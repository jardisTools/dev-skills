### 8. Toolbox — which skill covers which Jardis package

When a Process node needs a Jardis runtime tool (cache, mail, queue …), consult the package skill below before re-deriving the API. All packages are constructor-injected via `handle(<Service>::class, …)` from inside the node — never `new` (V2 / V3). Three rows — DB connection, DB query builder, Repository — name packages that the generated code and the packages use; hand code never uses them: reading is a declared Query, writing is an aggregate Command or a `process()` call (§10).

| Need | Skill |
|---|---|
| Cache (PSR-16, multi-layer, write-through) | `adapter-cache` |
| Logger (PSR-3, fluent builder, enrichers) | `adapter-logger` |
| Mail (SMTP, STARTTLS, attachments, retry) | `adapter-mailer` |
| HTTP client (PSR-18, cURL transport, retry) | `adapter-http` |
| Filesystem (local + S3, streams) | `adapter-filesystem` |
| Messaging (Kafka / Redis / RabbitMQ / DB / InMemory) | `adapter-messaging` |
| Event dispatcher (PSR-14, priorities, type-hierarchy) | `adapter-eventdispatcher` |
| DB connection (pool, read/write split, MySQL/Postgres/SQLite) — generated code and packages only, never hand code | `adapter-dbconnection` |
| DB query builder (fluent SELECT/INSERT/UPDATE/DELETE, CTE, window) — generated code and packages only, never hand code | `support-dbquery` |
| Repository (generic CRUD on top of dbquery) — generated code and packages only, never hand code | `support-repository` |
| Validation (composite, fluent, object graph) | `support-validation` |
| Workflow engine (multi-step orchestration) | `support-workflow` (and `generated-code-workflow-api` for the Process Designer-Orchestrator API) |
| Auth (session, password hash, RBAC) | `support-auth` |
| Scheduling / cron parsing | `support-scheduling` |
| Secret resolution (encrypted `.env` values) | `support-secret` |
| Dotenv loader (cascade, type casting) | `support-dotenv` |
| Hydration, identity, FieldMapper, UUID/NanoID | `support-data` |
| ClassVersion loader / namespace injection | `support-classversion` (Designer-side: see `generated-code-versioning`) |
| Factory / container | `support-factory` |
| `DomainKernelInterface` (the DomainKernel) + Bootstrap-Packer `BuildDomainKernelFromEnv` (ENV-driven DomainKernel assembly) | `core-kernel` — `DomainApp`/`BoundedContext`/`ServiceRegistry` do not exist; their role is carried by the generated Domain facade + `<Domain>Context` (this skill, §1) |
| `DomainResponse`/`ContextResponse`/`TransformResponse`/`BuildResponseData` | `support-response` (package `jardissupport/response`, §7 above) |
| One-time ENV-driven App entry point (`App/bootstrap.php` + `App/.env`, wires the DomainKernel + every workspace domain) | `core-kernel` (Bootstrap-Packer) — `jardiscore/foundation` does not exist; never reach for it |

### 9. Anchors

- `foundation-architecture` (pillar 4 Data-Behavior-Separation drives the service-layer note in `generated-code-versioning` §2), `foundation-patterns`, `foundation-testing`
- `generated-code-versioning` (ClassVersion resolution + versioning model — `LoadClassFromSubDirectory`, `v{N}/` per-class)
- `generated-code-workflow-api` (Workflow-Engine API used by Process Designer orchestrators)
- `generated-code-recipes` (Phase-3 recipes, event transport, troubleshooting)
- `DomainResponse`, `ContextResponse`, `TransformResponse`, `BuildResponseData`: package classes of `jardissupport/response` (§7 above); `ResponseStatus` + the response/context interfaces: `jardissupport/contracts`
- `MessagingService`, `MessagePublisher`: `adapter-messaging`
- `EventListenerRegistryInterface`, `EventDispatcher`, `EventCollector`: `adapter-eventdispatcher`
- `HttpClientInterface`, `HttpClient`: `adapter-http`

