#!/usr/bin/env python3
"""Combine safe benchmark receipts. No database, Redis, secret, or network access."""
import json
import os
import pathlib
import platform
import subprocess

root = pathlib.Path(os.environ["RUNTIME_BENCHMARK_EVIDENCE_DIR"])

def load(name):
    return json.loads((root / name).read_text())

def write(name, data):
    path = root / name
    path.write_text(json.dumps(data, indent=2) + "\n")
    path.chmod(0o600)

memory = {}
for line in pathlib.Path("/proc/meminfo").read_text().splitlines():
    key, value = line.split(":", 1)
    if key in ("MemTotal", "MemAvailable", "SwapTotal", "SwapFree"):
        memory[key + "_bytes"] = int(value.split()[0]) * 1024

environment = {
    "host": {"architecture": platform.machine(), "kernel": platform.release(), "logical_cpus": os.cpu_count(), "memory": memory},
    "docker_server_version": subprocess.check_output(["docker", "version", "--format", "{{.Server.Version}}"], text=True).strip(),
    "images": {},
    "resource_model": "API, PostgreSQL, Redis share cpuset 0,1 by default; caps sum to 2GiB; host and I/O are shared, and Manager/ads/proxy/SPAs are excluded.",
    "dedicated_target_node": False,
}
for variable, default in (("RUNTIME_BENCHMARK_POSTGRES_IMAGE", "postgres:17-alpine"), ("RUNTIME_BENCHMARK_REDIS_IMAGE", "redis:7.4-alpine")):
    image = os.environ.get(variable, default)
    environment["images"][image] = json.loads(subprocess.check_output(["docker", "image", "inspect", "--format", '{"id":{{json .Id}},"repo_digests":{{json .RepoDigests}},"architecture":{{json .Architecture}}}', image], text=True))
write("environment.json", environment)

base, doubled = load("measure-100000.json"), load("measure-200000.json")
a, b = base["data"], doubled["data"]
rss_a = max(x["rss_p95_bytes"] for x in a["phases"])
rss_b = max(x["rss_p95_bytes"] for x in b["phases"])
comparison = {
    "baseline_canonical": a["fixture"]["canonical_questions"], "doubled_canonical": b["fixture"]["canonical_questions"],
    "hot_scope_unchanged": a["fixture"]["hot_scope_questions"] == b["fixture"]["hot_scope_questions"] == 10000,
    "rss_comparison_method": "maximum phase p95 RSS, independently started API processes, same endpoint schedule",
    "baseline_steady_rss_bytes": rss_a, "doubled_steady_rss_bytes": rss_b, "steady_rss_delta_bytes": rss_b - rss_a,
    "observed_delta_under_16mib": rss_b - rss_a <= 16 * 1024 * 1024,
    "total_measured_http_operations": sum(x["operations"] for x in a["phases"] + b["phases"]),
    "total_errors": sum(sum(x["errors"].values()) for x in a["phases"] + b["phases"]),
    "captured_state_and_answer_sql_queries": {str(count): {name: sum(x["sql_queries"] for x in report["phases"] if x["endpoint"] == name) for name in ("state", "answer")} for count, report in ((100000, a), (200000, b))},
    "bounded_hydration": {"100000": a["bounded_hydration"], "200000": b["bounded_hydration"]},
    "dedicated_target_node_approval": False,
    "one_million_experiment": "not run; no claim for current production node",
}
write("comparison.json", comparison)

lines = ["# Synthetic runtime capacity evidence", "", "These are local synthetic measurements, not approval of a dedicated production node. All dependencies were disposable. No real question bank, project database, or project Redis was used.", "", "The three workloads shared two pinned CPUs. Per-container caps totaled 2 GiB (API 384 MiB, PostgreSQL 1,408 MiB, Redis 256 MiB). The host had other workloads; its kernel, I/O, and caches were shared. Manager, ads, SPAs and reverse proxy were excluded. The real Go router, domain services, PostgreSQL view-only reader and Redis run store were used through `httptest`; the harness shares API RSS/CPU and network ingress is excluded.", "", "Fixture: 200 themes, 2,000 topics, 100,000 then 200,000 canonical IDs; two locales; one fixed scope has 10,000 questions. Prompts are 256 bytes, each of seven alternatives 96 bytes. The added 100,000 questions are outside the measured theme. Each endpoint/run-limit pair has 100 warm-up operations and 1,000 measured operations with ten concurrent clients.", "", "| Canonical | Limit | Endpoint | p50 ms | p95 ms | Max ms | Errors | SQL/op | RSS p95 MiB |", "|---:|---:|---|---:|---:|---:|---:|---:|---:|"]
for count, report in ((100000, a), (200000, b)):
    for phase in report["phases"]:
        lines.append(f"| {count:,} | {phase['run_question_limit']} | {phase['endpoint']} | {phase['p50_ms']:.3f} | {phase['p95_ms']:.3f} | {phase['max_ms']:.3f} | {sum(phase['errors'].values())} | {phase['sql_queries_per_operation']:.1f} | {phase['rss_p95_bytes']/1048576:.2f} |")
lines += ["", f"Observed startup to ready: {a['startup_to_ready_ms']:.3f} ms / {b['startup_to_ready_ms']:.3f} ms. This starts at runtime-store construction and excludes program/config startup. Readiness uses view/state checks, not a full bank read.", "", f"Maximum phase p95 RSS: {rss_a/1048576:.2f} MiB / {rss_b/1048576:.2f} MiB; delta {(rss_b-rss_a)/1048576:.2f} MiB. Captured state/answer executed {sum(comparison['captured_state_and_answer_sql_queries'][str(n)][k] for n in (100000,200000) for k in ('state','answer'))} SQL queries in all measured phases. Errors: {comparison['total_errors']}. Operations: {comparison['total_measured_http_operations']:,}.", "", "Each JSON receipt records pool acquisition/empty-acquisition/cancellation counters, Redis command deltas, response sizes, bounded snapshot sizes, process RSS/HWM and actual `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` candidate/count/hydration plans. SQL counts include begin/commit. Redis INFO monitoring is excluded from command totals; Lua subcommands remain included. API sessions were reset through their own session IDs; dependency containers and volumes were removed on exit.", "", "The one-million-question experiment was not run. A dedicated 2 vCPU / 2 GiB same-node release run including all deployed services remains necessary before treating the proposed budgets as production acceptance. These fixed payloads do not establish capacity for arbitrary prompt sizes, large consumed-ID histories, AI calls or production traffic.", "", "Reproduce from the repository: `bash apps/api/cmd/runtime-benchmark/run-disposable.sh <new-evidence-directory>`. Docker, Go and Python 3 are required; it never reads a project `.env`. CPU pins and dependency images can be overridden via `RUNTIME_BENCHMARK_CPUSET`, `RUNTIME_BENCHMARK_POSTGRES_IMAGE`, and `RUNTIME_BENCHMARK_REDIS_IMAGE`. Keep the JSON receipts with the report.", ""]
report_path = root / "README.md"
report_path.write_text("\n".join(lines))
report_path.chmod(0o600)
