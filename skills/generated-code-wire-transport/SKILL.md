---
name: generated-code-wire-transport
description: Wiring Designer-generated Queries/Processes into a transport layer — bootstrap lifetime, call chain (Domain → BC → Aggregate READ facade → query, plus Domain → BC → Process facade → process for writes; the aggregate WRITE facade is family-internal only and not reachable from transport code), DomainResponse→transport mapping, error handling for HTTP / CLI / queue / worker, and the class version through the transport (`X-Jardis-Version` header → `$version`, `meta.version`).
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

The aggregate READ facade `{BC}/Model/{Agg}/{Agg}Read.php` (not generated for an aggregate with `visibility: internal` — it has no outer door, no route and no OpenAPI path) carries one public method per generated query/list (mixed ById/ByIds/By{Key}/By{PluralKey}/list — `By{Key}`/`By{PluralKey}` only on an aggregate WITH a unique key, `list` only where `Queries.json` declares one; ById/ByIds are always there) — all reachable directly on it; it is hermetic (regenerated every build, no aggregate-root file in front of it). The Process facade `{BC}/Process/{BC}Process.php` carries one thin-dispatch method per process. Never instantiate handlers via `new` (V3: bypassing `handle()` — see `generated-code-extend` §3).

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
| 200 | 200 OK | 0 | Success — every successful read and every successful Process answers 200 (a Process never answers 201, even when its Create node ran; the 201 of an aggregate Create handler stays family-internal). A Process answers with the data of the node it **ends on**, nothing handed on from earlier nodes (workflow 4.0): an end node without an answer of its own (a rule or event node) answers with no fields of its own — on the 2xx wire `data` carries only the process `@type`, `{"@type":"<processName>"}` (wire-contract case `200-empty`) — a later node that needs an earlier answer reads it explicitly (`latest()` / `all()`) |
| 204 | 204 No Content | 0 | Success, empty body |
| 400 | 400 Bad Request | 2 | Field/DTO validation failed (before any Rule runs; a Process checks its command inputs even before its first node — `generated-code-extend`, process gate) — `data` is `{"@type":"validation","fields":[{field,reason,message,messageKey}]}` (one form for route guard, aggregate command and Process; `field` is the outer name per FieldMap as a dot path with the list index at every door, e.g. `customer.customerName` where the column is called `name`, `orderItem[0].qty` for a list element; `reason` comes from the validator kind (`jardissupport/validation` ≥ 1.2.0 `ValidationResult::getKinds()`): `missing` only for a validator implementing `MissingValueValidatorInterface` (contracts ≥ 2.3.0), otherwise `invalid`; a custom `message` never changes `reason`). An empty string is no value: a NOT-NULL string column without a value list answers `""` and `null` with `reason: invalid` (not `missing`; `"  "` counts as a value) and its request schema carries `minLength: 1`; `missing` stays for enum, date, number, boolean, value-list and child-presence fields. The path form depends on the door: at the aggregate door (PUT / Command route) `field` is flat (`orderNumber`, `customer.customerName`), at a Process gate it is prefixed with the Process property name (`registration.serviceName`) — one form per door: the prefix also applies to the findings of the Command validator (e.g. an empty string), and there is no special case for a Process with exactly one property, so a frontend maps a Process's field faults through the property name. A third `reason`, `unknown` (message `Unknown field`), marks a body key the contract does not name — at the route root, in nested/list items path-prefixed (`contactAddress.street`), listed after the `missing`/`type` findings of the known fields; every request schema with body fields carries `additionalProperties: false` |
| 401 | 401 Unauthorized | 2 | Auth missing — at a Process door whose process declares an actor: the door's actor check (`CheckActor`) answers 401 before any node, message key `process.actorMissing`, `data` is `{}` (wire-contract case 401) |
| 403 | 403 Forbidden | 2 | Auth insufficient |
| 404 | 404 Not Found | 2 | A single read found nothing, or a write loaded its aggregate and missed it (Update/Remove/Add-child/Set — root, and the child a Remove looks up by identifier; never a Create) — `data` is `{"@type":"notFound","context":{<route parameter or load identifier>}}`, and a Process reaching such a loading Command node answers the same (the node's 4xx is a `reject`: the run ends with that status, rollback — `data` is `{"@type":"notFound","context":{…}}`, plus the node's declared `messageKey`); a bulk read never answers 404 (200 with `items: []`); the router's own 404/405 is the contract's `BoundaryEnvelope` |
| 409 | 409 Conflict | 2 | Stale write / state conflict — `data` is `{"@type":"concurrencyConflict","reason","aggregate","context"}` — `reason` is `stale_write`, `stale_delete` or `duplicate` (a unique-key duplicate, typed from the repository package — always 409, never 422; an FK violation stays 500; a transient database conflict — snapshot/serialization failure, deadlock — is `stale_write`/`stale_delete` with `violated: []`, not 500: retry the whole request in a new transaction); `context` carries the root's outer-door identifier under its FieldMap name (`id` only without a unique key), never primary-key or column internals |
| 422 | 422 Unprocessable Entity | 2 | Rules-Layer: a bound business Rule rejected the Command — or, at a Process door, a developer-owned node body of the own BC answered 422 (a unique-key violation is NOT a rule rejection, it is 409 `duplicate`) — `data` is `{"@type":"ruleViolation","rule","messageKey","context"}` (requires `jardiscore/kernel` ≥ 1.1.0); the Process door's OpenAPI declares this 422 whenever a Rule or an own-BC dev body is reachable (also through sub-processes) — `messageKey` is the closed enum of the catalog keys of the reachable Rules if all of them declare one, an open string as soon as a reachable Rule has no catalog key or an own-BC dev body is reachable, or a cross-BC call, external call or custom node with a foreign-BC call is reachable next to the Rules (its service body can answer 422 with its own key) (a Command door declares the same enum or stays open the same way); the response carries the catalog key when declared; a cross-BC or external call alone declares no 422; map `messageKey` to a localized message in this transport layer, never in the domain |
| 500 | 500 Internal | 1 | Exception escaped the pipeline (incl. a technical failure inside a Rule's existing-data check — never a 422); the message is in `errors`. At a Process door `data` and `errors` are `{}` (wire-contract case 500): the cause is logged once by the package, nothing of it reaches the answer |

> **Root update carries no child lists.** The root update (`PUT /…/{key}`) writes the root fields only; children are maintained through `Update{Child}` / `Add{Child}` / `Remove{Child}` (or as a block on the create path). A child field in a PUT body is a 400 with `reason: unknown`. The root PUT route exists only for a Command marked `expose: true` in `Closures.json`.

Envelope from `getStatus()` / `getData()` / `getErrors()` / `getMetadata()` (plus `isSuccess()` shortcut). **`getData()` is flat:** it is the business object itself, typed by its own `@type` field — there is no handler-name or aggregate-root level in between. **`getErrors()` is context-keyed** (`array<string, list<string>>`, e.g. `['counter' => ['message']]`); on the wire `errors` is an object `{<context>: [message, …]}`, `{}` when no component put a message there (never `[]`). **Where the cause of a failure lives:** only a **500** reliably fills `errors`; a **4xx** (400 field/guard, 404, 409, 422) carries its cause in the typed `data` (`fields[]` / `context` / `rule`+`messageKey`), and `errors` may be `{}` there — the route guard's 400 and a node's 404 leave it empty, the aggregate CommandHandler's 400 fills it with the validator message. So a transport that needs the reason of a 4xx reads `getData()`, never only `getErrors()`. `meta` carries `duration`, `contexts`, `timestamp`, `version`. Per X-1 the generator emits a minimal payload — **Command** echoes only the reference values (root business key, plus the affected child key), **Query** returns the projected aggregate: the root fields flat in the `data` array, children as typed `{Child}Response` objects (in PHP read them with `->`; `json_encode` writes the same nested JSON as below). `@type` per answer kind (full table: `generated-code-recipes` Recipe 6): the aggregate's root key (`counter`) for a single read and a Command, `<agg>List` for list and bulk reads, for a Process the process name (lcfirst) or, at a command end, the aggregate root key, `validation` / `notFound` / `concurrencyConflict` / `ruleViolation` for 400 / 404 / 409 / 422 (a Process builds the form from the `reject(...)` of a dev body: `data` is `@type` plus the form's fields; a 4xx without a form, e.g. 401/403/405, is passed through unchanged; the `messageKey` the node declares with `rejects(status, key)` is set on `data` after forming, for every 4xx status — also when it is not a field of the form). Examples:

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
  "data":   { "@type": "counter", "identifier": "018e...", "counterNumber": "M-1", "activeFrom": "2026-01-01", "recordedAt": "2026-10-07T10:00:00+00:00", "price": "19.90" },
  "errors": {},
  "meta":   { "duration": 8.1, "contexts": ["counter"], "timestamp": "2026-10-07T10:00:00+00:00", "version": "" }
}

// Bulk read (getCounterByIds / getCounterByIdentifiers) → 200
{ "status": 200, "data": { "@type": "counterList", "items": [ { "identifier": "018e..." } ] }, "errors": {}, "meta": { … } }

// Validation failure → 400
{
  "status": 400,
  "data":   { "@type": "validation", "fields": [ { "field": "counterNumber", "reason": "missing", "message": "Field is required.", "messageKey": "validation.missing" } ] },
  "errors": { "<context>": [ "…" ] },   // filled by the aggregate CommandHandler; {} from the route guard — the cause is always in data.fields
  "meta":   { … }
}

// Not found on write (e.g. update of a missing counter) → 404, never 500
{ "status": 404, "data": { "@type": "notFound", "context": { "counterIdentifier": "018e..." } }, "errors": {}, "meta": { … } }

// Rule rejection (or own-BC dev-body 422 at a Process door) → 422
{ "status": 422, "data": { "@type": "ruleViolation", "rule": "…", "messageKey": "counter.must_be_active", "context": { "identifier": "018e..." } }, "errors": {}, "meta": { … } }
```

Time leaves the door as an ISO string in UTC (`date` `Y-m-d`, `date-time` ATOM with `+00:00`; never a `DateTime` object), a decimal as a string (`format: decimal`, pattern `^-?\d+(\.\d+)?$`), a column bound to a Werteliste as a member of a closed `enum`. A single read or a loading write that finds nothing answers 404 `{"@type":"notFound","context":{<route parameter or load identifier>}}` at the outer door (inside the family, through the kernel seam, the payload stays empty). A list read through the aggregate read facade (`{agg}List(...)`) returns a plain array `{items, total, limit, offset}`, not a `DomainResponse`; the generated route wraps it as `data = {"@type":"<agg>List", items, total, limit, offset}` — a hand-written transport that wants the same outer shape does the same.

CQRS: the Command response carries only identity — to get full state after a write, issue the matching read-base query (`get<Agg>By<UniqueKey>` with the echoed business key, or `get<Agg>ById`) via `$app->{bc}()->{agg}()`. Every aggregate's read facade (`{Agg}Read`) carries the uniform read base `get<Agg>ById` / `get<Agg>ByIds` / `get<Agg>By<UniqueKey>` plus `<agg>List` — there is no suffix-less `get<Agg>` (catalog + bulk-read recipe: `generated-code-extend` §1). An aggregate with a public unique key also carries `get<Agg>By<PluralKey>` (bulk read on that key), and its list items lead with that key — not with `id` — as the public surface's canonical handle and the projected single-aggregate record of such an aggregate has no root `id` property at all; only an aggregate **without** a unique key leads list items with the root `id` and carries it (normalized as `id`) in the record. Family-internally `id` remains the reachable handle either way (`getById`/`getByIds` keep being emitted); whether ids are exposed outward at all is this transport layer's decision. Events (`getEvents()`) are collected on the response, not dispatched by the handler; publication after commit is the caller's job (`App/**` delivery code for an event an Event ◇ node announced — see `generated-code-recipes` §1). Include them in the transport response only for debug / fire-hose APIs.

### 4. Transport patterns

> **Where transport code stands.** A hand-written transport — controller, CLI command, queue consumer, event listener, project middleware — is delivery code in `App/**` (row 10 of the table in `generated-code-extend` §10). It carries no business logic, writes nothing itself, and calls only the doors `{agg}()` / `process()` of §1 and the kernel's accessors; never `Model/**`, a repository or `DbQuery`. The examples below show that shape.

> **Process input DTO name:** the examples below assume a Process `CreateCounter` was modelled around the `Counter` aggregate's `createCounter` command (`generated-code-extend` §2/§4) — its input DTO lives at `{BC}/Process/CreateCounter/Command/CreateCounter.php` (namespace `…\Process\CreateCounter\Command`), distinct from the aggregate's own Command DTO of the same simple name. Constructor fields like `name`/`obis` are **illustrative**, domain-specific to this example — not a fixed API; the real fields come from your process's own `input.fields`.

**Optional ready-made HTTP delivery (`jardiscore/app`).** This skill stays transport-agnostic by design (§1/§6) — the hand-rolled `JsonResponse` examples below work for any PSR-15 framework and remain valid. For HTTP specifically, `jardiscore/app` is a ready-made alternative to hand-rolling this wiring yourself: routing, a PSR-15 middleware pipeline, and the canonical `DomainResponseInterface` → PSR-7 `{status,data,errors,meta}` envelope mapper. Pipeline internals (router/middleware wiring, the mapper's class): s. Skill `core-app`. What the transport author must know regardless of internals: the mapper covers every one of the 11 `ResponseStatus` cases, with two documented deviations — `204` returns a bare empty response (no body, no `Content-Type`), and `422` passes `getData()` through unreshaped. It is optional, not a requirement: CLI, queue, and worker transports (below) have no equivalent package and stay hand-rolled either way.

**Generated route registration (`Api/{Domain}/routes.php`).** For `jardiscore/app` specifically, wiring §1's call chains onto `Routes` needs no hand-rolled controllers per route: the Builder emits `{outputDir}/Api/{Domain}/routes.php` — a closure file with signature `function (Routes $routes, {Domain} $domain, string $prefix = ''): void` — that registers every read/process/exposed-Command route from §1 against the app's own `Routes` instance, decoding the JSON body, dispatching through `$domain->{bc}()->…`, and mapping the `DomainResponseInterface` back through the same envelope as `core-app`'s mapper. The app calls this closure once at bootstrap and writes zero lines of its own routing code; it is built from the same route table as the OpenAPI spec (`Api/{Domain}/openapi.yaml`), so spec and registered routes are provably identical (verified in-vivo via route introspection). Decimal-typed request fields decode as PHP strings (`format: decimal` in the spec — same `decimal`→`string` convention as everywhere else). A path/method collision across BCs is a hard FastRoute boot failure unless the app passes a distinguishing `$prefix`. `routes.php` is hermetic — do not hand-edit, it is overwritten every build like the rest of `Api/{Domain}/`.

**Middleware pipeline and doors (app template, `jardiscore/app` >= 1.6).** The delivery layer wires the pipeline before the generated routes; the call chain of §1 starts only after it:

```
public/index.php (Delivery)       ← loads src/App/bootstrap.php → the app config entry 'middleware' (list<MiddlewareInterface>)
    ↓ $routes->middleware($m)     ← once per entry, registration order, BEFORE the route mount
    ↓ (require routes.php)($routes, $domain)   ← registers the doors
    ↓ App: 404/405 → ResolveVersion → pipeline (e.g. RequireSession) → route closure → $app->{bc}()->…
```

- **The pipeline is a project setting, not hand-written code.** The Builder project setting `middleware` (`update_project_settings`, see `design-headless-mcp`) lists standard names (`session`) and project classes (FQCN, delivery code in `App/**` without business logic); the build emits `src/App/middleware.php` and wires the session guard concretely there (`new RequireSession(new SignedTokenAuthenticator(base64_decode($kernel->env('auth_token_key'))), …)`; there is no middleware factory in the package any more), the once-written `bootstrap.php` returns it. Never set = `["session"]`. The front controller checks the entry strictly (list of `MiddlewareInterface`); a wrong shape or a failing factory/project class is a startup error answered by the 500 envelope, never a stack trace.
- **Door `session` (default) and door `anonymous`.** A process or query without `door` is a session door: the pipeline's `RequireSession` guards it. `door: anonymous` on the process/query makes the generated route register as `$routes->anonymous()->{verb}(…)` (e.g. a public read such as a price list or a status page) — no session needed, so such a door declares no `requestAttribute` input (V-DOOR-1). A hand-written public route in the front controller does the same: `$routes->anonymous()->get('/status', $handler)`. `/health` is always anonymous and never under `$prefix`.
- **Session attributes in the handler.** After `RequireSession` the request carries `RequestAttribute::SUBJECT` (`jardisSubject`) and, only when the token has a tenant, `RequestAttribute::TENANT` (`jardisTenant`; absent, never null); a session-aware result adds `::SUBJECT_ID` / `::SESSION`. The generated routes read them for `requestAttribute` inputs and scope columns; a hand-written controller reads them from the PSR-7 request, never from the body. Order matters: `session` must stand before any project middleware that reads these attributes.
- **Token model (`jardissupport/auth` >= 1.5).** The access token is signed (HMAC with the 32-byte key `AUTH_TOKEN_KEY`, base64 in `.env`, may be stored as `secret(...)`; generate with `php -r 'echo base64_encode(random_bytes(32));'`): `RequireSession` verifies it without any database access per request. Only refreshing a session goes through `DbTokenStore`. Login and identity doors are not generated by the pipeline: they come from the package side, not from this skill.
- **Error path.** No/invalid token on a session door → the guard answers 401 `{status:401,data:{},errors:{message:"Authentication required."},meta:{}}` + `WWW-Authenticate: Bearer` before the route runs; a throwable from the token store is a 500, not a 401. A route whose required request attribute is absent (tenant/subject/session — e.g. no guard in front, or a token without a tenant) never reaches its handler: the per-route middleware `RequireRequestAttribute` (`jardiscore/app` >= 1.8, wired by the generated `routes.php`) answers 403 `{status:403,data:{},errors:{message,messageKey:"request.attributeMissing",context:{attribute:"<name>"}},meta:{}}`; a client state, never a 500 (the OpenAPI names this form, as `oneOf` next to a process-declared 403). 404, 405 and an unknown `X-Jardis-Version` (400) are answered before the pipeline, so they never need a session. With `AppConfig(requireSessionGuard: true)` (template default) a non-anonymous route without a guard already fails the app start.

**Class version through the transport (`X-Jardis-Version`).** Every facade door takes the class version as an argument (`string $version = ''`); a hand-written controller passes it itself, the generated routes do it for you. With `jardiscore/app` >= 1.2.0 the header `X-Jardis-Version` is read **once** in the gate (`ResolveVersion`) against `AppConfig(knownVersions: …)` and handed on as the request attribute `jardisVersion` (`''` = baseline, so the attribute is always set); a value outside the known set, an empty value or a repeated header is answered `400` (validation form, field `X-Jardis-Version`, `messageKey` `request.versionUnknown`) before any route runs. Every generated route reads the attribute and passes it as `$version` to its facade door (read, list, declared query, exposed Command, Process) and to its own route-built answers (400 field fault, 404), so `meta.version` of every envelope names the requested version; a known version without a `v{N}/` class for the class that runs is served by the baseline class and `meta.version` still names the requested one. The known versions are the `versions` declaration in the domain manifest; each build writes them to `src/Api/{Domain}/versions.php`, which the app template's `public/index.php` collects. A hand-rolled transport does the same: read the header once at the edge, validate it, pass the result as `$version` to the facade door, never rely on a domain-wide default (there is none). Full model and recipe: Skill `generated-code-versioning` §3–§4.

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
    if (!$response->isSuccess())      throw new UnrecoverableException(json_encode($response->getData()));   // a 4xx cause lives in data (fields[] / messageKey / context); errors may be empty
}
```

### 5. Error handling

- 4xx business/validation errors → already in the response: the typed `data` (`validation` / `notFound` / `concurrencyConflict` / `ruleViolation`, §3; `data` = `@type` plus the form's fields, a 4xx without a form is passed through unchanged; the `messageKey` the node declares with `rejects(status, key)` is set on `data` after forming, for every 4xx status — also when it is not a field of the form) — that is where the cause of a 4xx lives; `DomainResponse::getErrors()` is filled reliably only for a 500 and may be `{}` on a 4xx (route-guard 400, node 404). Serialise both, do not rethrow, and never read the reason of a 4xx from `errors` alone; `errors` stays an object (`{}` when empty).
- Infrastructure exceptions → let them escape to framework middleware / CLI default handler. No bespoke envelope.
- Never `catch (\Throwable)` in the controller to build a custom error body.

### 6. Not in the transport layer

- Business validation → Command Actions
- DB transactions → Repository pipeline
- Event collection → command handler (`addEvent`); publish-after-commit → `App/**` delivery code for the Domain events an Event ◇ node announced (`generated-code-recipes` §1), no business logic
- Auth → the middleware pipeline (project setting `middleware`, default `session`; anonymous doors via `door: anonymous`), before the `$app->...` call
- Request-attribute columns (`requestAttribute`, `identifier.scope`): the authenticating middleware stores subject and tenant under `RequestAttribute::SUBJECT` (`jardisSubject`) / `::TENANT` (`jardisTenant`) of `jardissupport/contracts` >= 2.8.0 before the generated `routes.php` runs; a scoped route without the attribute is answered 403 `request.attributeMissing` by `RequireRequestAttribute` (`jardiscore/app` >= 1.8) instead of reading or writing outside a scope
- Field mapping → FieldMap + `Hydrate*` Action

### 7. Anchors

- Aggregate facade layout / V-rules / process modelling / where hand code may stand (`App/**` = row 10): `generated-code-extend` §1, §4, §5, §10
- Response shapes per use-case kind (X-1 table): `generated-code-recipes`
- `DomainResponse` / `ContextResponse` / `TransformResponse` / `BuildResponseData` (how a Process answer becomes the envelope's `data`): package classes of `jardissupport/response` (skill `support-response`); `ResponseStatus` + response/context interfaces: `jardissupport/contracts`
- ENV-driven DomainKernel assembly + one-time App entry point (`App/bootstrap.php`): `core-kernel` (Bootstrap-Packer `BuildDomainKernelFromEnv`) — `jardiscore/foundation` does not exist; never reach for it
- HTTP delivery (routing, PSR-15 middleware, canonical envelope mapper `MapDomainResponse`): `core-app` (`jardiscore/app`) — optional, one of several valid transports (§4)
