---
name: generated-code-wire-transport
description: Wiring Designer-generated Queries/Processes into a transport layer — bootstrap lifetime, call chain (Domain → BC → Aggregate READ facade → query, plus Domain → BC → Process facade → process for writes; the aggregate WRITE facade is family-internal only and not reachable from transport code), DomainResponse→transport mapping, error handling for HTTP / CLI / queue / worker.
zone: post-active
persona: D
profile: jardis
prerequisites: [generated-code-extend]
next: []
---

### 1. Call chain

Two chains reach the transport layer — **read** and **process** — the BC facade's public surface (G2) exposes no aggregate writes:

```
new MyApp($kernel)                ← final Domain facade, holds the DomainKernel (DomainKernelInterface), one per request/run
    ↓ $app->counter()             ← BC facade (the public surface)
    ↓ ->counter()                 ← Aggregate READ facade `{Agg}Read` (hermetic, reached via $bc->{agg}())
    ↓ ->getCounterById($dto)      ← DomainResponseInterface
```

```
new MyApp($kernel)                ← final Domain facade, holds the DomainKernel (DomainKernelInterface), one per request/run
    ↓ $app->counter()             ← BC facade (the public surface)
    ↓ ->process()                 ← Process facade `{BC}Process` (hermetic, reached via $bc->process())
    ↓ ->createCounter($dto)       ← DomainResponseInterface
```

Three method hops on the `MyApp` instance: BC accessor → `{agg}()`/`process()` accessor → use-case method. **There is no general third chain for aggregate writes from the transport layer.** The aggregate write facade `{Agg}/{Agg}.php` is reachable only family-internally, via the kernel seam (`$this->handle({Agg}::class)`), from classes that extend the Domain Context (Process node bodies, Services — the Context family). A PSR-15 controller, CLI command, or queue consumer is **outside** that family and has no `handle()` of its own — it can only reach `$app->{bc}()->{agg}()` (read) and `$app->{bc}()->process()->{process}()` (write). To let the transport layer trigger a write, model a **Process** around the aggregate command (`generated-code-extend` §2/§4) — a bare aggregate command with no Process is by design not externally callable (G6).

**Rules-Layer exception (G10).** A Command explicitly marked `expose: true` in `Closures.json` (and therefore carrying ≥1 bound Rule — enforced at build time) gets a **fourth, narrower** call chain straight off the BC facade: `$app->{bc}()->{lcfirst(Command)}($dto)` — two hops, no `process()`, no aggregate accessor. It still runs the Command's Rule chain (Guard) unconditionally, from inside the same generated CommandHandler every other caller uses — the shortcut is only at the transport hop, not a bypass of the guard. This exists specifically for Commands with no orchestration need beyond their Rule chain; a Command with real multi-step behaviour still belongs behind a Process.

The aggregate READ facade `{BC}/Model/{Agg}/{Agg}Read.php` carries one public method per generated query/list (mixed ById/ByIds/By{Key}/By{PluralKey}/list — `By{Key}`/`By{PluralKey}` only on an aggregate WITH a unique key, `list` only where `Queries.json` declares one; ById/ByIds are always there) — all reachable directly on it; it is hermetic (regenerated every build, no aggregate-root file in front of it). The Process facade `{BC}/Process/{BC}Process.php` carries one thin-dispatch method per process. Never instantiate handlers via `new` (V3: bypassing `handle()` — see `generated-code-extend` §3).

Matching BC/aggregate names (`MeterDevice\Counter\Counter`) → two `->counter()` hops for reads are correct (`$app->counter()->counter()->getCounterById($dto)`); the BC facade internally aliases the read class `CounterAggregateRead` to avoid a name collision, the accessor name stays `counter()` either way. Non-matching (`Ecommerce\Sales\Order`) → `$app->sales()->order()->getOrderById($dto)`.

**BC-level processes** wire through: `$app->counter()->process()->{process}($dto)` → `DomainResponseInterface`. The `->process()` hop returns the hermetic `{BC}Process` facade; everything in §3–§5 below (response mapping, error handling) applies identically to both chains.

### 2. Bootstrap lifetime

The DomainKernel (`$kernel: DomainKernelInterface`) is a plain immutable value object built once by `BuildDomainKernelFromEnv`, typically invoked from the generated one-time `App/bootstrap.php` — there is no static `$sharedRegistry` and no `DomainApp`/`ServiceRegistry`. Bootstrap internals (what `BuildDomainKernelFromEnv` wires, ENV shape): s. Skill `core-kernel`. `MyApp` (and every BC facade) is a cheap, uncached `new` — safe to construct fresh on every access.

| Transport | DomainKernel (`$kernel`) lifetime | `MyApp` lifetime | Note |
|---|---|---|---|
| HTTP (PSR-15) | One per request (or DI singleton if the underlying adapters tolerate reuse) | One per request, cheap `new MyApp($kernel)` | No caching on any facade — safe to recreate |
| Long-running worker (RoadRunner, Swoole) | One per process (built once at boot) | One per message | The DomainKernel holds no mutable global/shared state — reusing it across messages is safe as long as the wrapped adapters (DB pool, cache, …) are themselves safe to share across messages |
| Queue consumer | Same as worker | One per message | Same |
| CLI / Cron | One per process | One per invocation | Trivial |

Tenancy still matters at the adapter level: build a fresh DomainKernel (fresh DB credentials/connection, fresh cache namespace, …) per tenant when tenant isolation is required — there is no kernel-level `$sharedRegistry` mechanism to leak through.

### 3. DomainResponse → transport

| Status | HTTP | CLI exit | Meaning |
|---|---|---|---|
| 200 | 200 OK | 0 | Success — every successful read and every successful Process answers 200 (a Process never answers 201, even when its Create node ran; the 201 of an aggregate Create handler stays family-internal) |
| 204 | 204 No Content | 0 | Success, empty body |
| 400 | 400 Bad Request | 2 | Field/DTO validation failed (before any Rule runs) — `data` is `{"@type":"validation","fields":[{field,reason,message}]}` (one form for route guard, aggregate command and Process; `field` is the outer name per FieldMap as a dot path without list index, e.g. `customer.customerName` where the column is called `name`; `reason` comes from the validator kind (`jardissupport/validation` ≥ 1.2.0 `ValidationResult::getKinds()`): `missing` only for a validator implementing `MissingValueValidatorInterface` (contracts ≥ 2.3.0), otherwise `invalid`; a custom `message` never changes `reason`). An empty string is no value: a NOT-NULL string column without a value list answers `""` and `null` with `reason: invalid` (not `missing`; `"  "` counts as a value) and its request schema carries `minLength: 1`; `missing` stays for enum, date, number, boolean, value-list and child-presence fields. The path form depends on the door: at the aggregate door (PUT / Command route) `field` is flat (`orderNumber`, `customer.customerName`), at a Process gate it is prefixed with the Process property name (`registration.serviceName`) — one form per door: the prefix also applies to the findings of the Command validator (e.g. an empty string), and there is no special case for a Process with exactly one property, so a frontend maps a Process's field faults through the property name. A third `reason`, `unknown` (message `Unknown field`), marks a body key the contract does not name — at the route root, in nested/list items path-prefixed (`contactAddress.street`), listed after the `missing`/`type` findings of the known fields; every request schema with body fields carries `additionalProperties: false` |
| 401 | 401 Unauthorized | 2 | Auth missing |
| 403 | 403 Forbidden | 2 | Auth insufficient |
| 404 | 404 Not Found | 2 | A single read found nothing, or a write loaded its aggregate and missed it (Update/Remove/Add-child/Set — root, and the child a Remove looks up by identifier; never a Create) — `data` is `{"@type":"notFound","context":{<route parameter or load identifier>}}`, and a Process reaching such a loading Command node answers the same (first 4xx wins, the node routes `ON_FAIL`); a bulk read never answers 404 (200 with `items: []`); the router's own 404/405 is the contract's `BoundaryEnvelope` |
| 409 | 409 Conflict | 2 | Stale write / state conflict — `data` is `{"@type":"concurrencyConflict","reason","aggregate","context"}` — `reason` is `stale_write`, `stale_delete` or `duplicate` (a unique-key duplicate, typed from the repository package; an FK violation stays 500); `context` carries the root's outer-door identifier under its FieldMap name (`id` only without a unique key), never primary-key or column internals |
| 422 | 422 Unprocessable Entity | 2 | Rules-Layer: a bound business Rule rejected the Command — `data` is `{"@type":"ruleViolation","rule","messageKey","context"}` (requires `jardiscore/kernel` ≥ 1.1.0); map `messageKey` to a localized message in this transport layer, never in the domain |
| 500 | 500 Internal | 1 | Exception escaped the pipeline (incl. a technical failure inside a Rule's existing-data check — never a 422); the message is in `errors` |

Envelope from `getStatus()` / `getData()` / `getErrors()` / `getMetadata()` (plus `isSuccess()` shortcut). **`getData()` is flat:** it is the business object itself, typed by its own `@type` field — there is no handler-name or aggregate-root level in between. **`getErrors()` is context-keyed** (`array<string, list<string>>`, e.g. `['counter' => ['message']]`); on the wire `errors` is an object `{<context>: [message, …]}`, an empty `{}` when nothing failed (never `[]`). `meta` carries `duration`, `contexts`, `timestamp`, `version`. Per X-1 the generator emits a minimal payload — **Command** echoes only the reference values (root business key, plus the affected child key), **Query** returns the projected scalar tree of the aggregate record, flat. `@type` per answer kind (full table: `generated-code-recipes` Recipe 6): the aggregate's root key (`counter`) for a single read and a Command, `<agg>List` for list and bulk reads, the aggregate of the success command for a Process, `validation` / `concurrencyConflict` / `ruleViolation` for 400 / 409 / 422. Examples:

```json
// Command via a Process (e.g. createCounter) → 200, reference value only
{
  "status": 200,
  "data":   { "@type": "counter", "counterIdentifier": "018e..." },
  "errors": {},
  "meta":   { "duration": 12.4, "contexts": ["counter"], "timestamp": "2026-10-07T10:00:00+00:00", "version": "" }
}

// Query (e.g. getCounterById / getCounterByIdentifier) → 200, flat projected record
{
  "status": 200,
  "data":   { "@type": "counter", "id": 1, "identifier": "018e...", "counterNumber": "M-1", "activeFrom": "2026-01-01", "recordedAt": "2026-10-07T10:00:00+00:00", "price": "19.90" },
  "errors": {},
  "meta":   { "duration": 8.1, "contexts": ["counter"], "timestamp": "2026-10-07T10:00:00+00:00", "version": "" }
}

// Bulk read (getCounterByIds / getCounterByIdentifiers) → 200
{ "status": 200, "data": { "@type": "counterList", "items": [ { "id": 1, "identifier": "018e..." } ] }, "errors": {}, "meta": { … } }

// Validation failure → 400
{
  "status": 400,
  "data":   { "@type": "validation", "fields": [ { "field": "counterNumber", "reason": "missing", "message": "Field is required." } ] },
  "errors": { "<context>": [ "…" ] },
  "meta":   { … }
}

// Not found on write (e.g. update of a missing counter) → 404, never 500
{ "status": 404, "data": { "@type": "notFound", "context": { "counterIdentifier": "018e..." } }, "errors": {}, "meta": { … } }

// Rule rejection → 422
{ "status": 422, "data": { "@type": "ruleViolation", "rule": "…", "messageKey": "counter.must_be_active", "context": { "identifier": "018e..." } }, "errors": {}, "meta": { … } }
```

Time leaves the door as an ISO string in UTC (`date` `Y-m-d`, `date-time` ATOM with `+00:00`; never a `DateTime` object), a decimal as a string (`format: decimal`, pattern `^-?\d+(\.\d+)?$`), a column bound to a Werteliste as a member of a closed `enum`. A single read or a loading write that finds nothing answers 404 `{"@type":"notFound","context":{<route parameter or load identifier>}}` at the outer door (inside the family, through the kernel seam, the payload stays empty). A list read through the aggregate read facade (`{agg}List(...)`) returns a plain array `{items, total, limit, offset}`, not a `DomainResponse`; the generated route wraps it as `data = {"@type":"<agg>List", items, total, limit, offset}` — a hand-written transport that wants the same outer shape does the same.

CQRS: the Command response carries only identity — to get full state after a write, issue the matching read-base query (`get<Agg>By<UniqueKey>` with the echoed business key, or `get<Agg>ById`) via `$app->{bc}()->{agg}()`. Every aggregate's read facade (`{Agg}Read`) carries the uniform read base `get<Agg>ById` / `get<Agg>ByIds` / `get<Agg>By<UniqueKey>` plus `<agg>List` — there is no suffix-less `get<Agg>` (catalog + bulk-read recipe: `generated-code-extend` §1). An aggregate with a public unique key also carries `get<Agg>By<PluralKey>` (bulk read on that key), and its list items lead with that key — not with `id` — as the public surface's canonical handle (the single-aggregate-record projection is key-conformant either way); only an aggregate **without** a unique key leads list items with the root `id`. Family-internally `id` remains the reachable handle either way (`getById`/`getByIds` keep being emitted); whether ids are exposed outward at all is this transport layer's decision. Events (`getEvents()`) are collected on the response, not dispatched by the handler; publication after commit is the caller's job (a Process node — see `generated-code-recipes` §1). Include them in the transport response only for debug / fire-hose APIs.

### 4. Transport patterns

> **Process input DTO name:** the examples below assume a Process `CreateCounter` was modelled around the `Counter` aggregate's `createCounter` command (`generated-code-extend` §2/§4) — its input DTO lives at `{BC}/Process/CreateCounter/Command/CreateCounter.php` (namespace `…\Process\CreateCounter\Command`), distinct from the aggregate's own Command DTO of the same simple name. Constructor fields like `name`/`obis` are **illustrative**, domain-specific to this example — not a fixed API; the real fields come from your process's own `input.fields`.

**Optional ready-made HTTP delivery (`jardiscore/app`).** This skill stays transport-agnostic by design (§1/§6) — the hand-rolled `JsonResponse` examples below work for any PSR-15 framework and remain valid. For HTTP specifically, `jardiscore/app` is a ready-made alternative to hand-rolling this wiring yourself: routing, a PSR-15 middleware pipeline, and the canonical `DomainResponseInterface` → PSR-7 `{status,data,errors,meta}` envelope mapper. Pipeline internals (router/middleware wiring, the mapper's class): s. Skill `core-app`. What the transport author must know regardless of internals: the mapper covers every one of the 11 `ResponseStatus` cases, with two documented deviations — `204` returns a bare empty response (no body, no `Content-Type`), and `422` passes `getData()` through unreshaped. It is optional, not a requirement: CLI, queue, and worker transports (below) have no equivalent package and stay hand-rolled either way.

**Generated route registration (`Api/{Domain}/routes.php`).** For `jardiscore/app` specifically, wiring §1's call chains onto `Routes` needs no hand-rolled controllers per route: the Builder emits `{outputDir}/Api/{Domain}/routes.php` — a closure file with signature `function (Routes $routes, {Domain} $domain, string $prefix = ''): void` — that registers every read/process/exposed-Command route from §1 against the app's own `Routes` instance, decoding the JSON body, dispatching through `$domain->{bc}()->…`, and mapping the `DomainResponseInterface` back through the same envelope as `core-app`'s mapper. The app calls this closure once at bootstrap and writes zero lines of its own routing code; it is built from the same route table as the OpenAPI spec (`Api/{Domain}/openapi.yaml`), so spec and registered routes are provably identical (verified in-vivo via route introspection). Decimal-typed request fields decode as PHP strings (`format: decimal` in the spec — same `decimal`→`string` convention as everywhere else). A path/method collision across BCs is a hard FastRoute boot failure unless the app passes a distinguishing `$prefix`. `routes.php` is hermetic — do not hand-edit, it is overwritten every build like the rest of `Api/{Domain}/`.

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
            'status' => $response->getStatus(),
            'data'   => $response->getData() ?: new \stdClass(),      // flat business object, typed by `@type`
            'errors' => $response->getErrors() ?: new \stdClass(),    // object {<context>: [message]}, never []
            'meta'   => $response->getMetadata(),
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
            'status' => $response->getStatus(),
            'data'   => $response->getData() ?: new \stdClass(),      // flat business object, typed by `@type`
            'errors' => $response->getErrors() ?: new \stdClass(),    // object {<context>: [message]}, never []
            'meta'   => $response->getMetadata(),
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

- 4xx business/validation errors → already in the response: the typed `data` (`validation` / `concurrencyConflict` / `ruleViolation`, §3) and `DomainResponse::getErrors()`. Serialise both, do not rethrow; `errors` stays an object (`{}` when empty).
- Infrastructure exceptions → let them escape to framework middleware / CLI default handler. No bespoke envelope.
- Never `catch (\Throwable)` in the controller to build a custom error body.

### 6. Not in the transport layer

- Business validation → Command Actions
- DB transactions → Repository pipeline
- Event collection → command handler (`addEvent`); publish-after-commit → Process node, not ad-hoc here
- Auth → framework middleware, before the `$app->...` call
- Field mapping → FieldMap + `Hydrate*` Action

### 7. Anchors

- Aggregate facade layout / V-rules / process modelling: `generated-code-extend` §1, §4, §5
- Response shapes per use-case kind (X-1 table): `generated-code-recipes`
- `DomainResponse` / `ContextResponse` / `DomainResponseTransformer` (+ `ResolveProcessOutcome`, which decides how a Process answers): generated per domain (`{Domain}\Response\`) — not package classes; `ResponseStatus` + response/context interfaces: `jardissupport/contracts`
- ENV-driven DomainKernel assembly + one-time App entry point (`App/bootstrap.php`): `core-kernel` (Bootstrap-Packer `BuildDomainKernelFromEnv`) — `jardiscore/foundation` does not exist; never reach for it
- HTTP delivery (routing, PSR-15 middleware, canonical envelope mapper `MapDomainResponse`): `core-app` (`jardiscore/app`) — optional, one of several valid transports (§4)
