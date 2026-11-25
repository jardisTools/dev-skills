---
name: generated-code-wire-transport
description: Wiring generated Jardis domains into HTTP, CLI, queue or worker code — bootstrap and kernel lifetime, call chains (read facade, process(), command doors; the write facade stays family-internal), DomainResponse-to-status mapping, error handling, X-Jardis-Version and meta.version. Use when writing transport code around a generated domain.
zone: post-active
persona: D
profile: jardis
prerequisites: [generated-code-extend]
next: []
---

This skill is an index; the detail lives in `reference/`, one leaf per topic. Read exactly the leaf whose question matches your task, not all of them.

### 1. Call chain

Two chains reach the transport layer — **read** and **process** — the BC facade's public surface exposes no general aggregate write accessor (a command door at stance `domain` or wider adds a narrow direct chain, the Rules-Layer exception below):

```
new MyApp($kernel)                ← final Domain facade, holds the DomainKernel (DomainKernelInterface), one per request/run
    ↓ $app->counter()             ← BC facade (the public surface)
    ↓ ->counter()                 ← Aggregate READ facade `{Agg}Read` (hermetic, reached via $bc->{agg}())
    ↓ ->getCounterByIdentifier($dto)  ← DomainResponseInterface
```

```
new MyApp($kernel)                ← final Domain facade, holds the DomainKernel (DomainKernelInterface), one per request/run
    ↓ $app->counter()             ← BC facade (the public surface)
    ↓ ->process()                 ← Process facade `{BC}Process` (hermetic, reached via $bc->process())
    ↓ ->createCounter($dto)       ← DomainResponseInterface
```

Three method hops on the `MyApp` instance: BC accessor → `{agg}()`/`process()` accessor → use-case method. **There is no general third chain for aggregate writes from the transport layer.** The aggregate write facade `{Agg}/{Agg}.php` is reachable only family-internally, via the kernel seam (`$this->handle({Agg}::class)`), from classes that extend the Domain Context (Process node bodies, Services — the Context family). A PSR-15 controller, CLI command, or queue consumer is **outside** that family and has no `handle()` of its own — it can only reach `$app->{bc}()->{agg}()` (read), `$app->{bc}()->process()->{process}()` (write) and, for a command door at stance `domain` or wider, `$app->{bc}()->{command}($dto)` (write, Rules-Layer exception below). To let the transport layer trigger a write, model a **Process** around the aggregate command (`generated-code-extend` §2/§4) or open the command's door — a bare aggregate command with no Process and no door at `domain` or wider is by design not externally callable.

**Rules-Layer exception.** A Command whose row in `Doors.json` stands at `domain` or wider (default `closed`; the Create command is the row `Create{Agg}`, method `create{Agg}()`; carrying ≥1 bound Rule is advised — without one the build warns `V-RULE-5`) gets a **fourth, narrower** call chain straight off the BC facade: `$app->{bc}()->{lcfirst(Command)}($dto)` — two hops, no `process()`, no aggregate accessor. It still runs the Command's Rule chain (Guard) unconditionally, from inside the same generated CommandHandler every other caller uses — the shortcut is only at the transport hop, not a bypass of the guard. This exists specifically for Commands with no orchestration need beyond their Rule chain; a Command with real multi-step behaviour still belongs behind a Process.

The aggregate READ facade `{BC}/Model/{Agg}/{Agg}Read.php` (not generated for an aggregate with no read door at `domain` or wider in `Doors.json` — it has no outer door, no route and no OpenAPI path) carries one public method per read door at `domain` or wider (mixed ById/ByIds/By{Key}/By{PluralKey}/list/declared queries — an aggregate WITH a unique key has `By{Key}`/`By{PluralKey}` and NO ById/ByIds (those stay internal), one without has ById/ByIds; `list` only where `Queries.json` declares one) — all reachable directly on it; it is hermetic (regenerated every build, no aggregate-root file in front of it). The Process facade `{BC}/Process/{BC}Process.php` carries one thin-dispatch method per process. Never instantiate handlers via `new` (V3: bypassing `handle()` — see `generated-code-extend` §3).

Matching BC/aggregate names (`MeterDevice\Counter\Counter`) → two `->counter()` hops for reads are correct (`$app->counter()->counter()->getCounterByIdentifier($dto)`); the BC facade internally aliases the read class `CounterAggregateRead` to avoid a name collision, the accessor name stays `counter()` either way. Non-matching (`Ecommerce\Sales\Order`) → `$app->sales()->order()->getOrderById($dto)`.

**BC-level processes** wire through: `$app->counter()->process()->{process}($dto)` → `DomainResponseInterface`. The `->process()` hop returns the hermetic `{BC}Process` facade; everything in §3–§5 below (response mapping, error handling) applies identically to both chains.

### 2. Bootstrap lifetime

The DomainKernel (`$kernel: DomainKernelInterface`) is a plain immutable value object built once by `BuildDomainKernelFromEnv`, typically invoked from the generated one-time `App/bootstrap.php` — there is no static `$sharedRegistry` and no `DomainApp`/`ServiceRegistry`. Bootstrap internals (what `BuildDomainKernelFromEnv` wires, ENV shape): see skill `core-kernel`. `MyApp` (and every BC facade) is a cheap, uncached `new` — safe to construct fresh on every access.

| Transport | DomainKernel (`$kernel`) lifetime | `MyApp` lifetime | Note |
|---|---|---|---|
| HTTP (PSR-15) | One per request (or DI singleton if the underlying adapters tolerate reuse) | One per request, cheap `new MyApp($kernel)` | No caching on any facade — safe to recreate |
| Long-running worker (RoadRunner, Swoole) | One per process (built once at boot) | One per message | The DomainKernel holds no mutable global/shared state — reusing it across messages is safe as long as the wrapped adapters (DB pool, cache, …) are themselves safe to share across messages |
| Queue consumer | Same as worker | One per message | Same |
| CLI / Cron | One per process | One per invocation | Trivial |

Tenancy still matters at the adapter level: build a fresh DomainKernel (fresh DB credentials/connection, fresh cache namespace, …) per tenant when tenant isolation is required — there is no kernel-level `$sharedRegistry` mechanism to leak through.

### Navigation

Navigation: one leaf per topic under `reference/` (§3, §4). Pick the row whose question fits the task.

| Question the leaf answers | Leaf |
| --- | --- |
| Which HTTP status and CLI exit code belongs to each DomainResponse status, and what does `data` carry? (§3) | reference/status-mapping.md |
| What does the envelope look like — JSON examples, time/decimal format, list ReadResult, reading back after a write? (§3) | reference/envelope-and-examples.md |
| Do I use `jardiscore/app` and the generated `routes.php`, and how does `X-Jardis-Version` reach a facade door? (§4) | reference/http-delivery-and-routes.md |
| How are the middleware pipeline, session and open routes, `RequireRight`, token model and the 401/403 path wired? (§4) | reference/middleware-and-session.md |
| How do group middleware, share/unshare routes and the access backfill work when records are protected? (§4) | reference/per-record-access-and-backfill.md |
| I hand-write an HTTP controller, a CLI command or a queue consumer — show me the shape. (§4) | reference/transport-examples.md |

### 4. Transport patterns

> **Where transport code stands.** A hand-written transport — controller, CLI command, queue consumer, event listener, project middleware — is delivery code in `App/**` (row 10 of the table in `generated-code-extend` §10). It carries no business logic, writes nothing itself, and calls only the doors of §1 — `{agg}()` (reads), `process()` and the direct command method `{BC}::{command}()` of a command door at stance `domain` or wider (HTTP route from `outside`) — and the kernel's accessors; never `Model/**`, a repository or `DbQuery`. The examples below show that shape.

### 5. Error handling

- 4xx business/validation errors → already in the response: the typed `data` (`validation` / `notFound` / `concurrencyConflict` / `ruleViolation`, §3; `data` = `@type` plus the form's fields, a 4xx without a form is passed through unchanged; the `messageKey` the node declares with `rejects(status, key)` is set on `data` after forming, for every 4xx status — also when it is not a field of the form) — that is where the cause of a 4xx lives; `DomainResponse::getErrors()` is filled reliably only for a 500 and may be `{}` on a 4xx (route-guard 400, node 404). Serialise both, do not rethrow, and never read the reason of a 4xx from `errors` alone; `errors` stays an object (`{}` when empty).
- Infrastructure exceptions → let them escape to framework middleware / CLI default handler. No bespoke envelope.
- Never `catch (\Throwable)` in the controller to build a custom error body.

### 6. Not in the transport layer

- Business validation → Command Actions
- DB transactions → Repository pipeline
- Event collection → command handler (`addEvent`); publish-after-commit → `App/**` delivery code for the Domain events an Event ◇ node announced (`generated-code-recipes` §1), no business logic
- Auth → the middleware pipeline (project setting `middleware`, default `session`; open routes via the stance `open` of the process row in `Doors.json`; the right per route by `RequireRight`), before the `$app->...` call
- `tenant` / `user` marker columns: the authenticating middleware stores subject, subject id, tenant and rights under `RequestAttribute::SUBJECT` / `::SUBJECT_ID` / `::TENANT` (`jardisTenant`) / `::RIGHTS` of `jardissupport/contracts` >= 2.17 before the generated `routes.php` runs; a route without the right is answered 403 `access.rightMissing` by `RequireRight`, one without the attribute 403 `request.attributeMissing` by `RequireRequestAttribute` (`jardiscore/app` >= 1.8) instead of reading or writing outside a scope
- Per-record access (protection is the rule in a world with login and groups; `access: "everyone"` releases): the group middleware above resolve the groups before the handler, the read door filters, the create door grants and the write door asks the level before it writes (a second 403 form, `forbidden`, besides the door refusal) — a transport never queries the entry table, never checks a level and never adds entries itself
- Field mapping → FieldMap + `Hydrate*` Action

### 7. Anchors

- Aggregate facade layout / V-rules / process modelling / where hand code may stand (`App/**` = row 10): `generated-code-extend` §1, §4, §5, §10
- Response shapes per use-case kind (Recipe 6 table): `generated-code-recipes`
- `DomainResponse` / `ContextResponse` / `TransformResponse` / `BuildResponseData` (how a Process answer becomes the envelope's `data`): package classes of `jardissupport/response` (skill `support-response`); `ResponseStatus` + response/context interfaces: `jardissupport/contracts`
- ENV-driven DomainKernel assembly + one-time App entry point (`App/bootstrap.php`): `core-kernel` (Bootstrap-Packer `BuildDomainKernelFromEnv`) — `jardiscore/foundation` does not exist; never reach for it
- HTTP delivery (routing, PSR-15 middleware, canonical envelope mapper `MapDomainResponse`): `core-app` (`jardiscore/app`) — optional, one of several valid transports (§4)

### Zitate §N (citation targets)

- §1 → „1. Call chain“ (this index)
- §2 → „2. Bootstrap lifetime“ (this index)
- §3 → „3. DomainResponse → transport“ (status-mapping, envelope-and-examples)
- §4 → „4. Transport patterns“ (this index; the four §4 leaves)
- §5 → „5. Error handling“ (this index)
- §6 → „6. Not in the transport layer“ (this index)
- §7 → „7. Anchors“ (this index)
