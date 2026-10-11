# PostgreSQL runtime synthetic benchmark

Run from the repository root:

```sh
bash apps/api/cmd/runtime-benchmark/run-disposable.sh /tmp/quickquiz-runtime-evidence
```

Requires Docker, Go and Python 3. Uses the existing local `postgres:17-alpine`
and `redis:7.4-alpine` images. It builds a separate Go executable, creates only
its own containers on Docker's default bridge, and removes those containers and
their anonymous volumes on exit. It never reads a project `.env`, a question
bank, a named project volume, or an existing Compose stack.

The default workload is 200 themes, 2,000 topics, 100,000 canonical questions,
two locales and seven alternatives per translation. One topic/difficulty has
10,000 questions. The second fixture adds 100,000 questions outside the entire
measured theme. Each fresh measurement process exercises the real HTTP router,
application services, view-only PostgreSQL reader and Redis run store. Every
discovery/catalog/availability/create/state/answer endpoint has 100 warm-up and
1,000 measured operations, ten concurrent clients, and run limits 5 and 10.
There is no AI call or ingress network hop. Response validation happens outside
the request timer but shares process CPU/RSS with the load generator.

All workloads share CPUs `0,1`, configurable with `RUNTIME_BENCHMARK_CPUSET`.
Their memory caps total 2 GiB: API harness 384 MiB, PostgreSQL 1,408 MiB, Redis
256 MiB, without swap. This is a local approximation: host I/O/caches and other
workloads remain shared, and Manager/ads/proxy/SPAs are excluded. Results must
not be treated as dedicated production-node acceptance. Images can be changed
through `RUNTIME_BENCHMARK_POSTGRES_IMAGE` and
`RUNTIME_BENCHMARK_REDIS_IMAGE`; image IDs/digests go into safe evidence.

The receipt includes latency p50/p95/max, errors, RSS samples/process HWM,
SQL query counts, pool acquisition/cancellation/empty-acquisition counters,
Redis command deltas, response/snapshot sizes, and actual SQL execution plans.
Percentiles use the lower empirical order statistic at `floor((N-1)*q)`; the
1,000-request p95 is the 950th sorted request. RSS is sampled every 20 ms.
Setup, pre-created runs, owned-session cleanup, plan collection and the
administrator's fixture count verification are outside measured query/latency
windows. Readiness timing starts at runtime-store initialization with healthy,
already seeded dependencies; it excludes executable/config startup. Production
request log I/O is disabled for measurement. Redis INFO observation commands are
excluded; Lua subcommands remain counted by Redis.

For custom experiments, build `./cmd/runtime-benchmark` within `apps/api` and
provide `RUNTIME_BENCHMARK_DATABASE_URL` as a PostgreSQL URL. The database name
must be exactly `quickquiz_runtime_benchmark_test`. `--mode seed` also requires
`--apply-fixture`, refuses a nonempty database without its synthetic marker and
extends rather than deletes a recognized fixture. `--migrations` points to the
versioned Manager SQL migrations. `--questions`, `--prompt-bytes` and
`--answer-bytes` control the fixture. A one-million-question run is optional and
must be reported separately, with its own actual resource conditions.

`--mode measure` requires `--disposable-redis`, an explicit
`RUNTIME_BENCHMARK_REDIS_ADDR`, and isolated Redis database 15. It refuses a
nonempty Redis database without its benchmark guard, never flushes Redis, and
resets only sessions it creates. A normal project database or default Redis DB
is rejected before writes. `--operations`, `--warmup`, and `--clients` configure
measurement counts. Safe JSON receipts are mode 0600; URLs, passwords, question
texts and answers are never included in output.

Automated safety/concurrency checks:

```sh
cd apps/api
go test -race ./cmd/runtime-benchmark
```
