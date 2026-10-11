#!/usr/bin/env bash
# No existing Compose project, database, volume, or Redis is used. Everything
# written to dependencies is synthetic and removed by the exit trap.
set -euo pipefail

tool_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
repo_dir=$(cd -- "$tool_dir/../../../.." && pwd)
output_dir=${1:-"$repo_dir/docs/specs/006-postgresql-quiz-runtime/evidence/local-100k-200k"}
mkdir -p -- "$output_dir"
output_dir=$(cd -- "$output_dir" && pwd)
task_dir=$(mktemp -d /tmp/quickquiz-runtime-capacity.XXXXXX)
task_id="quickquiz-runtime-capacity-$$"
pg_name="$task_id-pg"
redis_name="$task_id-redis"
api_name="$task_id-api"
pg_id=""
redis_id=""
api_id=""

cleanup() {
  for owned_id in "$api_id" "$redis_id" "$pg_id"; do
    if [[ -n "$owned_id" ]]; then docker rm -f -v "$owned_id" >/dev/null 2>&1 || true; fi
  done
  rm -rf -- "$task_dir"
}
trap cleanup EXIT

# Pin all three workloads to the same two allowed CPUs. This constrains their
# combined execution width; it does not isolate them from host activity.
cpu_set=${RUNTIME_BENCHMARK_CPUSET:-0,1}
pg_image=${RUNTIME_BENCHMARK_POSTGRES_IMAGE:-postgres:17-alpine}
redis_image=${RUNTIME_BENCHMARK_REDIS_IMAGE:-redis:7.4-alpine}

(cd -- "$repo_dir/apps/api" && CGO_ENABLED=0 go build -trimpath -o "$task_dir/runtime-benchmark" ./cmd/runtime-benchmark)
go test "$repo_dir/apps/api/cmd/runtime-benchmark" >"$output_dir/tool-tests.txt"
sha256sum "$task_dir/runtime-benchmark" | awk '{print $1}' >"$output_dir/binary-sha256.txt"
go version -m "$task_dir/runtime-benchmark" >"$output_dir/binary-build-info.txt"

pg_id=$(docker run -d --name "$pg_name" --label quickquiz.runtime-benchmark=synthetic --network bridge --cpuset-cpus "$cpu_set" --memory 1408m --memory-swap 1408m \
  -e POSTGRES_DB=quickquiz_runtime_benchmark_test -e POSTGRES_PASSWORD=synthetic-benchmark-only \
  -p 127.0.0.1::5432 "$pg_image" postgres -c shared_buffers=128MB -c max_connections=30)
redis_id=$(docker run -d --name "$redis_name" --label quickquiz.runtime-benchmark=synthetic --network bridge --cpuset-cpus "$cpu_set" --memory 256m --memory-swap 256m \
  "$redis_image" redis-server --save '' --appendonly no --maxmemory 220mb --maxmemory-policy noeviction)

pg_port=$(docker inspect -f '{{(index (index .NetworkSettings.Ports "5432/tcp") 0).HostPort}}' "$pg_id")
pg_ip=$(docker inspect -f '{{(index .NetworkSettings.Networks "bridge").IPAddress}}' "$pg_id")
redis_ip=$(docker inspect -f '{{(index .NetworkSettings.Networks "bridge").IPAddress}}' "$redis_id")
for attempt in $(seq 1 90); do
  if docker exec "$pg_id" pg_isready -U postgres -d quickquiz_runtime_benchmark_test >/dev/null 2>&1; then break; fi
  if [[ "$attempt" = 90 ]]; then printf 'PostgreSQL fixture did not become ready\n' >&2; exit 1; fi
  sleep 1
done

export RUNTIME_BENCHMARK_DATABASE_URL="postgres://postgres:synthetic-benchmark-only@127.0.0.1:$pg_port/quickquiz_runtime_benchmark_test?sslmode=disable"
for count in 100000 200000; do
  "$task_dir/runtime-benchmark" --mode seed --apply-fixture --questions "$count" --migrations "$repo_dir/apps/manager/migrations" --output "$output_dir/seed-$count.json" \
    --profile 'isolated synthetic seed; no API memory measurements in seed process'
  # A fresh API process per bank size prevents seed allocations and previous
  # benchmark heaps from contaminating the memory comparison.
  api_id=$(docker create --name "$api_name" --user "$(id -u):$(id -g)" --label quickquiz.runtime-benchmark=synthetic --network bridge --cpuset-cpus "$cpu_set" --memory 384m --memory-swap 384m \
    -v "$task_dir/runtime-benchmark:/runtime-benchmark:ro" -v "$output_dir:/evidence" \
    -e "RUNTIME_BENCHMARK_DATABASE_URL=postgres://postgres:synthetic-benchmark-only@$pg_ip:5432/quickquiz_runtime_benchmark_test?sslmode=disable" \
    -e "RUNTIME_BENCHMARK_REDIS_ADDR=$redis_ip:6379" --entrypoint /runtime-benchmark "$redis_image" \
    --mode measure --disposable-redis --questions "$count" --operations 1000 --warmup 100 --clients 10 \
    --profile 'shared host; all three containers pinned to same two CPUs; memory caps total 2GiB (API384+PG1408+Redis256MiB)' \
    --output "/evidence/measure-$count.json")
  docker start -a "$api_id"
  api_exit=$(docker inspect -f '{{.State.ExitCode}}' "$api_id")
  docker inspect --format '{"name":{{json .Name}},"image_id":{{json .Image}},"exit_code":{{.State.ExitCode}},"oom_killed":{{.State.OOMKilled}},"memory_limit_bytes":{{.HostConfig.Memory}},"cpuset":{{json .HostConfig.CpusetCpus}}}' "$api_id" >"$output_dir/api-container-$count.json"
  docker stats --no-stream --format '{{json .}}' "$pg_id" "$redis_id" >"$output_dir/dependency-stats-$count.jsonl"
  docker rm -v "$api_id" >/dev/null
  api_id=""
  if [[ "$api_exit" != 0 ]]; then printf 'Measurement failed; safe receipt retained\n' >&2; exit 1; fi
done

# Only whitelisted inspect fields enter the evidence; never full container env.
docker inspect --format '{"name":{{json .Name}},"image_id":{{json .Image}},"oom_killed":{{.State.OOMKilled}},"restart_count":{{.RestartCount}},"memory_limit_bytes":{{.HostConfig.Memory}},"cpuset":{{json .HostConfig.CpusetCpus}}}' "$pg_id" "$redis_id" >"$output_dir/dependency-containers.jsonl"
export RUNTIME_BENCHMARK_EVIDENCE_DIR="$output_dir"
python3 -B "$tool_dir/summarize.py"
printf 'Synthetic benchmark complete; evidence=%s\n' "$output_dir"
