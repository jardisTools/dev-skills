---
name: generated-code-recipes
description: Phase-3 recipes and troubleshooting for Designer-generated code — Event transport from a Process node (Kafka/RabbitMQ/Redis/HTTP-webhook/in-process; the generated `<Agg>EventRouter.php` is hermetic), VO in a Process node, Domain Service, new aggregate op vs. new Process, self-contained Process input, Response shapes per operation, bulk read list→ids→`get{Agg}ByIds` (or, with a unique key, list→keys→`get{Agg}By{PluralKey}`), sub-process node, cross-BC write (DTO-translation → foreign `process()` → response-mapping), guard a Command with a business Rule (Rules-Layer), Invariant as state (uniqueness invariant via a first-writing gatekeeper node + CAS-UPDATE instead of check-then-act, status aggregate/invoicing and number range/reservation-with-retry cases), troubleshooting table (ClassVersion misses, hermetic-tree edits lost, `@node-id` body-preserve, routing-safety, cross-BC, listener exceptions, Rule-merge/422 pitfalls).
zone: post-active
persona: C
profile: jardis
prerequisites: [generated-code-extend]
next: []
---

> **Layout context.** The aggregate tree `{BC}/Model/{Agg}/` (abbreviated `{Agg}/` in this skill) is hermetic — ForceOverwrite, never edited (V1); developer code lands only in the BC-level Process scope `{BC}/Process/{Name}/`, reached via `$bc->process()`. `$bc->{agg}()` reaches only the read facade `{Agg}Read` (the BC facade's public surface); the write facade `{Agg}` is family-internal via the kernel seam `$this->handle({Agg}::class)`. Canonical layout + vocabulary glossary (aggregate facade / read facade / public surface / kernel seam / Context family): `generated-code-extend` §1–§2.

### 1. Event transport — authored in a Process node

The generator emits the base router at `{Agg}/Event/<Agg>EventRouter.php` (namespace `<Domain>\<BC>\Model\<Agg>\Event`, ForceOverwrite). It is a plain `class <Agg>EventRouter` carrying one **empty** `protected function on<Event>(EventListenerRegistryInterface $registry): void` stub per Domain Event (body `// configure transport`), each preceded by a channel-key comment (`{ChannelPrefix}.{ChannelSuffix}`) as a topic / routing-key suggestion. The Domain facade wires it directly into `eventDispatcher()`:

```php
protected function eventDispatcher(): EventDispatcherInterface|false|null
{
    return (new EventDispatcherHandler())(
        new CounterEventRouter()
    );
}
```

**The router is hermetic** (ForceOverwrite) — you never fill its bodies; the next build truncates them (V1). Transport is therefore authored where developer code is allowed: a **Process node**. The aggregate command records its events on its response (`$this->result()->addEvent($event, EventScope::Internal)` in the generated handler) — the handler **only collects, it does not dispatch**. Note `getEvents(?EventScope)` is **context-keyed** (`array<string, array<int, object>>`), so flatten it before iterating: `array_merge(...array_values($response->getEvents()))` (or a nested `foreach`). (Aggregate life-cycle events are `Internal`; a **Domain event** is announced by an **Event ◇ node** of the Process.)

**What a node sees of the previous node.** `$context->getPrevious()->getData()` / `$context->getLatest(NodeFqcn::class)->getData()` is the previous node's **flat business payload** — an `array`, never a `DomainResponse`, and with no handler-name or root level around it. For the generated default body of an aggregate-call node that is the command's reference value, e.g. `['@type' => 'counter', 'counterIdentifier' => '018e…']` — read it flat: `$context->getLatest(PersistCounter::class)->getData()['counterIdentifier']`. Never read it with `?? null`: a misspelt key then yields a silent `null` instead of a failure (the generated Event node throws when the identity is missing). The node's `DomainResponse` and its `Internal` events are **not** forwarded by the default body.

**Two ways events leave a Process:**

1. **Event ◇ node (generated, no body to write).** The node's `logic()` returns the event object(s) in the reserved channel `data['__jardis']['events']`. The generated orchestrator hands the executed chain to the hermetic `{Domain}\Response\ResolveProcessOutcome` closure, which harvests only that channel and the orchestrator collects each event on the response with `addEvent($event, EventScope::Domain)`. The **caller** publishes after the commit, from `$response->getEvents(EventScope::Domain)`. The identity of the event is read flat from the preceding node: `getLatest(PersistHeartbeat::class)->getData()['heartbeatSignalId'] ?? throw …`.
2. **Custom node that publishes itself (below).** The node calls the aggregate command through the kernel seam, so it holds that `DomainResponse` and its events in hand, and hands them to a transport via `handle()`.

`__jardis` is a **reserved key** of a node's `data` (`{status, errors, events}`, written by the generated bodies; `ResolveProcessOutcome` reads it and strips it from the answer) — do not use it for business fields, and do not put events anywhere else in `data`.

**Event payload identity (X-3).** `<Agg>{ChildEntity}Added` events carry the affected child's business identifier (`<childIdentifier>`) when G4 is satisfied — otherwise the internal PK (same rule as the Command response, see Recipe 6). Wire your listener against the business key whenever you can; the internal PK is meaningful only inside the aggregate and changes on rebuild scenarios where data is reseeded.

**Publish from a Process node** (`{BC}/Process/<Name>/Command/Handler/Action/<NodeClass>.php`, `extends <Domain>Context`). The node calls the aggregate command itself (kernel seam), takes the command's events off **that** response and publishes them. The reusable part is the `handle()` call into the transport package — never `new` a publisher/client (V2/V3).

**Kafka / RabbitMQ / Redis** via `jardisadapter/messaging`:

```php
public function __invoke(WorkflowContextInterface $context): WorkflowResultInterface
{
    /** @var CreateCounter $cmd */
    $cmd = $this->payload();

    /** @var DomainResponseInterface $response */
    $response = $this->handle(Counter::class)->createCounter($cmd->create);   // illustrative: `create` = the process-input field carrying the command DTO; the response is held here, not read from a previous node

    foreach (array_merge(...array_values($response->getEvents())) as $event) {
        $this->handle(MessagingService::class)
            ->publish('meterdevice.counter.counter.created', $event);
    }

    return new WorkflowResult(WorkflowResult::ON_SUCCESS, $response->getData());   // flat reference payload, the Process answers with it
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
- A throwing node flips the process response per its `ON_FAIL` routing (`generated-code-workflow-api` §5). Wrap `try/catch` inside the node only if "event delivery must not fail the process".
- **Tenant / feature-flag transport variant:** because ClassVersion can resolve any generated class, a `v{N}/Event/<Agg>EventRouter.php` next to the baseline is the escape hatch for a per-version router — but version *creation* is out of scope; prefer the Process node (`generated-code-versioning` §1).

### 2. Phase-3 cookbook

Paths assume BC `MeterDevice\Counter`, aggregate `Counter`. Aggregate code lives under `{BC}/Model/{Agg}/` (hermetic, never edited); **all developer code lands under `{BC}/Process/<Name>/`** — node bodies in `Command/Handler/Action/` (`@node-id` body-preserve), VOs in `ValueObject/`, Domain Services in `Service/`, optional reads in `Query/`, repos in `Repository/`.

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

`handle()` constructs the VO (V2/V3 — never `new`). A bad OBIS throws and the node's `ON_FAIL` transition takes over. For a tenant-specific variant of any generated class, see the `v{N}/` escape hatch (`generated-code-versioning` §1).

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

**Case A — a new aggregate command or query** (mutates/reads this one aggregate's state). Re-model it in the **Aggregate Designer** (`Aggregate.json`) and rebuild. The Generator regenerates the Command/Query DTO + handler + validator tree and exposes the operation **inline on the matching facade** — a query on the read facade `{Agg}Read.php` (the BC facade's public surface), a command on the write facade `{Agg}.php` (family-internal only):

```php
$bc->counter()->getCounterByIdentifier($dto);               // query — generated, reached via $bc->{agg}() (public surface)
$this->handle(Counter::class)->deactivateCounter($dto);     // command — generated, family-internal via the kernel seam
```

No method is hand-written and nothing under `{Agg}/` is edited — both facades are fully regenerated (no `@flow-id`, no body merge). A new command has **no** external caller by itself (G6, the aggregate is not writable from outside without a Process) — wrap it in a Process (Case B) to expose it via `$bc->process()`.

**Case B — a BC-level operation or one that coordinates several aggregates.** Model a **Process** in the Process Designer. The Generator emits, under `{BC}/Process/<Name>/`:

1. `Command/<Name>.php` — the self-contained input DTO (`readonly`, its own `input.fields` — Recipe 5). Namespace: `…\Process\<Name>\Command\<Name>`.
2. `Command/Handler/<Name>Handler.php` — the Workflow orchestrator (`__invoke()` + `config()` building the full graph). ForceOverwrite — regenerated, not edited. Namespace: `…\Process\<Name>\Command\Handler\<Name>Handler`.
3. `Command/Handler/Action/<NodeClass>.php` — one node stub per node. Custom nodes are `CreateIfNotExists` with an `@node-id` marker; **the body is yours** and survives rebuilds. Namespace: `…\Process\<Name>\Command\Handler\Action\<NodeClass>`.
4. A thin-dispatch method on the hermetic `{BC}Process` facade: `return $this->context(<Name>Handler::class, $in, $version)();` — reached via `$bc->process()->{name}($dto)`.

The segments `Query/`, `Repository/`, and `Service/` are **not emitted** by the Generator — they are developer-authored as needed (AI-/Dev-owned).

Your logic lives in the custom-node bodies. The process has **no aggregate ownership** and invokes whichever aggregate commands/queries it needs via `$this->context(...)` / `$this->handle(...)` from a node.

**Recipe 4 — Event to Kafka (end-to-end)**

A concrete instance of §1, way 2. Model a Process whose node invokes the aggregate command and publishes its events in the same node body:

```php
// {BC}/Process/CreateCounter/Command/Handler/Action/CreateAndPublish.php
public function __invoke(WorkflowContextInterface $context): WorkflowResultInterface
{
    /** @var CreateCounter $cmd */
    $cmd = $this->payload();

    /** @var DomainResponseInterface $response */
    $response = $this->handle(Counter::class)->createCounter($cmd->create);   // held in this node

    foreach (array_merge(...array_values($response->getEvents())) as $event) {
        $this->handle(MessagingService::class)
            ->publish('meterdevice.counter.counter.created', $event);
    }

    return new WorkflowResult(WorkflowResult::ON_SUCCESS, $response->getData());
}
```

Test via the `EventCollector` fake (see `foundation-testing` §6) plus a `MessagingService` fake asserting the published payload. Never edit the hermetic `<Agg>EventRouter` (§1).

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

The Generator emits a fixed, minimal `setData(...)` payload per use-case kind — never the full aggregate (CQRS: Command mutates with a reference-value echo, Query reads with the projected graph). **`data` is flat:** the payload IS the business object and names its own type in the field `@type` — there is no handler-name level and no aggregate-root level around it, so `getData()['identifier']`, never `getData()['GetCounterByIdHandler']['counter']['identifier']`. Layout below for `Counter` (root business key named `counterIdentifier` in the generated code of the example domain; the key names follow the aggregate's business key).

| Use-case kind | Generated `setData(...)` shape (`@type`) |
|---|---|
| **Query ById / By{UniqueKey}** (and hand-modelled single variants) | `['@type' => 'counter'] + <projected record fields>` — one projected nested scalar tree, flat (see Query projection below); nothing found → `[]` (empty payload, no `@type`) |
| **Query ByIds** / **By{PluralKey}** (the latter only on an aggregate with a public unique key) | `['@type' => 'counterList', 'items' => <list<AggregateRecord>>]` — **no `[0]` collapse**, no `total`/`limit`/`offset`; missing ids/keys → partial result, empty input → empty `items` |
| **List** (`{agg}List`, via the read facade) | a plain array `['items' => …, 'total' => …, 'limit' => …, 'offset' => …]` (not a `DomainResponse`); the generated route wraps it as `['@type' => 'counterList'] + $result` |
| **Create** | `['@type' => 'counter', 'counterIdentifier' => …]` — root business key only |
| **Set{Child}** / **Add{Child}** | `['@type' => 'counter', 'counterIdentifier' => …, '<childIdentifier>' => …]` — root key from the cmd-DTO + affected child key resolved via the aggregate walk |
| **Update** / **Remove** / **Remove{Child}** | `['@type' => 'counter', 'counterIdentifier' => …]` — root identity only (Remove{Child} skips the child key) |
| **Process** | `['@type' => '<aggregate of the success command>'] + <flat payload of the success node>`; a Process without one unambiguous static success exit answers `['@type' => '<processName>']` (lcfirst process name) without a reference value (the build warns); status **200**, never 201 |
| **400 / 409 / 422** | `['@type' => 'validation', 'fields' => [{field, reason, message}]]` / `['@type' => 'concurrencyConflict', 'reason', 'aggregate', 'context']` / `['@type' => 'ruleViolation', 'rule', 'messageKey', 'context']` |

Exactly one context of a response may carry data; the generated `DomainResponseTransformer` takes that payload as `data` and throws a `LogicException` when two contexts carry data — it never merges silently.

**`Remove{Child}` does not exist for every child.** A `Remove{Child}` command is only emitted when the child may actually be detached — a DEPEND leaf whose depend-FK is NOT NULL, and a `required` containment child, both get **no** remove mutation at all (rule and rationale: `generated-code-extend` §1). If the row above has no counterpart in your generated aggregate, that is the rule, not a gap.

Substitute the actual root identifier name (e.g. `counterId`, `meterNumber`) where the aggregate uses a different business key. Child responses use the child's business identifier (`<childIdentifier>`, e.g. `counterGatewayId`).

**Business-key resolution (G4 / X-2).** The Generator picks the root identifier by walking the entity for a Single-Column-Unique-Index on a NOT-NULL `string` column. If exactly one such column exists, that is the business key and surfaces in the response. If none exists, the response falls back to the internal `int` PK property (e.g. `counterGatewayId: int` for a keyless `counterGateway` child — not a defect, the only available identity). If multiple ambiguous candidates exist (X-2: two NOT-NULL-unique-string columns), the Build aborts — model an explicit single business key in the Schema instead of letting the response shape become non-deterministic.

**Query projection.** The Generator emits per BC a `{BC}/FieldMap.php` (ForceOverwrite — a pure naming container with one `{table}Columns()` method per BC table, the write-path DTO→column map; there is no `Fields()` method). The **read** projection (internal-PK strip where a business key exists, G4; root-id normalization; FK-column strip; pure-join collapse, F3.1) is aggregate-structural and runs at the **aggregate read edge (the query handler)**, not in FieldMap. The projected aggregate record therefore always carries the root id — the internal handle the ById/ByIds read base relies on; child entities stay id-free. This concerns the projected aggregate record only — the auto-**list** SELECT is not uniform: on an aggregate WITH a public unique key it leads with that key column `AS {keyField}` instead of `id` (the outward, key-first bulk-read recipe, Recipe 7 below); an aggregate WITHOUT one leads its list with `id`. `DateTimeImmutable` blade values stay inert — JSON/CLI serialization is the caller's job (G5).

**Command response** never carries domain state — only the reference value(s) the caller needs to address what just changed (event-sourcing / correlation). For the full state after a write, the caller issues the matching read-base query — `get{Agg}By{UniqueKey}` with the echoed business key, or `get{Agg}ById` (CQRS).

**Adding a custom field.** The `setData(...)` block sits inside the generated, hermetic operation `__invoke()` body — you do not edit it (V1). To enrich a Process answer, add fields to the flat payload your success node returns (`'data' => $response->getData() + ['extra' => $value]` — Decorator at process level, see `generated-code-extend` §4); the orchestrator puts `@type` in front and answers with it. Do not use the keys `@type` or `__jardis` for business fields.

**Recipe 7 — Bulk read: list → keys/ids → full aggregate records**

Every aggregate facade carries the uniform read base `get{Agg}ById` / `get{Agg}ByIds` / `get{Agg}By{UniqueKey}` (catalog: `generated-code-extend` §1). An aggregate WITH a public unique key additionally carries the bulk variant `get{Agg}By{PluralKey}` (e.g. `getOrderByOrderNumbers`), and its auto-list items lead with that key column instead of `id` — so the recipe forks:

An aggregate **WITH** a unique key — list → keys → `get{Agg}By{PluralKey}`:

```php
$list  = $bc->order()->orderList($filter);                                             // filtered flat list: plain array {items, total, limit, offset}
$keys  = array_values(array_unique(array_column($list['items'], 'orderNumber')));
$records = $bc->order()->getOrderByOrderNumbers(new QueryOrderByOrderNumbers(orderNumbers: $keys))->getData()['items']; // list<AggregateRecord>, data = {@type: orderList, items}
```

An aggregate **WITHOUT** a unique key — list → ids → `get{Agg}ByIds`:

```php
$list  = $bc->counter()->counterList($filter);                               // filtered flat list
$ids   = array_values(array_unique(array_column($list['items'], 'id')));
$records = $bc->counter()->getCounterByIds(new QueryCounterByIds(ids: $ids))->getData()['items'];  // list<AggregateRecord>, data = {@type: counterList, items}
```

Edge behaviour is plain IN semantics either way: empty input → `[]` · duplicates → one aggregate record · missing keys/ids → partial result without error · no order guarantee — match per key (or `id`), which every aggregate record carries. `get{Agg}ByIds` keeps being emitted family-internally regardless of a unique key — only the outward (public surface/OpenAPI) bulk-read surface and the list-item handle switch to the key when one exists.

**Recipe 8 — Sub-process node: calling another process synchronously**

A sub-process node is a **typed Dev-Stub** (`CreateIfNotExists` + `@node-id` body-preserve) — not a No-Op. The Generator emits the full skeleton on first build; subsequent builds preserve the developer body unchanged.

**Generated skeleton** (example: main process `CounterChange`, sub-process node `NotifyOps` calling `SendOpsNotification`):

```php
// {BC}/Process/CounterChange/Command/Handler/Action/NotifyOps.php
// @node-id 2a48bb04

protected function logic(WorkflowContextInterface $context): array
{
    $in = new SendOpsNotification(
        counterId: $this->counterId($context),  // throwing resolver
        reason:    $this->reason($context),     // throwing resolver
    );

    $res = $this->context(SendOpsNotificationHandler::class, $in)();

    if ($res->getStatus() >= ResponseStatus::InternalError->value) {
        throw new \RuntimeException(sprintf('Technischer Fehler im Sub-Prozess SendOpsNotification (Status %d).', $res->getStatus()));
    }

    $events = [];
    foreach ($res->getEvents(EventScope::Domain) as $subEvents) {
        foreach ($subEvents as $e) {
            $events[] = $e;
        }
    }

    return [
        'status' => $res->isSuccess() ? WorkflowResult::ON_SUCCESS : WorkflowResult::ON_FAIL,
        'data'   => ['__jardis' => ['events' => $events]],   // reserved channel, harvested by ResolveProcessOutcome
        'responseStatus' => $res->getStatus(),
        'errors' => array_merge(...array_values($res->getErrors())),
    ];
}

// --- throwing resolvers (dev fills in the values) ---

protected function counterId(WorkflowContextInterface $context): mixed
{
    throw new \RuntimeException('Sub-DTO-Feld counterId fuellen: ' . self::class);
}

protected function reason(WorkflowContextInterface $context): mixed
{
    throw new \RuntimeException('Sub-DTO-Feld reason fuellen: ' . self::class);
}
```

**What the developer does:** replace each throwing resolver with the real value from `$context` (e.g. `return $this->payload()->counterId;`). The `$in` constructor call, the `$res = $this->context(…)()` call, the status mapping, and the event bubbling are generated — do not touch them.

**Event bubbling (flat, Domain-scope only):** `EventScope::Domain` events from the sub-`DomainResponse` are collected flat into the reserved channel `data['__jardis']['events']`. The main-process orchestrator harvests them identically to events from any other node: it hands `getChain()` to the generated `ResolveProcessOutcome`, which reads only that channel, and adds each event with `addEvent(…, EventScope::Domain)`. `Internal` events of the sub-process stay sub-process-internal (they are not returned). The generated `__invoke` of the node additionally moves the `responseStatus` and `errors` keys of the `logic()` return into the same channel (`data['__jardis']['status'|'errors']`) — you never write those into `data` yourself.

**Routing (`onFail`):** add an `onFail` edge from the sub-process node in the Process Designer — the node's status set is derived from the drawn edges, so the `onFail` transition surfaces in the generated routing automatically. `onFail` = the sub-process run broke (exception or `InternalError` response); a business verdict (true/false) is data and is routed via a downstream decision node.

**`subprocessOnly` flag:** a process that is only called as a sub-process (never directly via `$bc->process()`) should have `subprocessOnly: true` in its definition (UI toggle "In API sichtbar" (visible in API), default ON). This suppresses the thin-dispatch method on the `{BC}Process` facade — the process DTO, orchestrator, and node stubs are always generated regardless of the flag.

**Rules:**
- Never `new` the Sub-Handler directly — always `$this->context(SubHandler::class, $in)()` (V2 / V3).
- Never return the `DomainResponse` object of the sub-call upstream — return the flat `['status' => …, 'data' => […]]` array. `data` is the node's flat business payload plus, where needed, the reserved channel `__jardis` (`{status, errors, events}`); the generated `ResolveProcessOutcome` reads the channel and strips it from the answer.
- The sub-process node body survives rebuilds (unlike ForceOverwrite nodes) — keep the `@node-id` marker intact.

**Recipe 9 — Cross-BC write: translate → foreign `process()` → map response (G7)**

A Cross-BC-Call node whose target **mutates** state in a foreign BC must target that BC's **process**, never its aggregate — the Designer/Validator enforce this (`V-XBC-WRITE-TARGET`; the same rule is additionally sealed as a PHPStan boundary gate): `consumedCalls` for a foreign write offers only process methods of the target BC. Reads stay on the foreign `{Agg}Read` (unrestricted, no process mandate). The generated Service (`{Domain}/Service/<Name>.php`, `generated-code-extend` §1) is the ACL — it never passes the caller's DTO through unchanged.

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

        // 1) read the foreign current state via the foreign READ facade
        $read = $catalog->product()->getProductByIdentifier(
            new QueryProductByIdentifier(identifier: $cmd->productIdentifier)
        );
        if (!$read->isSuccess() || $read->getData() === []) {
            return ['status' => WorkflowResult::ON_FAIL, 'data' => ['productIdentifier' => $cmd->productIdentifier]];
        }
        $current = $read->getData();   // flat: ['@type' => 'product', 'identifier' => …, …]; [] when not found

        // 2) translate the own input into the foreign PROCESS input DTO (ACL) —
        //    only the changed field is overridden, the rest mirrors the current state
        $update = new UpdateProductInCatalog(new UpdateProduct(
            productIdentifier: $current['identifier'],
            /* … remaining fields copied from $current[...] … */
            price: $cmd->newPrice,
        ));

        // 3) write exclusively via the foreign process() — never the foreign aggregate
        $response = $catalog->process()->updateProductInCatalog($update);

        // 4) map the response back into the caller's own vocabulary (ACL) — never
        //    pass the foreign DTO through unchanged
        return [
            'status' => $response->isSuccess() ? WorkflowResult::ON_SUCCESS : WorkflowResult::ON_FAIL,
            'data'   => ['productIdentifier' => $current['identifier'], 'newPrice' => $cmd->newPrice],
        ];
    }
}
```

**Rules:** the foreign BC facade itself (`$this->handle({TargetBC}::class)`) is fine to hold — `product()`/`process()` are its own public surface, not an internal hop; only the foreign **write** facade stays off-limits (V6-sibling). Same-BC writes stay on the kernel seam (Recipe 3 Case A) — this recipe is only for a write into a **different** BC.

**Recipe 10 — Guard a Command with a business Rule (Rules-Layer)**

A Rule is a synchronous, endpoint-bound yes/no guard — for an existing-data check that must run before a Command, not for anything multi-step or side-effecting (that stays a Process). Declared in `Closures.json` (BC-level, sibling of Process/): a catalog entry (name, optional Policy reference) plus a binding (which Command, ordered chain, `expose` switch).

**Where the catalog entry comes from.** You author it in the Closure-Editor (`…/closures/{name}/{closure|code}`, reached from the model list's "Neu ▾" (New) or the "Closure andocken ▾" (attach Closure) guard-chain menu on the Aggregate's "API" tab) or headless via MCP `save_closures` — never by hand-editing `Closures.json`. Before writing `__invoke()`, pull the ready-composed work package: the MCP Resource template `jardis://closures/{domain}/{subdomain}/{bc}/{name}/work` (same JSON over `GET /api/closures/{domain}/{subdomain}/{bc}/{name}/work`) hands you the free-text task, the contract/signature, `uses`/`reads` call recipes, the path of the test you write from `examples[]` (`files.test`, with `testExists` and `runTest`), and `body: "offen"`/`"geschrieben"` telling you whether the stub still throws `Not implemented`.

```php
// {BC}/Closure/CounterMustBeActive.php — DeveloperOwned, tag RuleClass
final class CounterMustBeActive extends MeterDeviceContext
{
    public function __invoke(UpdateCounter $cmd): RuleResult
    {
        /** @var Counter $counter */
        $counter = $this->handle(Counter::class);   // own BC only (M9: never a foreign BC) — here via the read facade; context() works too
        $read = $counter->counter()->getCounterByIdentifier(
            new QueryCounterByIdentifier(identifier: $cmd->identifier)
        );

        $record = $read->getData();   // flat record; [] when the counter does not exist
        if ($record === [] || ($record['status'] ?? null) !== 'active') {
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

**What's generated, what's yours:** the Generator emits the stub signature + the `Closure/Data/RuleResult.php` VO + a hermetic `Closure/Guard/GuardUpdateCounter.php` that runs the bound chain (AND, short-circuit) from inside the generated `UpdateCounterHandler` — you never call the Guard yourself, and you never wire the rejection into a response: a chain rejection surfaces as `ResponseStatus::RuleViolation` (422) with `{rule, messageKey, context}` automatically. Your only job is the `__invoke()` body above.

**Rule as a Process node:** the same catalog entry can additionally be dropped as a node in the Process Designer — a catalog reference (matrix-ineligible, like a sub-process node), the generated adapter maps `passed → ON_SUCCESS` / `rejected → ON_FAIL`. This is for an **early** check in a flow (before expensive work), not a replacement for the endpoint chain — binding the same Rule both at the endpoint and as a node in a process that calls that endpoint is flagged (M7, a build-time warning, not an Error: possible double-execution / inconsistent existing-data reads between the two runs).

**Rules:**
- Never `new` a Rule — always `$this->handle({Rule}::class)` (ClassVersion-capable, `Closure/v{N}/`).
- Read existing data only from your **own** BC (V13/M9) — via that BC's read facade, or directly via the kernel seam (`context()`) for a BC-internal read — a declared internal list read with `limit: 1`, decided over `total` — a cross-BC existing-data check is process territory, not a Rule. **Worked example (`query-ist-immer-eine-liste.md`):** "customer has open invoices" — Query `openInvoicesByCustomer` (`internal`, `limit: 1`) declared via `save_queries`, bound via `Closures.json` `reads:`; the Rule body reads `$this->context(GetOpenInvoicesByCustomerHandler::class, new OpenInvoicesByCustomerFilter(customerId: $cmd->customerId, limit: 1))()` and rejects when `total > 0`.
- A Rule never throws to reject — `RuleResult::reject(...)` is data, not an exception. Only let a genuinely technical failure (DB down) propagate as an exception (→ 500), never mis-signal it as a 422 by wrapping it in `reject()`.
- A freshly generated, **not-yet-implemented** stub throws too — but for the opposite reason: the emitted body is `throw new \RuntimeException('Not implemented: write the rule predicate for ' . self::class)`, not `RuleResult::pass()` (G03). An unfinished Rule fails loud (500) instead of silently letting every Command through — implement `__invoke()` before binding it live.
- Versioning a Rule (`Closure/v2/`) may **tighten** the accepted set, but must keep the payload shape + `messageKey` stable — that's the contract callers (and i18n) depend on (M5, `generated-code-versioning`).
- TOCTOU is a known v1 boundary (`generated-code-extend` §7) — a concurrent write between the Rule's read and the Command's persist is not locked against. Harden with a DB constraint if the invariant is truly hard.

**Recipe 11 — Invariant as state: securing a uniqueness invariant across process boundaries**

> Where a database UNIQUE index exists on the key, use Recipe 12 instead (typed `UniqueViolationException` → 409 `duplicate`, no gatekeeper node).

A decision node that checks for absence via a query ("is there already an invoice for this
order?") and writes afterwards is check-then-act — racy under concurrency, two
simultaneous runs can both pass the check. The replacement: uniqueness is not
checked but **modelled as the state of an existing row**, implemented by a conditional write
(CAS-UPDATE, the `generated-code-extend` Persist-Layer emits `$expected` = old values of the
changed fields automatically). Two simultaneous runs can never both win
— no new mechanism, no lock table, no DB-UNIQUE rule carrier.

**Basic rule:** the gatekeeper (the node with the conditional write) is the **first
writing node** of the process. Nothing unwanted may have been written before it — the
savepoint (see conflict cascade below) afterwards only secures the atomicity of the aborted
single persists, not the order.

**Case A — gatekeeper/status aggregate** (e.g. "one invoice per order"). A dedicated
status aggregate carries a flag (`fakturiert: bool`). The row is created **in advance** (e.g. via
domain event on "order delivered"), not only when invoicing itself — otherwise the
race returns as an initial-creation race. Process flow:

```
N1 "MarkInvoiced"          — conditional UPDATE fakturiert: false → true
   ON_SUCCESS → N2 "CreateCustomerInvoice"   (unconditional INSERT)
   ON_FAIL    → declared 409 edge (reject terminal)
```

A convergent rejection — several functionally different predecessor nodes (not found / already
invoiced / validation) lead into the same reject-terminal node — is the **normal case**,
not a special case, and needs no handling of its own.

**Case B — number range/reservation with retry** (the system assigns the key). A small
counter aggregate (`lastNumber: int`):

```
N1 "ReserveInvoiceNumber"  — conditional UPDATE lastNumber: n → n+1
   ON_SUCCESS → N2 uses the new value (passed on via WorkflowContext::getLatest() in the
                node body — there is no declarative way for this in the corpus, dev surface)
   ON_FAIL    → 409
```

**Retry is convenience, not correctness** and needs two obligations: an **upper bound**
(attempt counter, the engine has no built-in loop brake) and — the documented defect of this
class — the target value MUST be **recomputed per attempt**, from the freshly read
`current` state. A hard-wired target value (`lastNumber: 1` on every attempt) produces on the
second attempt a no-op UPDATE whose `rowCount()` interpretation turns out
differently depending on the driver (MySQL accidentally reads it correctly as a conflict, Postgres wrongly as success → double
assignment).

**Status behaviour at process end.** A gatekeeper conflict must become visible outwardly as 409,
not merely route internally. Without this refinement a healed retry wrongly reports 409 despite actual success
including the side effect (documented, Postgres number range, see below).

This is the three-level separation from `generated-code-workflow-api` §1: **branching** (ON_SUCCESS/ON_FAIL
= true/false, pure path selection) · **response status** (always from the actual `DomainResponse`
of the last decisive node, NEVER from the edge declaration) · **transaction** (the No path
keeps committing — a declared 409 terminal is no reason to roll back, only a thrown
technical error rolls back).

**Collision sequence (Case A):** both runs load `fakturiert=false`. The first N1 wins. The
second N1 waits on the row lock, re-evaluates the current state after waiting (the UPDATE
is a current read), hits 0 rows → 409 → N2 is never reached. The bracket does
NOT roll back in that case, it commits empty — the No path is a legitimate completion, not an abort.

**Limits:**

- **Only via `runInTransaction` processes.** The direct BC path (Command without a process) stays
  unprotected.
- **SQLite:** without `busy_timeout` SQLite does not wait on the lock but throws
  `SQLITE_BUSY` immediately — the loser ends up via the Throwable path as a **500 with rollback**, not as
  409. The invariant holds (it writes nothing), but status and waiting behaviour are wrong.
  A known item of its own on the dbConnection adapter, not solved here.
- **Under MySQL `REPEATABLE READ` a retry structurally never heals within the same transaction** —
  every snapshot of the loser sees the same `current` as its first read, never the
  winner value committed in the meantime. Under Postgres `READ COMMITTED` the retry sees the committed state after
  waiting and can win. Both pictures are correct — a real
  driver delta, not a bug.
- **rowCount() trap as a warning:** the generated CAS persist layer checks success via
  `rowCount() > 0` — unreliable depending on the driver for no-op updates. Affects every gatekeeper whose target value
  may happen to match the actual state — just as relevant for Case A (bool flag) as for
  Case B (counter).

**Event initial creation remains a concept, not a finished path.** The status row in Case A should ideally
arise via domain event ("order delivered" → create row), but the generated
`<Agg>EventRouter.php` is a pure registration stub (§1 above) — delivery via a
real transport (Kafka/HTTP/in-process, §1) is open wiring, not a finished building block. Until then:
initial creation via fixture seed / a one-off migration step, documented as a deliberate gap, not
silently passed over.

**Converting the real process.** An existing check-then-act decision node (reads via query
for absence) is replaced in the Designer, not built alongside it: model the status aggregate
(new table, a flag or counter field), put the gatekeeper node as the first writing node
in front of the previous write logic, remove the racy read check, declare the 409 edge
as terminal. The business pre-check ("delivered?", "does the order exist at all?") remains
in place as a read decision BEFORE the gatekeeper — only the uniqueness half moves into the
gatekeeper.

**Recipe 12 — Duplicate on a unique key → 409 `duplicate` (instead of a gatekeeper node)**

Where the database holds a UNIQUE index on the key (the aggregate's public unique key or any
unique column), the generated persist already turns a collision into the business answer — no
gatekeeper node, no check-then-act query. The repository package types the unique-constraint
hit as `JardisSupport\Contract\Repository\Exception\UniqueViolationException` (contracts ≥ v2.2.0,
repository ≥ v1.3.0); every generated Persist catches it per transaction bracket BEFORE the general
catch, rolls back (own transaction) or to its savepoint (open bracket), and throws
`ConcurrencyConflictException('duplicate', …)`. The Command/Process answers 409
`{"@type":"concurrencyConflict","reason":"duplicate","aggregate":"…","context":{<outer-door identifier>}}`;
an FK violation stays a technical 500. No driver knowledge in the generate, nothing to write.

- Use Recipe 11 (gatekeeper) only where NO database UNIQUE index can carry the invariant (a state over several rows, a counter, a status transition).
- Dev code that writes OUTSIDE the generated persist (own `Repository/` or `Service/` under `{BC}/Process/{Name}/`) catches `UniqueViolationException` itself and translates it to its own business answer (e.g. a 409 `DomainResponse`); it never inspects driver error codes or SQLSTATE.
- Keep the DB UNIQUE index in `Schema.json` — it is the only carrier; without it nothing throws and the duplicate is stored.

### 3. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `LogicException: Cannot resolve ClassName` | BC vs. Model segment swapped in namespace | Namespace is `<Domain>\<BC>\Model\<Agg>\…` — BC and Model are two segments even when they share a name (the `Model/` segment sits between them) |
| Edit under `{BC}/Model/{Agg}/` gone after rebuild | The **whole** aggregate tree is hermetic (ForceOverwrite, V1) — every build truncates and rewrites it; there is no override slot inside it | Move the behaviour to a **Process** (`{BC}/Process/<Name>/Command/Handler/Action/`), re-model the aggregate in the Designer, or (tenant variant) author a `v{N}/<Class>.php` next to the baseline (`generated-code-versioning` §1) |
| `on<Event>()` edit gone after rebuild | Bodies were filled in the hermetic `<Agg>EventRouter.php` | Never edit the router — author transport in a **Process node** that publishes the events of the aggregate command's response (§1) — or let an Event ◇ node announce a Domain event via the channel `__jardis` |
| Versioned override (`v2/…`) ignored | The call isn't passing `'v2'` as `$version`, or a needed `ClassVersionConfig` fallback entry is missing | Thread the version through the call (`$bc->{agg}()->getCounterById($dto, 'v2')` for reads, or `$this->handle(Counter::class)->createCounter($dto, 'v2')` family-internally for writes) — there is **no** domain-wide `version()` default to set instead, the per-call argument is the only lever; the variant must sit at `{Agg}/…/v2/<Class>.php` (immediate neighbour of the baseline) — `generated-code-versioning` §1 |
| Process node body lost after rebuild | The custom node lost its `@node-id` marker, or the file had broken syntax so the body-preserve merger could not parse it | Keep the generated `@node-id` DocBlock marker intact; fix the parse error. The merger regenerates the node *header* but preserves the body keyed by `@node-id`. (The Designer's "Force" build path deliberately overwrites a node body.) |
| Process node not invoked though it's in the graph | R5-Routing-Safety: the node isn't registered via `addNode()`, or the returned `ON_*` status has no transition in the current node | Every handler referenced in `->onSuccess()/onFail()/…` must be declared as its own `->node(...)`; add the missing status to the routing — `generated-code-workflow-api` §5 |
| `Error: Cannot instantiate abstract class` / "Service X not in container" | Direct `new` bypassing `handle()` (V2 / V3) | Replace with `$this->handle(X::class, ...)` from inside the node |
| `Cannot import OtherBC\...` review blocker | V6 violation (cross-BC import) | Add a Domain Service in the Process scope and call the other BC via `handle()` |
| Process tries to extend an aggregate DTO (`extends platform:…`) | Not supported — a process input is self-contained (Recipe 5) | Declare the fields the process needs in `input.fields`; fetch aggregate data by running its query from a node |
| Event node / node reading `getData()['<ctx>']` fails or yields `null` | `getData()` is flat — there is no handler-name or root level; a read keyed by context, or with `?? null`, misses | Read the field flat: `$context->getLatest(Node::class)->getData()['<idProp>']`, and let a missing key fail (`?? throw …`) instead of `?? null` |
| `getData()` empty after `addData()` in a node | The node returned before augmenting `$this->result()`, or replaced the payload | Read aggregate data, then `addData(...)`/`setData(...)`, then return |
| Process node throws → whole process fails | Default: an uncaught node exception routes to `ON_FAIL` (or bubbles to 500 if unrouted) | Wrap the node body in `try/catch` only if its failure must not fail the process; otherwise add the `ON_FAIL` transition — §1, `generated-code-workflow-api` §5 |
| Sub-process node body overwritten after rebuild | Sub-process node lost its `@node-id` marker, or the file was built with an older Generator version (formerly ForceOverwrite No-Op) | Keep the `@node-id` DocBlock marker intact; if the file is an old No-Op, delete it — the next build emits the typed Dev-Stub fresh (Recipe 8) |
| Sub-process throwing resolver throws at runtime | Expected — the resolver is a placeholder until the developer fills in the real value from `$context` | Replace `throw new \RuntimeException(…)` in each resolver with the real value (e.g. `return $this->payload()->counterId;`) |
| Sub-process `Domain` events missing in main response | The node returned `Internal` events, or put the events anywhere but the reserved channel | The stub returns `'data' => ['__jardis' => ['events' => $events]]`; `ResolveProcessOutcome` harvests only that channel from the executed chain (`getChain()`) |
| Process answers with a field named `responseStatus`/`domain` missing or changed | A business field collided with the reserved channel — only the key `__jardis` is reserved, business fields named `responseStatus` or `domain` pass through unchanged | Never write business data under `__jardis`; check the node returns the business fields beside it |
| Process doesn't appear on `$bc->process()` facade | `subprocessOnly: true` is set — by design | The process is only callable as a sub-process node; use `$this->context(Handler::class, $dto)()` from another node; or unset the flag if the process should also be a public API entry |
| Rule body edit gone after rebuild | Byte-for-byte matched an untouched generated stub (wholesale-migration path) — false-positive risk is a known, documented trade-off of the merge's exact-match check | Make a real edit (any content change) — the merger then treats the method as hand-edited and keeps it 100% verbatim on every future rebuild |
| Rule stub throws `RuntimeException: Not implemented: write the rule predicate for …` | Expected — a freshly generated, not-yet-implemented Rule predicate throws instead of failing open with `RuleResult::pass()` (G03); a Guard-Closure never wraps its Rule dispatch in try/catch, so it propagates uncaught and surfaces through the generated Command handler's generic `catch (\Throwable $e)` as a 500, never the 422 a bound Rule is meant to produce | Implement `__invoke()`: return `RuleResult::pass()` / `RuleResult::reject(...)` per your existing-data check |
| Command rejects with 422 but I expected the Command to just run | A bound Rule in `Closures.json` returned `RuleResult::reject(...)` — check `data.rule`/`data.messageKey`/`data.context` in the response (`data.@type` is `ruleViolation`) | Expected behaviour, not a bug — either the existing data genuinely fails the Rule, or the binding/chain in `Closures.json` is wrong for this Command |
| `expose: true` binding warns or fails the build | Zero bound Rules only warns (`V-RULE-5`; exposed endpoints should be rule-guarded). A Create-Command is a blocker (`V-RULE-6`: name always collides with `{agg}()`, structurally never exposable). Child commands (`Add{Child}`, `Set…`, `Update…`, `Remove…`) are exposable like root commands | Bind ≥1 Rule before exposing; Create-Commands stay reachable only via a Process |
| Command-calling Process node throws instead of routing `ON_FAIL` on a 500 | Intentional staircase semantics: `422 → ON_FAIL`, `5xx → exception path` — never a blanket `isSuccess() ? ON_SUCCESS : ON_FAIL` | Not a regression — add the `onFail` edge for the 422 case; a genuine 5xx is meant to surface as an exception, handle it like any other node exception (`generated-code-workflow-api` §5) |
| M7 warning ("doppelt gebunden" (bound twice)) on a Rule node | The same Rule is bound both at the endpoint (`Closures.json`) and as a node in a process calling that endpoint | Usually fine (early-check pattern) — only a problem if the two runs can see inconsistent existing data between them; drop the node binding if redundant |

### Anchors

- `generated-code-extend` (hermetic aggregate layout, the customization surfaces incl. the `{BC}/Closure/` catalog, prohibitions incl. M9/V13, decision tree, TOCTOU boundary).
- `generated-code-versioning` (ClassVersion resolution — `LoadClassFromSubDirectory`, per-class `v{N}`).
- `generated-code-workflow-api` (Workflow-Engine API used by the Process orchestrators + node routing referenced above).
- `adapter-messaging`, `adapter-http`, `adapter-eventdispatcher` (event-transport recipes).
- `foundation-testing` (EventCollector fake for Recipe 4).
