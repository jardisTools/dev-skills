---
name: platform-cookbook
description: Phase-3 recipes and troubleshooting for Designer-generated code — Event transport from a Process node (Kafka/RabbitMQ/Redis/HTTP-webhook/in-process; the generated `<Agg>EventRouter.php` is hermetic), VO in a Process node, Domain Service, new aggregate op vs. new Process, self-contained Process input, Response shapes per operation, bulk read list→ids→`get{Agg}ByIds` (or, with a unique key since P13, list→keys→`get{Agg}By{PluralKey}`), sub-process node, cross-BC write (DTO-translation → foreign `process()` → response-mapping), guard a Command with a business Rule (Rules-Layer), Invariante als Zustand (uniqueness invariant via a first-writing Torwächter node + CAS-UPDATE instead of check-then-act, Statusaggregat/Fakturierung and Nummernkreis/Reservierung-with-Retry cases), troubleshooting table (ClassVersion misses, hermetic-tree edits lost, `@node-id` body-preserve, routing-safety, cross-BC, listener exceptions, Rule-merge/422 pitfalls).
zone: post-active
persona: C
prerequisites: [platform-implementation]
next: []
---

> **Layout context.** The aggregate tree `{BC}/Aggregate/{Agg}/` (abbreviated `{Agg}/` in this skill) is hermetic — ForceOverwrite, never edited (V1); developer code lands only in the BC-level Process scope `{BC}/Process/{Name}/`, reached via `$bc->process()`. `$bc->{agg}()` reaches only the read facade `{Agg}Read` (Außentür); the write facade `{Agg}` is family-internal via the Kernel-Naht `$this->handle({Agg}::class)`. Canonical layout + Vokabular-Glossar (Aggregat-Fassade / Lese-Fassade / Außentür / Kernel-Naht / Context-Familie): `platform-implementation` §1–§2.

### 1. Event transport — authored in a Process node

The generator emits the base router at `{Agg}/Event/<Agg>EventRouter.php` (namespace `<Domain>\<BC>\Aggregate\<Agg>\Event`, ForceOverwrite). It is a plain `class <Agg>EventRouter` carrying one **empty** `protected function on<Event>(EventListenerRegistryInterface $registry): void` stub per Domain Event (body `// configure transport`), each preceded by a channel-key comment (`{ChannelPrefix}.{ChannelSuffix}`) as a topic / routing-key suggestion. The Domain facade wires it directly into `eventDispatcher()`:

```php
protected function eventDispatcher(): EventDispatcherInterface|false|null
{
    return (new EventDispatcherHandler())(
        new CounterEventRouter()
    );
}
```

**The router is hermetic** (ForceOverwrite) — you never fill its bodies; the next build truncates them (V1). Transport is therefore authored where developer code is allowed: a **Process node**. Model a Process whose orchestrator runs the aggregate command, then publish the events it produced from a downstream custom node. The aggregate command records its events on the response (`$this->result()->addEvent($event, EventScope::Internal)` in the generated handler) — the handler **only collects, it does not dispatch** — so the node reads `$response->getEvents()` and hands each to a transport via `handle()`. Note `getEvents(?EventScope)` is **context-keyed** (`array<string, array<int, object>>`), so flatten it before iterating: `array_merge(...array_values($response->getEvents()))` (or a nested `foreach`). (All aggregate events are `Internal`; `EventScope::Domain` + a „verkünden" switch are planned, not yet emitted.)

**Event payload identity (X-3).** `<Agg>{ChildEntity}Added` events carry the affected child's business identifier (`<childIdentifier>`) when G4 is satisfied — otherwise the internal PK (same rule as the Command response, see Recipe 6). Wire your listener against the business key whenever you can; the internal PK is meaningful only inside the aggregate and changes on rebuild scenarios where data is reseeded.

**Publish from a Process node** (`{BC}/Process/<Name>/Command/Handler/Action/<NodeClass>.php`, `extends <Domain>Context`). The node runs *after* the node that invoked the aggregate command; it reads the command's events off the previous step and publishes them. The reusable part is the `handle()` call into the transport package — never `new` a publisher/client (V2/V3).

**Kafka / RabbitMQ / Redis** via `jardisadapter/messaging`:

```php
public function __invoke(WorkflowContextInterface $context): WorkflowResultInterface
{
    /** @var DomainResponseInterface $response */
    $response = $context->getPrevious()->getData();   // the aggregate command's response

    foreach (array_merge(...array_values($response->getEvents())) as $event) {
        $this->handle(MessagingService::class)
            ->publish('meterdevice.counter.counter.created', $event);
    }

    return new WorkflowResult(WorkflowResult::ON_SUCCESS, []);
}
```

**HTTP webhook** via `jardisadapter/http`:

```php
foreach (array_merge(...array_values($response->getEvents())) as $event) {
    $this->handle(HttpClient::class)->post($webhookUrl, (array) $event);   // event array → JSON body; headers = 3rd arg
}
```

**In-process** (projection, audit trail) — also a node body, calling the projector through `handle()`:

```php
foreach (array_merge(...array_values($response->getEvents())) as $event) {
    $this->handle(CounterProjector::class)->onCreated($event);
}
```

**Rules:**

- Never `new` publishers / clients inside the node — always `handle()` (V2 / V3).
- Never fill the generated `<Agg>EventRouter` bodies — the tree is hermetic (V1); the router's channel-key comments are documentation for the topic names, nothing more.
- A node runs **synchronously** in the workflow. Long operations → enqueue and let a consumer pick up, the node only hands off.
- A throwing node flips the process response per its `ON_FAIL` routing (`platform-workflow` §5). Wrap `try/catch` inside the node only if "event delivery must not fail the process".
- **Tenant / feature-flag transport variant:** because ClassVersion can resolve any generated class, a `v{N}/Event/<Agg>EventRouter.php` next to the baseline is the escape hatch for a per-version router — but version *creation* is out of current scope (PRD P6); prefer the Process node (`platform-versioning` §1).

### 2. Phase-3 cookbook

Paths assume BC `MeterDevice\Counter`, aggregate `Counter`. Aggregate code lives under `{BC}/Aggregate/{Agg}/` (hermetic, never edited); **all developer code lands under `{BC}/Process/<Name>/`** — node bodies in `Command/Handler/Action/` (`@node-id` body-preserve), VOs in `ValueObject/`, Domain Services in `Service/`, optional reads in `Query/`, repos in `Repository/`.

**Recipe 1 — VO used for validation (in a Process node)**

The aggregate's generated Hydrate/Build pipeline is hermetic — you cannot inject a VO into it. Validate the domain concept in a **Process node** that runs before (or instead of) the aggregate command. Author the VO in the Process scope:

VO `{BC}/Process/CounterChange/ValueObject/ObisCode.php`:

```php
namespace MeterDevice\Counter\Process\CounterChange\ValueObject;

final class ObisCode
{
    public function __construct(public readonly string $code)
    {
        if (!preg_match('/^\d+-\d+:\d+\.\d+\.\d+\*\d+$/', $code)) {
            throw new \InvalidArgumentException("Invalid OBIS: {$code}");
        }
    }
}
```

Use it from a custom-node body (`{BC}/Process/CounterChange/Command/Handler/Action/ValidateNewValue.php`):

```php
public function __invoke(WorkflowContextInterface $context): WorkflowResultInterface
{
    /** @var CounterChange $cmd */
    $cmd = $this->payload();

    $this->handle(ObisCode::class, $cmd->obis);   // throws on bad input → ON_FAIL routing

    return new WorkflowResult(WorkflowResult::ON_SUCCESS, []);
}
```

`handle()` constructs the VO (V2/V3 — never `new`). A bad OBIS throws and the node's `ON_FAIL` transition takes over. For a tenant-specific variant of any generated class, see the `v{N}/` escape hatch (`platform-versioning` §1).

**Recipe 2 — Domain Service for external lookup**

Author the Service in the Process scope (`{BC}/Process/CounterChange/Service/ResolveMeterLocationName.php`):

```php
namespace MeterDevice\Counter\Process\CounterChange\Service;

final class ResolveMeterLocationName
{
    public function __construct(private readonly HttpClientInterface $http) {}
    public function __invoke(string $meterLocationIdentifier): string
    {
        $r    = $this->http->get("/meter-locations/{$meterLocationIdentifier}");  // ResponseInterface (PSR-7)
        $data = json_decode($r->getBody()->getContents(), true);
        return (string) ($data['name'] ?? $meterLocationIdentifier);
    }
}
```

Call it from a custom-node body via `$this->handle(ResolveMeterLocationName::class, $cmd->meterLocationIdentifier)`. V9: the service reads only, no persistence — persistence stays in the aggregate command the process invokes.

**Recipe 3 — A new operation: aggregate command/query vs. BC-level Process**

There is no hand-edit slot on the aggregate; the route depends on *what kind* of operation it is.

**Case A — a new aggregate command or query** (mutates/reads this one aggregate's state). Re-model it in the **Aggregate Designer** (`Aggregate.yaml`) and rebuild. The Generator regenerates the Command/Query DTO + handler + validator tree and exposes the operation **inline on the matching facade** — a query on the read facade `{Agg}Read.php` (Außentür), a command on the write facade `{Agg}.php` (family-internal only):

```php
$bc->counter()->getCounterByIdentifier($dto);               // query — generated, reached via $bc->{agg}() (Außentür)
$this->handle(Counter::class)->deactivateCounter($dto);     // command — generated, family-internal via the Kernel-Naht
```

No method is hand-written and nothing under `{Agg}/` is edited — both facades are fully regenerated (no `@flow-id`, no body merge). A new command has **no** external caller by itself (G6, the aggregate is not writable from outside without a Process) — wrap it in a Process (Case B) to expose it via `$bc->process()`.

**Case B — a BC-level operation or one that coordinates several aggregates.** Model a **Process** in the Process Designer. The Generator emits, under `{BC}/Process/<Name>/`:

1. `Command/<Name>.php` — the self-contained input DTO (`readonly`, its own `input.fields` — Recipe 5). Namespace: `…\Process\<Name>\Command\<Name>`.
2. `Command/Handler/<Name>Handler.php` — the Workflow orchestrator (`__invoke()` + `config()` building the full graph). ForceOverwrite — regenerated, not edited. Namespace: `…\Process\<Name>\Command\Handler\<Name>Handler`.
3. `Command/Handler/Action/<NodeClass>.php` — one node stub per node. Custom nodes are `CreateIfNotExists` with an `@node-id` marker; **the body is yours** and survives rebuilds. Namespace: `…\Process\<Name>\Command\Handler\Action\<NodeClass>`.
4. A thin-dispatch method on the hermetic `{BC}Process` facade: `return $this->context(<Name>Handler::class, $in, $version)();` — reached via `$bc->process()->{name}($dto)`.

The segments `Query/`, `Repository/`, and `Service/` are **not emitted** by the Generator — they are developer-authored as needed (KI-/Dev-owned).

Your logic lives in the custom-node bodies. The process has **no aggregate ownership** and invokes whichever aggregate commands/queries it needs via `$this->context(...)` / `$this->handle(...)` from a node.

**Recipe 4 — Event to Kafka (end-to-end)**

A concrete instance of §1. Model a Process that (a) invokes the aggregate command in one node, then (b) publishes its events in a downstream node:

```php
// {BC}/Process/CounterChange/Command/Handler/Action/PublishCreated.php
public function __invoke(WorkflowContextInterface $context): WorkflowResultInterface
{
    /** @var DomainResponseInterface $response */
    $response = $context->getPrevious()->getData();

    foreach (array_merge(...array_values($response->getEvents())) as $event) {
        $this->handle(MessagingService::class)
            ->publish('meterdevice.counter.counter.created', $event);
    }

    return new WorkflowResult(WorkflowResult::ON_SUCCESS, []);
}
```

Test via the `EventCollector` fake (see `rules-testing` §6) plus a `MessagingService` fake asserting the published payload. Never edit the hermetic `<Agg>EventRouter` (§1).

**Recipe 5 — A Process input is self-contained**

A Process DTO declares **its own** input and never extends an aggregate Command/Query DTO. The former `input.extends: platform:<Name>` reference was **removed** in the Flow→BC refactor (a process has no aggregate ownership). The Designer carries the input as `input.fields` with Option-1.5 type syntax (`?type` = nullable, `= <literal>` = default), and the Generator materialises a standalone `readonly` DTO:

```php
namespace MeterDevice\Counter\Process\CounterChange;

readonly class CounterChange
{
    public function __construct(
        public string $identifier,
        public int $newValue,
        public ?string $note = null,
    ) {}
}
```

If a process needs data that lives on an aggregate, it does **not** inherit a DTO — it **runs the aggregate query/command** from a node (`$this->context(...)`) and reads the response. There is no merged-DTO / `platform:` parent concept; an unknown field in `input.fields` is a hard build error (no silent fallback).

**Recipe 6 — Response shapes per operation (X-1)**

The Generator emits a fixed, minimal `setData(...)` payload per use-case kind — never the full aggregate (CQRS: Command mutates with identity-only echo, Query reads with projected graph). Layout below for `Counter` (root identifier `identifier`).

| Use-case kind | Generated `setData(...)` shape |
|---|---|
| **Query ById / By{UniqueKey}** (and hand-modelled single variants) | `['counter' => $projected[0] ?? null]` — one projected nested scalar tree or `null` (see Query projection below) |
| **Query ByIds** | `['counter' => $projected]` — `list<Akte>`, **no `[0]` collapse**; missing ids → partial result, empty `ids` → `[]` |
| **Query By{PluralKey}** (P13, `openapi-aussentuer`, 2026-08-27 — only on an aggregate with a public unique key) | `['counter' => $projected]` — `list<Akte>`, **no `[0]` collapse**; missing keys → partial result, empty key list → `[]`; same shape as ByIds, keyed on the unique column instead of the PK |
| **Create** | `['identifier' => $handler->getData()->getIdentifier()]` — root business key only |
| **Set{Child}** / **Add{Child}** | `['identifier' => …, '<childIdentifier>' => …]` — root key from cmd-DTO + affected child key resolved via the aggregate walk |
| **Update** (root scalars) | `['identifier' => $cmd->getIdentifier()]` — pure echo of input identity |
| **Remove** | `['identifier' => $cmd->getIdentifier()]` — root identity only |
| **Remove{Child}** | `['identifier' => $cmd->getIdentifier()]` — Remove{Child} skips the child key; only the root identity is echoed |

Substitute the actual root identifier name (e.g. `counterId`, `meterNumber`) for `identifier` where the aggregate uses a different business key. Child responses use the child's business identifier (`<childIdentifier>`, e.g. `counterGatewayId`).

**Business-key resolution (G4 / X-2).** The Generator picks the root identifier by walking the entity for a Single-Column-Unique-Index on a NOT-NULL `string` column. If exactly one such column exists, that is the business key and surfaces in the response. If none exists, the response falls back to the internal `int` PK property (e.g. `counterGatewayId: int` for a keyless `counterGateway` child — not a defect, the only available identity). If multiple ambiguous candidates exist (X-2: two NOT-NULL-unique-string columns), the Build aborts — model an explicit single business key in the Schema instead of letting the response shape become non-deterministic.

**Query projection.** The Generator emits per BC a `{BC}/FieldMap.php` (ForceOverwrite — a pure naming container with one `{table}Columns()` method per BC table, the write-path DTO→column map; there is no `Fields()` method). The **read** projection (internal-PK strip where a business key exists, G4; root-id normalization; FK-column strip; pure-join collapse, F3.1) is aggregate-structural and runs at the **aggregate read edge (the query handler)**, not in FieldMap — traversal mechanics: [[beschreibt-bauweise-von::builder-generat-bauweise]] §8. The projected Akte therefore always carries the root id — the internal handle the ById/ByIds read base relies on; child entities stay id-free. **Since P13 (`openapi-aussentuer`, 2026-08-27):** this concerns the projected Akte only — the auto-**list** SELECT is no longer uniform: on an aggregate WITH a public unique key it leads with that key column `AS {keyField}` instead of `id` (the outward, key-first bulk-read recipe, Recipe 7 below); an aggregate WITHOUT one still leads its list with `id` as before. `DateTimeImmutable` blade values stay inert — JSON/CLI serialization is the caller's job (G5).

**Command response** never carries domain state — only the identifier(s) the caller needs to address what just changed (event-sourcing / correlation). For the full state after a write, the caller issues the matching read-base query — `get{Agg}By{UniqueKey}` with the echoed business key, or `get{Agg}ById` (CQRS).

**Adding a custom field.** The `setData(...)` block sits inside the generated, hermetic operation `__invoke()` body — you do not edit it (V1). To enrich a response, run the aggregate query/command from a **Process node**, then add fields in the node body before returning (`$this->result()->addData('extra', $value)` — Decorator at process level, see `platform-implementation` §4).

**Recipe 7 — Bulk read: list → keys/ids → full Akten**

Every aggregate facade carries the uniform read base `get{Agg}ById` / `get{Agg}ByIds` / `get{Agg}By{UniqueKey}` (catalog: `platform-implementation` §1). **Since P13 (`openapi-aussentuer`, 2026-08-27):** an aggregate WITH a public unique key additionally carries the bulk variant `get{Agg}By{PluralKey}` (e.g. `getOrderByOrderNumbers`), and its auto-list items lead with that key column instead of `id` — so the recipe forks:

An aggregate **WITH** a unique key — list → keys → `get{Agg}By{PluralKey}`:

```php
$list  = $bc->order()->orderList($filter);                                             // filtered flat list
$keys  = array_values(array_unique(array_column($list['items'], 'orderNumber')));
$akten = $bc->order()->getOrderByOrderNumbers(new QueryOrderByOrderNumbers(orderNumbers: $keys)); // ['order' => list<Akte>]
```

An aggregate **WITHOUT** a unique key — list → ids → `get{Agg}ByIds` (unchanged, pre-P13 shape):

```php
$list  = $bc->counter()->counterList($filter);                               // filtered flat list
$ids   = array_values(array_unique(array_column($list['items'], 'id')));
$akten = $bc->counter()->getCounterByIds(new QueryCounterByIds(ids: $ids));  // ['counter' => list<Akte>]
```

Edge behaviour is plain IN semantics either way (no new mechanics underneath): empty input → `[]` · duplicates → one Akte · missing keys/ids → partial result without error · no order guarantee — match per key (or `id`), which every Akte carries. `get{Agg}ByIds` keeps being emitted family-internally regardless of a unique key — only the outward (Außentür/OpenAPI) bulk-read surface and the list-item handle switch to the key when one exists.

**Recipe 8 — Sub-process node: calling another process synchronously**

A sub-process node is a **typed Dev-Stub** (`CreateIfNotExists` + `@node-id` body-preserve) — not a No-Op. The Generator emits the full skeleton on first build; subsequent builds preserve the developer body unchanged.

**Generated skeleton** (example: main process `CounterChange`, sub-process node `NotifyOps` calling `SendOpsNotification`):

```php
// {BC}/Process/CounterChange/Command/Handler/Action/NotifyOps.php
// @node-id 2a48bb04

protected function logic(WorkflowContextInterface $context): array
{
    $in = new SendOpsNotification(
        counterId: $this->counterId($context),  // werfender Resolver
        reason:    $this->reason($context),     // werfender Resolver
    );

    $res = $this->context(SendOpsNotificationHandler::class, $in)();

    $events = [];
    foreach ($res->getEvents(EventScope::Domain) as $subEvents) {
        foreach ($subEvents as $e) {
            $events[] = $e;
        }
    }

    return [
        'status' => $res->isSuccess() ? WorkflowResult::ON_SUCCESS : WorkflowResult::ON_FAIL,
        'data'   => [EventScope::Domain->value => $events],
    ];
}

// --- werfende Resolver (Dev füllt die Werte) ---

protected function counterId(WorkflowContextInterface $context): mixed
{
    throw new \RuntimeException('Sub-DTO-Feld counterId fuellen: ' . self::class);
}

protected function reason(WorkflowContextInterface $context): mixed
{
    throw new \RuntimeException('Sub-DTO-Feld reason fuellen: ' . self::class);
}
```

**What the developer does:** replace each werfenden Resolver with the real value from `$context` (e.g. `return $this->payload()->counterId;`). The `$in` constructor call, the `$res = $this->context(…)()` call, the status mapping, and the event bubbling are generated — do not touch them.

**Event bubbling (flat, Domain-scope only):** `EventScope::Domain` events from the sub-`DomainResponse` are collected flat into the `data` return array. The main-process orchestrator harvests them identically to events from any other node (`getChain()` → `$data[EventScope::Domain->value]` → `addEvent(…, Domain)`). `Internal` events of the sub-process stay sub-process-internal (they are not returned).

**Routing (`onFail`):** add an `onFail` edge from the sub-process node in the Process Designer — the node's status set is derived from the drawn edges (mechanics: [[beschreibt-bauweise-von::builder-generat-bauweise]] §6.1), so the `onFail` transition surfaces in the generated routing automatically. `onFail` = the sub-process run broke (exception or `InternalError` response); a fachliches Verdikt (true/false) is data and is routed via a downstream decision node.

**`subprocessOnly` flag:** a process that is only called as a sub-process (never directly via `$bc->process()`) should have `subprocessOnly: true` in its YAML (UI toggle „In API sichtbar", default ON). This suppresses the thin-dispatch method on the `{BC}Process` facade — the process DTO, orchestrator, and node stubs are always generated regardless of the flag.

**Rules:**
- Never `new` the Sub-Handler directly — always `$this->context(SubHandler::class, $in)()` (V2 / V3).
- Never return the `DomainResponse` object of the sub-call upstream — return the flat `['status' => …, 'data' => […]]` array (the orchestrator's harvest loop expects this shape).
- The sub-process node body survives rebuilds (unlike ForceOverwrite nodes) — keep the `@node-id` marker intact.

**Recipe 9 — Cross-BC write: translate → foreign `process()` → map response (G7)**

A Cross-BC-Call node whose target **mutates** state in a foreign BC must target that BC's **process**, never its aggregate — the Designer/Validator enforce this (`V-XBC-WRITE-TARGET`; the same rule is additionally sealed as a PHPStan boundary gate, [[beschreibt-bauweise-von::builder-generat-bauweise]] §2): `consumedCalls` for a foreign write offers only process methods of the target BC. Reads stay on the foreign `{Agg}Read` (unrestricted, no Prozess-Zwang). The generated Service (`{Domain}/Service/<Name>.php`, `platform-implementation` §1) is the ACL — it never passes the caller's DTO through unchanged.

```php
// {Domain}/Service/<Name>.php — generated scaffolding, __invoke() body is yours
final class CheckStockInCatalog extends EcommerceContext
{
    public function __invoke(WorkflowContextInterface $context): array
    {
        /** @var CrossBcServiceDemo $cmd */
        $cmd = $this->payload();

        /** @var Catalog $catalog */
        $catalog = $this->handle(Catalog::class);          // the foreign BC facade

        // 1) read the foreign Ist-Stand via the foreign READ facade
        $read = $catalog->product()->getProductByIdentifier(
            new QueryProductByIdentifier(identifier: $cmd->productIdentifier)
        );
        if (!$read->isSuccess()) {
            return ['status' => WorkflowResult::ON_FAIL, 'data' => ['productIdentifier' => $cmd->productIdentifier]];
        }
        $current = $read->getData()['GetProductByIdentifierHandler']['product'];

        // 2) translate the own input into the foreign PROCESS input DTO (ACL) —
        //    only the changed field is overridden, the rest mirrors the Ist-Stand
        $update = new UpdateProductInCatalog(new UpdateProduct(
            productIdentifier: $current->identifier,
            /* … remaining fields copied from $current … */
            price: $cmd->newPrice,
        ));

        // 3) write exclusively via the foreign process() — never the foreign aggregate
        $response = $catalog->process()->updateProductInCatalog($update);

        // 4) map the response back into the caller's own vocabulary (ACL) — never
        //    pass the foreign DTO through unchanged
        return [
            'status' => $response->isSuccess() ? WorkflowResult::ON_SUCCESS : WorkflowResult::ON_FAIL,
            'data'   => ['productIdentifier' => $current->identifier, 'newPrice' => $cmd->newPrice],
        ];
    }
}
```

Full reference implementation: `tests/Builder/Generated/Domain/Ecommerce/Service/CheckStockInCatalog.php` in the Builder repo. **Rules:** the foreign BC facade itself (`$this->handle({TargetBC}::class)`) is fine to hold — `product()`/`process()` are its own Außentür, not an internal hop; only the foreign **write** facade stays off-limits (V6-sibling). Same-BC writes stay on the Kernel-Naht (Recipe 3 Case A) — this recipe is only for a write into a **different** BC.

**Recipe 10 — Guard a Command with a business Rule (Rules-Layer)**

A Rule is a synchronous, endpoint-bound Ja/Nein-Wächter — for a bestand-check that must run before a Command, not for anything multi-step or side-effecting (that stays a Process). Declared in `Rules.yaml` (BC-level, sibling of Process/): a catalog entry (name, optional Policy reference) plus a binding (which Command, ordered chain, `expose` switch).

```php
// {BC}/Rule/CounterMustBeActive.php — DeveloperOwned, tag RuleClass
final class CounterMustBeActive extends MeterDeviceContext
{
    public function __invoke(UpdateCounter $cmd): RuleResult
    {
        /** @var Counter $counter */
        $counter = $this->handle(Counter::class);   // own BC only (M9: never a foreign BC) — here via the read facade; context() works too
        $read = $counter->counter()->getCounterByIdentifier(
            new QueryCounterByIdentifier(identifier: $cmd->identifier)
        );

        $akte = $read->getData()['counter'] ?? null;
        if ($akte === null || ($akte['status'] ?? null) !== 'active') {
            return RuleResult::reject(
                rule: self::class,
                messageKey: 'counter.must_be_active',
                context: ['identifier' => $cmd->identifier],
            );
        }

        return RuleResult::pass();
    }
}
```

**What's generated, what's yours:** the Generator emits the stub signature + the `Rule/Data/RuleResult.php` VO + a hermetic `Rule/Guard/GuardUpdateCounter.php` that runs the bound chain (AND, short-circuit) from inside the generated `UpdateCounterHandler` — you never call the Guard yourself, and you never wire the rejection into a response: a chain rejection surfaces as `ResponseStatus::RuleViolation` (422) with `{rule, messageKey, context}` automatically. Your only job is the `__invoke()` body above.

**Rule as a Process node:** the same catalog entry can additionally be dropped as a node in the Process Designer — a Katalog-Referenz (matrix-ineligible, like a sub-process node), the generated adapter maps `passed → ON_SUCCESS` / `rejected → ON_FAIL`. This is for an **early** check in a flow (before expensive work), not a replacement for the endpoint chain — binding the same Rule both at the endpoint and as a node in a process that calls that endpoint is flagged (M7, a build-time Warnung, not an Error: possible double-execution / inconsistent bestand-reads between the two runs).

**Rules:**
- Never `new` a Rule — always `$this->handle({Rule}::class)` (ClassVersion-fähig, `Rule/v{N}/`).
- Read bestand only from your **own** BC (V13/M9) — via that BC's read facade, or directly via the Kernel-Naht (`context()`) for a BC-internal read (e.g. the derived Selector) — a cross-BC bestand-check is Prozess-Territorium, not a Rule.
- A Rule never throws to reject — `RuleResult::reject(...)` is data, not an exception. Only let a genuinely technical failure (DB down) propagate as an exception (→ 500), never mis-signal it as a 422 by wrapping it in `reject()`.
- A freshly generated, **not-yet-implemented** stub throws too — but for the opposite reason: the emitted body is `throw new \RuntimeException('Not implemented: write the rule predicate for ' . self::class)`, not `RuleResult::pass()` (G03, `wissensbasis/stub-ausfallphilosophie.md`). An unfinished Rule fails loud (500) instead of silently letting every Command through — implement `__invoke()` before binding it live.
- Versioning a Rule (`Rule/v2/`) may **tighten** the accepted set, but must keep the payload shape + `messageKey` stable — that's the contract callers (and i18n) depend on (M5, `platform-versioning`).
- TOCTOU is a known v1 boundary (`platform-implementation` §7) — a concurrent write between the Rule's read and the Command's persist is not locked against. Harden with a DB constraint if the invariant is truly hard.

**Recipe 11 — Invariante als Zustand: eine Eindeutigkeits-Invariante über Prozessgrenzen sichern**

Ein Decision-Knoten, der per Query auf Abwesenheit prüft ("gibt es schon eine Rechnung für diese
Bestellung?") und danach schreibt, ist Check-then-Act — racy unter Nebenläufigkeit, zwei
gleichzeitige Läufe können beide die Prüfung bestehen. Die Ablösung: die Eindeutigkeit wird nicht
geprüft, sondern **als Zustand einer existierenden Zeile modelliert**, die per bedingtem Schreiben
(CAS-UPDATE, `platform-implementation` Persist-Layer emittiert `$expected` = alte Werte der
geänderten Felder automatisch) umgesetzt wird. Zwei gleichzeitige Läufe können nie beide gewinnen
— kein neuer Mechanismus, keine Sperrtabelle, kein DB-UNIQUE-Regelträger.

**Grundregel:** der Torwächter (der Knoten mit dem bedingten Schreiben) ist der **erste
schreibende Knoten** des Prozesses. Vor ihm darf nichts Ungewolltes geschrieben worden sein — der
Savepoint (siehe Konflikt-Kaskade unten) sichert danach nur noch die Atomarität des abgebrochenen
Einzel-Persists, nicht die Reihenfolge.

**Fall A — Torwächter/Statusaggregat** (z. B. "eine Rechnung je Bestellung"). Ein eigenes
Zustandsaggregat trägt ein Flag (`fakturiert: bool`). Die Zeile entsteht **vorab** (z. B. per
Domain-Event bei "Bestellung ausgeliefert"), nicht erst beim Fakturieren selbst — sonst kehrt das
Rennen als Erstanlage-Rennen zurück. Prozessablauf:

```
K1 „MarkInvoiced"          — bedingtes UPDATE fakturiert: false → true
   ON_SUCCESS → K2 „CreateCustomerInvoice"   (unbedingter INSERT)
   ON_FAIL    → deklarierte 409-Kante (Reject-Terminal)
```

Ein konvergenter Abweis — mehrere fachlich verschiedene Vorknoten (nicht gefunden / bereits
fakturiert / Validierung) münden auf denselben Reject-Terminal-Knoten — ist der **Normalfall**,
kein Sonderfall, und braucht keine eigene Behandlung.

**Fall B — Nummernkreis/Reservierung mit Retry** (das System vergibt den Schlüssel). Ein kleines
Zähler-Aggregat (`lastNumber: int`):

```
K1 „ReserveInvoiceNumber"  — bedingtes UPDATE lastNumber: n → n+1
   ON_SUCCESS → K2 nutzt den neuen Wert (Weitergabe via WorkflowContext::getLatest() im
                Knoten-Body — es gibt dafür keinen deklarativen Weg im Korpus, Dev-Fläche)
   ON_FAIL    → 409
```

**Retry ist Komfort, nicht Korrektheit** und braucht zwei Pflichten: eine **Obergrenze**
(Versuchszähler, die Engine hat keine eingebaute Schleifenbremse) und — der belegte Fehler dieser
Klasse — der Zielwert MUSS **pro Versuch neu berechnet** werden, aus dem frisch gelesenen
`current`-Stand. Ein fest verdrahteter Zielwert (`lastNumber: 1` bei jedem Versuch) erzeugt beim
zweiten Versuch ein No-Op-UPDATE, dessen `rowCount()`-Interpretation treiberabhängig unterschiedlich
ausfällt (MySQL liest es zufällig richtig als Konflikt, Postgres fälschlich als Erfolg → Doppel-
vergabe). Details, Ursache und Package-Folgeposten: `wissensbasis/rowcount-cas-ist-treiberabhaengig.md`.

**Statusverhalten am Prozessende.** Ein Torwächter-Konflikt muss als 409 nach außen sichtbar
werden, nicht nur intern routen — der zweistufige Kettenscan, der das leistet (nur der Status der
letzten Ausführung je Knoten-Identität zählt, damit ein beim Retry gelingender Torwächter seinen
frühen Fehlschlag nicht mehr in den Antwortstatus trägt): [[beschreibt-bauweise-von::builder-generat-bauweise]]
§5.4. Ohne diese Verfeinerung meldet ein geheilter Retry fälschlich 409 trotz tatsächlichem Erfolg
samt Seiteneffekt (belegt, Postgres-Nummernkreis, s. u.).

Das ist die Drei-Ebenen-Trennung aus `platform-workflow` §1: **Verzweigung** (ON_SUCCESS/ON_FAIL
= true/false, reine Wegwahl) · **Antwort-Status** (immer aus der tatsächlichen `DomainResponse`
des zuletzt maßgeblichen Knotens, NIE aus der Kanten-Deklaration) · **Transaktion** (der Nein-Pfad
committet weiter — ein deklarierter 409-Terminal ist kein Rollback-Grund, nur ein geworfener
technischer Fehler rollt zurück).

**Kollisionsverlauf (Fall A):** beide Läufe laden `fakturiert=false`. Der erste K1 gewinnt. Der
zweite K1 wartet an der Zeilensperre, wertet nach dem Warten den aktuellen Stand neu (das UPDATE
ist ein Current-Read), trifft 0 Zeilen → 409 → K2 wird nie erreicht. Die Klammer rollt dabei
NICHT zurück, sie committet leer — der Nein-Pfad ist ein legitimer Abschluss, kein Abbruch.

**Grenzen:**

- **Nur über `runInTransaction`-Prozesse.** Der direkte BC-Weg (Command ohne Prozess) bleibt
  ungeschützt.
- **SQLite:** ohne `busy_timeout` wartet SQLite nicht an der Sperre, sondern wirft sofort
  `SQLITE_BUSY` — der Verlierer endet über den Throwable-Pfad als **500 mit Rollback**, nicht als
  409. Die Invariante hält (er schreibt nichts), aber Status und Warteverhalten sind falsch.
  Bekannter eigener Posten am dbConnection-Adapter, hier nicht gelöst.
- **Retry heilt unter MySQL `REPEATABLE READ` innerhalb derselben Transaktion strukturell nie** —
  jeder Snapshot des Verlierers sieht denselben `current` wie sein erster Read, nie den
  zwischenzeitlich committeten Gewinner-Wert. Unter Postgres `READ COMMITTED` sieht der Retry nach
  dem Warten den committeten Stand und kann gewinnen. Beide Bilder sind korrekt — ein reales
  Treiber-Delta, kein Bug.
- **rowCount()-Falle als Warnung:** der generierte CAS-Persist-Layer prüft Erfolg über
  `rowCount() > 0` — treiberabhängig unzuverlässig bei No-Op-Updates (Details:
  `wissensbasis/rowcount-cas-ist-treiberabhaengig.md`). Betrifft jeden Torwächter, dessen Zielwert
  zufällig mit dem Ist-Zustand übereinstimmen kann — bei Fall A (bool-Flag) ebenso relevant wie bei
  Fall B (Zähler).

**Event-Erstanlage bleibt Konzept, kein fertiger Weg.** Die Statuszeile in Fall A soll idealerweise
per Domain-Event entstehen ("Bestellung ausgeliefert" → Zeile anlegen), aber der generierte
`<Agg>EventRouter.php` ist ein reiner Registrierungs-Stub (§1 oben) — die Zustellung über einen
echten Transport (Kafka/HTTP/in-process, §1) ist offenes Wiring, kein fertiger Baustein. Bis dahin:
Erstanlage per Fixture-Seed / einmaligem Migrations-Schritt, dokumentiert als bewusste Lücke, nicht
stillschweigend übergangen.

**Den realen Prozess umstellen.** Ein bestehender Check-then-Act-Decision-Knoten (liest per Query
auf Abwesenheit) wird im Designer abgelöst, nicht daneben gebaut: das Zustandsaggregat modellieren
(neue Tabelle, ein Flag- oder Zähler-Feld), den Torwächter-Knoten als ersten schreibenden Knoten
vor die bisherige Schreiblogik setzen, die racy Lese-Prüfung entfernen, die 409-Kante als Terminal
deklarieren. Die fachliche Vorprüfung ("ausgeliefert?", "gibt es die Bestellung überhaupt?") bleibt
als Lese-Decision VOR dem Torwächter bestehen — nur die Eindeutigkeits-Hälfte wandert in den
Torwächter.

### 3. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `LogicException: Cannot resolve ClassName` | BC vs. Aggregate segment swapped in namespace | Namespace is `<Domain>\<BC>\Aggregate\<Agg>\…` — BC and Aggregate are two segments even when they share a name (the `Aggregate/` segment sits between them) |
| Edit under `{BC}/Aggregate/{Agg}/` gone after rebuild | The **whole** aggregate tree is hermetic (ForceOverwrite, V1) — every build truncates and rewrites it; there is no override slot inside it | Move the behaviour to a **Process** (`{BC}/Process/<Name>/Command/Handler/Action/`), re-model the aggregate in the Designer, or (tenant variant) author a `v{N}/<Class>.php` next to the baseline (`platform-versioning` §1) |
| `on<Event>()` edit gone after rebuild | Bodies were filled in the hermetic `<Agg>EventRouter.php` | Never edit the router — author transport in a **Process node** that publishes `$response->getEvents()` after the aggregate command (§1) |
| Versioned override (`v2/…`) ignored | The call isn't passing `'v2'` as `$version`, or a needed `ClassVersionConfig` fallback entry is missing | Thread the version through the call (`$bc->{agg}()->getCounterById($dto, 'v2')` for reads, or `$this->handle(Counter::class)->createCounter($dto, 'v2')` family-internally for writes) — there is **no** domain-wide `version()` default to set instead, the per-call argument is the only lever; the variant must sit at `{Agg}/…/v2/<Class>.php` (immediate neighbour of the baseline) — `platform-versioning` §1 |
| Process node body lost after rebuild | The custom node lost its `@node-id` marker, or the file had broken syntax so the body-preserve merger could not parse it | Keep the generated `@node-id` DocBlock marker intact; fix the parse error. The merger regenerates the node *header* but preserves the body keyed by `@node-id`. (The Designer's "Force" build path deliberately overwrites a node body.) |
| Process node not invoked though it's in the graph | R5-Routing-Safety: the node isn't registered via `addNode()`, or the returned `ON_*` status has no transition in the current node | Every handler referenced in `->onSuccess()/onFail()/…` must be declared as its own `->node(...)`; add the missing status to the routing — `platform-workflow` §5 |
| `Error: Cannot instantiate abstract class` / "Service X not in container" | Direct `new` bypassing `handle()` (V2 / V3) | Replace with `$this->handle(X::class, ...)` from inside the node |
| `Cannot import OtherBC\...` review blocker | V6 violation (cross-BC import) | Add a Domain Service in the Process scope and call the other BC via `handle()` |
| Process tries to extend an aggregate DTO (`extends platform:…`) | Removed feature — a process input is self-contained (Recipe 5) | Declare the fields the process needs in `input.fields`; fetch aggregate data by running its query from a node |
| `getData()` empty after `addData()` in a node | The node returned before augmenting `$this->result()`, or replaced the payload | Read aggregate data, then `addData(...)`/`setData(...)`, then return |
| Process node throws → whole process fails | Default: an uncaught node exception routes to `ON_FAIL` (or bubbles to 500 if unrouted) | Wrap the node body in `try/catch` only if its failure must not fail the process; otherwise add the `ON_FAIL` transition — §1, `platform-workflow` §5 |
| Sub-process node body overwritten after rebuild | Sub-process node lost its `@node-id` marker, or the file was built with an older Generator version (formerly ForceOverwrite No-Op) | Keep the `@node-id` DocBlock marker intact; if the file is an old No-Op, delete it — the next build emits the typed Dev-Stub fresh (Recipe 8) |
| Sub-process werfender Resolver throws at runtime | Expected — the resolver is a placeholder until the developer fills in the real value from `$context` | Replace `throw new \RuntimeException(…)` in each resolver with the real value (e.g. `return $this->payload()->counterId;`) |
| Sub-process `Domain` events missing in main response | The sub-process node returned `Internal` events under `EventScope::Domain->value` by mistake, or the orchestrator loop wasn't updated | The stub returns `[EventScope::Domain->value => $events]`; the orchestrator harvests `$data[EventScope::Domain->value]` — both use the enum value string; check that `EventScope` is imported in both files |
| Process doesn't appear on `$bc->process()` facade | `subprocessOnly: true` is set — by design | The process is only callable as a sub-process node; use `$this->context(Handler::class, $dto)()` from another node; or unset the flag if the process should also be a public API entry |
| Rule body edit gone after rebuild | Byte-for-byte matched an untouched generated stub (wholesale-migration path) — false-positive risk is a known, documented trade-off of the merge's exact-match check | Make a real edit (any content change) — the merger then treats the method as hand-edited and keeps it 100% verbatim on every future rebuild |
| Rule stub throws `RuntimeException: Not implemented: write the rule predicate for …` | Expected — a freshly generated, not-yet-implemented Rule predicate throws instead of failing open with `RuleResult::pass()` (G03, `wissensbasis/stub-ausfallphilosophie.md`); a Guard-Closure never wraps its Rule dispatch in try/catch, so it propagates uncaught and surfaces through the generated Command handler's generic `catch (\Throwable $e)` as a 500, never the 422 a bound Rule is meant to produce | Implement `__invoke()`: return `RuleResult::pass()` / `RuleResult::reject(...)` per your bestand-check |
| Command rejects with 422 but I expected the Command to just run | A bound Rule in `Rules.yaml` returned `RuleResult::reject(...)` — check `data.rule`/`data.messageKey`/`data.context` in the response | Expected behaviour, not a bug — either the bestand genuinely fails the Rule, or the binding/chain in `Rules.yaml` is wrong for this Command |
| `expose: true` binding fails the build | The Command has zero bound Rules (B3 — exposed endpoints must be rule-guarded), or it's a Create-Command (name always collides with `{agg}()`, structurally never exposable) | Bind ≥1 Rule before exposing; Create-Commands stay reachable only via a Process |
| Command-calling Process node throws instead of routing `ON_FAIL` on a 500 | Intentional staircase semantics: `422 → ON_FAIL`, `5xx → exception path` — never a blanket `isSuccess() ? ON_SUCCESS : ON_FAIL` | Not a regression — add the `onFail` edge for the 422 case; a genuine 5xx is meant to surface as an exception, handle it like any other node exception (`platform-workflow` §5) |
| M7 warning ("doppelt gebunden") on a Rule node | The same Rule is bound both at the endpoint (`Rules.yaml`) and as a node in a process calling that endpoint | Usually fine (early-check pattern) — only a problem if the two runs can see inconsistent bestand between them; drop the node binding if redundant |

### Anchors

- `platform-implementation` (hermetic aggregate layout, the customization surfaces incl. the `{BC}/Rule/` catalog, prohibitions incl. M9/V13, decision tree, TOCTOU boundary).
- `platform-versioning` (ClassVersion resolution — `LoadClassFromSubDirectory`, per-class `v{N}`).
- `platform-workflow` (Workflow-Engine API used by the Process orchestrators + node routing referenced above).
- `adapter-messaging`, `adapter-http`, `adapter-eventdispatcher` (event-transport recipes).
- `rules-testing` (EventCollector fake for Recipe 4).
