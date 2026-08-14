---
name: platform-usage
description: Wiring Designer-generated Queries/Processes into a transport layer — bootstrap lifetime, call chain (Domain → BC → Aggregate READ facade → query, plus Domain → BC → Process facade → process for writes; the aggregate WRITE facade is family-internal only and not reachable from transport code), DomainResponse→transport mapping, error handling for HTTP / CLI / queue / worker.
zone: post-active
persona: D
prerequisites: [platform-implementation]
next: []
---

### 1. Call chain

Two chains reach the transport layer — **read** and **process** — the BC facade's Außentür (G2) exposes no aggregate writes:

```
new MyApp($kernel)                ← final Domain facade, holds the Koffer (DomainKernelInterface), one per request/run
    ↓ $app->counter()             ← BC facade (the Außentür)
    ↓ ->counter()                 ← Aggregate READ facade `{Agg}Read` (hermetic, reached via $bc->{agg}())
    ↓ ->getCounterById($dto)      ← DomainResponseInterface
```

```
new MyApp($kernel)                ← final Domain facade, holds the Koffer (DomainKernelInterface), one per request/run
    ↓ $app->counter()             ← BC facade (the Außentür)
    ↓ ->process()                 ← Process facade `{BC}Process` (hermetic, reached via $bc->process())
    ↓ ->createCounter($dto)       ← DomainResponseInterface
```

Three method hops on the `MyApp` instance: BC accessor → `{agg}()`/`process()` accessor → use-case method. **There is no general third chain for aggregate writes from the transport layer.** The aggregate write facade `{Agg}/{Agg}.php` is reachable only family-internally, via the Kernel-Naht (`$this->handle({Agg}::class)`), from classes that extend the Domain Context (Process node bodies, Services — the Context-Familie). A PSR-15 controller, CLI command, or queue consumer is **outside** that family and has no `handle()` of its own — it can only reach `$app->{bc}()->{agg}()` (read) and `$app->{bc}()->process()->{process}()` (write). To let the transport layer trigger a write, model a **Process** around the aggregate command (`platform-implementation` §2/§4) — a bare aggregate command with no Process is by design not externally callable (G6).

**Rules-Layer exception (G10).** A Command explicitly marked `expose: true` in `Rules.yaml` (and therefore carrying ≥1 bound Rule — enforced at build time) gets a **fourth, narrower** call chain straight off the BC facade: `$app->{bc}()->{lcfirst(Command)}($dto)` — two hops, no `process()`, no aggregate accessor. It still runs the Command's Rule chain (Guard) unconditionally, from inside the same generated CommandHandler every other caller uses — the shortcut is only at the transport hop, not a bypass of the guard. This exists specifically for Commands with no orchestration need beyond their Rule chain; a Command with real multi-step behaviour still belongs behind a Process.

The aggregate READ facade `{BC}/Aggregate/{Agg}/{Agg}Read.php` carries one public method per generated query/list (mixed ById/ByIds/By{Key}/list) — all reachable directly on it; it is hermetic (regenerated every build, no aggregate-root file in front of it). The Process facade `{BC}/Process/{BC}Process.php` carries one thin-dispatch method per process. Never instantiate handlers via `new` (V3: bypassing `handle()` — see `platform-implementation` §3).

Matching BC/aggregate names (`MeterDevice\Counter\Counter`) → two `->counter()` hops for reads are correct (`$app->counter()->counter()->getCounterById($dto)`); the BC facade internally aliases the read class `CounterAggregateRead` to avoid a name collision, the accessor name stays `counter()` either way. Non-matching (`Ecommerce\Sales\Order`) → `$app->sales()->order()->getOrderById($dto)`.

**BC-level processes** wire through: `$app->counter()->process()->{process}($dto)` → `DomainResponseInterface`. The `->process()` hop returns the hermetic `{BC}Process` facade; everything in §3–§5 below (response mapping, error handling) applies identically to both chains.

### 2. Bootstrap lifetime

The Koffer (`$kernel: DomainKernelInterface`) is a plain immutable value object built once by `BuildDomainKernelFromEnv`, typically invoked from the generated one-time `App/bootstrap.php` — there is no static `$sharedRegistry` and no `DomainApp`/`ServiceRegistry`. Bootstrap internals (what `BuildDomainKernelFromEnv` wires, ENV shape): s. Skill `core-kernel`. `MyApp` (and every BC facade) is a cheap, uncached `new` — safe to construct fresh on every access.

| Transport | Koffer (`$kernel`) lifetime | `MyApp` lifetime | Note |
|---|---|---|---|
| HTTP (PSR-15) | One per request (or DI singleton if the underlying adapters tolerate reuse) | One per request, cheap `new MyApp($kernel)` | No caching on any facade — safe to recreate |
| Long-running worker (RoadRunner, Swoole) | One per process (built once at boot) | One per message | The Koffer holds no mutable global/shared state — reusing it across messages is safe as long as the wrapped adapters (DB pool, cache, …) are themselves safe to share across messages |
| Queue consumer | Same as worker | One per message | Same |
| CLI / Cron | One per process | One per invocation | Trivial |

Tenancy still matters at the adapter level: build a fresh Koffer (fresh DB credentials/connection, fresh cache namespace, …) per tenant when tenant isolation is required — there is no kernel-level `$sharedRegistry` mechanism to leak through.

### 3. DomainResponse → transport

| Status | HTTP | CLI exit | Meaning |
|---|---|---|---|
| 200 | 200 OK | 0 | Success with data |
| 201 | 201 Created | 0 | Resource created |
| 204 | 204 No Content | 0 | Success, empty body |
| 400 | 400 Bad Request | 2 | Field/DTO validation failed (before any Rule runs) |
| 401 | 401 Unauthorized | 2 | Auth missing |
| 403 | 403 Forbidden | 2 | Auth insufficient |
| 404 | 404 Not Found | 2 | Target absent |
| 409 | 409 Conflict | 2 | State conflict |
| 422 | 422 Unprocessable Entity | 2 | Rules-Layer: a bound business Rule rejected the Command — payload carries `{rule, messageKey, context}` under `data` (requires `jardiscore/kernel` ≥ 1.1.0); map `messageKey` to a localized message in this transport layer, never in the domain |
| 500 | 500 Internal | 1 | Exception escaped the pipeline (incl. a technical failure inside a Rule's bestand-check — never a 422) |

Envelope from `getStatus()` / `getData()` / `getErrors()` / `getMetadata()` (plus `isSuccess()` shortcut). Per X-1 the generator emits a minimal payload — **Command** echoes only the affected identifier, **Query** returns the projected scalar tree under the aggregate root key; which fields the generator derives per use-case: [[beschreibt-bauweise-von::builder-generat-bauweise]] §5.2 (see also `platform-cookbook` for the full response-shape table). Examples:

```json
// Command (e.g. CreateCounter) → 201
{
  "status": 201,
  "data":   { "identifier": "018e..." },
  "errors": [],
  "meta":   { "duration_ms": 12, "version": "v1.0" }
}

// Query (e.g. getCounterById / getCounterByIdentifier) → 200
{
  "status": 200,
  "data":   { "counter": { "id": 1, "identifier": "018e...", "counterNumber": "M-1", "activeFrom": "2026-01-01" } },
  "errors": [],
  "meta":   { "duration_ms": 8, "version": "v1.0" }
}
```

CQRS: the Command response carries only identity — to get full state after a write, issue the matching read-base query (`get<Agg>By<UniqueKey>` with the echoed business key, or `get<Agg>ById`) via `$app->{bc}()->{agg}()`. Every aggregate's read facade (`{Agg}Read`) carries the uniform read base `get<Agg>ById` / `get<Agg>ByIds` / `get<Agg>By<UniqueKey>` plus `<agg>List` — there is no suffix-less `get<Agg>` (catalog + bulk-read recipe: `platform-implementation` §1). The projected Akte and the list items lead with the root `id` — the internal handle of the domain API; whether ids are exposed outward is this transport layer's decision. Events (`getEvents()`) are collected on the response, not dispatched by the handler; publication after commit is the caller's job (a Process node — see `platform-cookbook` §1). Include them in the transport response only for debug / fire-hose APIs.

### 4. Transport patterns

> **Process input DTO name:** the examples below assume a Process `CreateCounter` was modelled around the `Counter` aggregate's `createCounter` command (`platform-implementation` §2/§4) — its input DTO lives at `{BC}/Process/CreateCounter/Command/CreateCounter.php` (namespace `…\Process\CreateCounter\Command`), distinct from the aggregate's own Command DTO of the same simple name. Constructor fields like `name`/`obis` are **illustrative**, domain-specific to this example — not a fixed API; the real fields come from your process's own `input.fields`.

**Optional ready-made HTTP delivery (`jardiscore/app`).** This skill stays transport-agnostic by design (§1/§6) — the hand-rolled `JsonResponse` examples below work for any PSR-15 framework and remain valid. For HTTP specifically, `jardiscore/app` is a ready-made alternative to hand-rolling this wiring yourself: routing, a PSR-15 middleware pipeline, and the canonical `DomainResponseInterface` → PSR-7 `{status,data,errors,meta}` envelope mapper. Pipeline internals (router/middleware wiring, the mapper's class): s. Skill `core-app`. What the transport author must know regardless of internals: the mapper covers every one of the 11 `ResponseStatus` cases, with two documented deviations — `204` returns a bare empty response (no body, no `Content-Type`), and `422` passes `getData()` through unreshaped. It is optional, not a requirement: CLI, queue, and worker transports (below) have no equivalent package and stay hand-rolled either way.

**HTTP (PSR-15) — write via process():**

```php
final class CreateCounterController implements RequestHandlerInterface
{
    public function __construct(private readonly MyApp $app) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body     = (array) json_decode((string) $request->getBody(), true);
        $dto      = new CreateCounter(name: $body['name'], obis: $body['obis']);
        $response = $this->app->counter()->process()->createCounter($dto);   // write — the aggregate write facade is not reachable from here (G2)

        // Envelope is your app's mapper — build it inline from the DomainResponse getters:
        return (new JsonResponse([
            'data'   => $response->getData(),
            'errors' => $response->getErrors(),
            'status' => $response->getStatus(),
        ]))->withStatus($response->getStatus());
    }
}
```

**HTTP (PSR-15) — read via the aggregate's read facade:**

```php
final class GetCounterController implements RequestHandlerInterface
{
    public function __construct(private readonly MyApp $app) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id       = (int) $request->getAttribute('id');
        $response = $this->app->counter()->counter()->getCounterById(new QueryCounterById(id: $id));

        return (new JsonResponse([
            'data'   => $response->getData(),
            'errors' => $response->getErrors(),
            'status' => $response->getStatus(),
        ]))->withStatus($response->getStatus());
    }
}
```

**CLI (Symfony Console):**

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $dto      = new CreateCounter(name: $input->getArgument('name'));
    $response = $this->app->counter()->process()->createCounter($dto);   // write via process()
    $output->writeln(json_encode($response->getData(), JSON_PRETTY_PRINT));
    return match (true) {
        $response->isSuccess()        => 0,
        $response->getStatus() >= 500 => 1,
        default                       => 2,
    };
}
```

**Queue / Worker:**

```php
public function __invoke(CreateCounterMessage $msg): void
{
    $response = $this->app->counter()->process()->createCounter($msg->toDto());   // write via process()
    if ($response->getStatus() >= 500) throw new RecoverableException('infra error, retry');
    if (!$response->isSuccess())      throw new UnrecoverableException($response->getErrors());
}
```

### 5. Error handling

- 4xx business/validation errors → already in `DomainResponse::getErrors()`. Serialise, do not rethrow.
- Infrastructure exceptions → let them escape to framework middleware / CLI default handler. No bespoke envelope.
- Never `catch (\Throwable)` in the controller to build a custom error body.

### 6. Not in the transport layer

- Business validation → Command Actions
- DB transactions → Repository pipeline
- Event collection → command handler (`addEvent`); publish-after-commit → Process node, not ad-hoc here
- Auth → framework middleware, before the `$app->...` call
- Field mapping → FieldMap + `Hydrate*` Action

### 7. Anchors

- Aggregate facade layout / V-rules / process modelling: `platform-implementation` §1, §4, §5
- Response shapes per use-case kind (X-1 table): `platform-cookbook`
- `DomainResponse` / `ContextResponse` / `DomainResponseTransformer`: generated per domain (`{Domain}\Response\`, Response-Trio) — not package classes; `ResponseStatus` + response/context interfaces: `jardissupport/contracts`
- ENV-driven Koffer assembly + one-time App entry point (`App/bootstrap.php`): `core-kernel` (Bootstrap-Packer `BuildDomainKernelFromEnv`) — `jardiscore/foundation` does not exist; never reach for it
- HTTP delivery (routing, PSR-15 middleware, canonical envelope mapper `MapDomainResponse`): `core-app` (`jardiscore/app`) — optional, one of several valid transports (§4)
