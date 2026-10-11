# Synthetic runtime capacity evidence

These are local synthetic measurements, not approval of a dedicated production node. All dependencies were disposable. No real question bank, project database, or project Redis was used.

The three workloads shared two pinned CPUs. Per-container caps totaled 2 GiB (API 384 MiB, PostgreSQL 1,408 MiB, Redis 256 MiB). The host had other workloads; its kernel, I/O, and caches were shared. Manager, ads, SPAs and reverse proxy were excluded. The real Go router, domain services, PostgreSQL view-only reader and Redis run store were used through `httptest`; the harness shares API RSS/CPU and network ingress is excluded.

Fixture: 200 themes, 2,000 topics, 100,000 then 200,000 canonical IDs; two locales; one fixed scope has 10,000 questions. Prompts are 256 bytes, each of seven alternatives 96 bytes. The added 100,000 questions are outside the measured theme. Each endpoint/run-limit pair has 100 warm-up operations and 1,000 measured operations with ten concurrent clients.

| Canonical | Limit | Endpoint | p50 ms | p95 ms | Max ms | Errors | SQL/op | RSS p95 MiB |
|---:|---:|---|---:|---:|---:|---:|---:|---:|
| 100,000 | 5 | discovery | 19.945 | 27.012 | 32.799 | 0 | 4.0 | 17.34 |
| 100,000 | 5 | catalog | 31.764 | 40.010 | 47.230 | 0 | 6.0 | 17.93 |
| 100,000 | 5 | availability | 31.688 | 37.838 | 46.980 | 0 | 6.0 | 18.02 |
| 100,000 | 5 | create | 44.730 | 54.895 | 73.143 | 0 | 6.0 | 18.69 |
| 100,000 | 5 | state | 0.793 | 2.476 | 4.350 | 0 | 0.0 | 19.44 |
| 100,000 | 5 | answer | 2.336 | 4.095 | 6.135 | 0 | 0.0 | 21.97 |
| 100,000 | 10 | discovery | 19.468 | 25.874 | 33.488 | 0 | 4.0 | 20.05 |
| 100,000 | 10 | catalog | 31.130 | 38.876 | 52.428 | 0 | 6.0 | 18.78 |
| 100,000 | 10 | availability | 32.417 | 39.294 | 48.453 | 0 | 6.0 | 18.84 |
| 100,000 | 10 | create | 66.316 | 82.419 | 108.181 | 0 | 6.0 | 19.96 |
| 100,000 | 10 | state | 0.478 | 4.596 | 9.380 | 0 | 0.0 | 20.91 |
| 100,000 | 10 | answer | 2.979 | 5.576 | 8.965 | 0 | 0.0 | 23.54 |
| 200,000 | 5 | discovery | 19.716 | 25.559 | 32.897 | 0 | 4.0 | 17.25 |
| 200,000 | 5 | catalog | 43.091 | 52.548 | 61.892 | 0 | 6.0 | 17.93 |
| 200,000 | 5 | availability | 43.586 | 51.639 | 66.416 | 0 | 6.0 | 17.69 |
| 200,000 | 5 | create | 45.562 | 54.962 | 62.970 | 0 | 6.0 | 18.60 |
| 200,000 | 5 | state | 0.724 | 2.574 | 4.572 | 0 | 0.0 | 19.23 |
| 200,000 | 5 | answer | 2.215 | 4.116 | 7.766 | 0 | 0.0 | 22.36 |
| 200,000 | 10 | discovery | 20.559 | 27.815 | 39.586 | 0 | 4.0 | 19.42 |
| 200,000 | 10 | catalog | 44.263 | 52.718 | 63.900 | 0 | 6.0 | 18.61 |
| 200,000 | 10 | availability | 44.545 | 52.566 | 66.220 | 0 | 6.0 | 18.80 |
| 200,000 | 10 | create | 67.009 | 81.213 | 100.857 | 0 | 6.0 | 19.59 |
| 200,000 | 10 | state | 0.366 | 4.705 | 8.198 | 0 | 0.0 | 20.30 |
| 200,000 | 10 | answer | 3.148 | 5.336 | 8.594 | 0 | 0.0 | 23.58 |

Observed startup to ready: 22.889 ms / 37.616 ms. This starts at runtime-store construction and excludes program/config startup. Readiness uses view/state checks, not a full bank read.

Maximum phase p95 RSS: 23.54 MiB / 23.58 MiB; delta 0.04 MiB. Captured state/answer executed 0 SQL queries in all measured phases. Errors: 0. Operations: 24,000.

Each JSON receipt records pool acquisition/empty-acquisition/cancellation counters, Redis command deltas, response sizes, bounded snapshot sizes, process RSS/HWM and actual `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` candidate/count/hydration plans. SQL counts include begin/commit. Redis INFO monitoring is excluded from command totals; Lua subcommands remain included. API sessions were reset through their own session IDs; dependency containers and volumes were removed on exit.

The one-million-question experiment was not run. A dedicated 2 vCPU / 2 GiB same-node release run including all deployed services remains necessary before treating the proposed budgets as production acceptance. These fixed payloads do not establish capacity for arbitrary prompt sizes, large consumed-ID histories, AI calls or production traffic.

Reproduce from the repository: `bash apps/api/cmd/runtime-benchmark/run-disposable.sh <new-evidence-directory>`. Docker, Go and Python 3 are required; it never reads a project `.env`. CPU pins and dependency images can be overridden via `RUNTIME_BENCHMARK_CPUSET`, `RUNTIME_BENCHMARK_POSTGRES_IMAGE`, and `RUNTIME_BENCHMARK_REDIS_IMAGE`. Keep the JSON receipts with the report.
