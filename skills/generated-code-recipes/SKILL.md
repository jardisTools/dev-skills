---
name: generated-code-recipes
description: Phase-3 recipes and troubleshooting for Designer-generated code — Event announce from an Event ◇ node and transport in `App/**` delivery code (Kafka/RabbitMQ/Redis/HTTP-webhook; the generated `<Agg>EventRouter.php` is hermetic), validating a domain concept with a Closure, external lookup through an External-Call node (Domain Service at `{BC}/Service/`), new aggregate op vs. new Process, self-contained Process input, Response shapes per operation, bulk read list→ids→`get{Agg}ByIds` (or, with a unique key, list→keys→`get{Agg}By{PluralKey}`), sub-process node, cross-BC write (DTO-translation → foreign `process()` → response-mapping), guard a Command with a business Rule (Rules-Layer), write a computed field from a process node (`Set{Entity}Computed`), cross-BC read only through the doors of the foreign BC (`{Agg}Read`, from a Cross-BC-Call node and its Service at `{BC}/Service/`; writes through `process()`), typed Process inputs (date/datetime/time/decimal/uuid/`valueList`, strict date gate), default or declared (`successOutput`) Process answer, the process transaction bracket (default on, 4xx rollback, events only after commit, `runInTransaction: false` opt-out), Invariant as state (uniqueness invariant via a first-writing gatekeeper node + CAS-UPDATE instead of check-then-act, status aggregate/invoicing and number range/reservation-with-retry cases), troubleshooting table (ClassVersion misses, hermetic-tree edits lost, `@node-id` body-preserve, routing-safety, cross-BC, listener exceptions, Rule-merge/422 pitfalls).
zone: post-active
persona: C
profile: jardis
prerequisites: [generated-code-extend]
next: []
---

> **Layout context.** The aggregate tree `{BC}/Model/{Agg}/` (abbreviated `{Agg}/` in this skill) is hermetic — ForceOverwrite, never edited (V1); hand code stands only at the places of the one table in `generated-code-extend` §10 (node bodies of a process reached via `$bc->process()`, Closure/Rule bodies, `{BC}/Service/`, `App/**`). `$bc->{agg}()` reaches only the read facade `{Agg}Read` (the BC facade's public surface); the write facade `{Agg}` is family-internal via the kernel seam `$this->handle({Agg}::class)`. Canonical layout + vocabulary glossary (aggregate facade / read facade / public surface / kernel seam / Context family): `generated-code-extend` §1–§2.

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

**The router is hermetic** (ForceOverwrite) — you never fill its bodies; the next build truncates them (V1). Events therefore leave a Process only through an **Event ◇ node**, and the transport of an announced event is delivery code in `App/**` (row 10 of the table in `generated-code-extend` §10), never an edit of the router. The aggregate command records its events on its response (`$this->result()->addEvent($event, EventScope::Internal)` in the generated handler) — the handler **only collects, it does not dispatch**. Note `getEvents(?EventScope)` is **context-keyed** (`array<string, array<int, object>>`), so flatten it before iterating: `array_merge(...array_values($response->getEvents()))` (or a nested `foreach`). (Aggregate life-cycle events are `Internal`; a **Domain event** is announced by an **Event ◇ node** of the Process.)

**What a node sees of the earlier nodes (workflow 4.0).** Nothing is handed on from node to node: the answer of a run is the data of the **last** node only, and a node that needs an earlier answer asks for it explicitly — `$ctx->latest(NodeFqcn::class)?->data()` / `$ctx->all(NodeFqcn::class)` (or the typed getter `$ctx-><node>()` of the generated `{Name}Context`, a `?NodeResult` over `latest()`); `$ctx->previous()` is only the node run last, which can be a node without data. The `data()` of a call node is the command's **flat reference value** — an `array`, never a `DomainResponse`, with no handler-name or root level around it, e.g. `['@type' => 'counter', 'counterIdentifier' => '018e…']` — read it flat: `$ctx->persistCounter()?->data()['counterIdentifier'] ?? throw …`. A misspelt key must fail loud (`?? throw`), never read as a silent `null`. The node's `DomainResponse` and its `Internal` events are **not** forwarded by the default body (`MapResponse`: 2xx becomes `ok(data)`, 4xx a `reject`).

**How events leave a Process.** Only an **Event ◇ node** announces a Domain event (generated, no body to write). The node ends `NodeResult::route(Route::OnTrue)->withEvents(new <Event>(...))`: the events travel with the result, not in the data. The runner collects the events of all steps in execution order and reports them on the `ProcessOutcome` for a successful run only (a rejected result and a failed run report none); the generated handler adds each with `addEvent($event, EventScope::Domain)` and the **caller** publishes after the commit, from `$response->getEvents(EventScope::Domain)`. The identity of the event is read from the earlier node explicitly through the typed context — `$ctx->persistHeartbeat()?->data()['heartbeatSignalId'] ?? throw …` — because an event node routes without data and a process that ends on it answers with no fields of its own (on the wire `data` carries only the process `@type`, `{"@type":"<processName>"}`). **Event class name and FQCN:** the event-data class is `{Domain}\{BC}\Process\{Process}\Event\<ucfirst(node name)>` (the node class beside it is `<ucfirst(node name)>Node`) — a node named `invoicePaid` emits `…\Event\InvoicePaid`. Listeners and hand-written transport import exactly that FQCN. A project built before the PascalCase derivation carried the raw lowercase name (`…\Event\invoicePaid`): the next build rewrites the event under the new name, reports "FQCN geändert: Listener-Importe prüfen" as a build Hinweis, and **your `use …\Event\invoicePaid;` imports must be updated by hand** (grep the project for the old FQCN after that build).

A node's `data` holds business fields only: status, rejects and events are carried by the `NodeResult` itself (`reject(...)`, `withEvents(...)`), never by a key inside `data` — do not put events or a status into `data`. A node does not publish: announcing is the Event ◇ node's job, publishing is the delivery code's.

**Event payload identity (X-3).** `<Agg>{ChildEntity}Added` events carry the affected child's business identifier (`<childIdentifier>`) when G4 is satisfied — otherwise the internal PK (same rule as the Command response, see Recipe 6). Wire your listener against the business key whenever you can; the internal PK is meaningful only inside the aggregate and changes on rebuild scenarios where data is reseeded.

**Publish from delivery code (`App/**`).** After the commit the caller holds the answer of the door and publishes its Domain events. That code is delivery code — row 10 of the table in `generated-code-extend` §10: it takes the events from the answer, hands them to the transport package, and carries no business decision. It reaches the BC only through the doors `{agg}()` / `process()`, and the transport package through the kernel's accessors (`$kernel->messaging()`, `$kernel->httpClient()`); never `Model/**`, a repository or `DbQuery`.

**Kafka / RabbitMQ / Redis** via `jardisadapter/messaging`:

```php
// App/** — delivery code, no business logic
$response = $app->counter()->process()->createCounter($dto);   // the public door of the BC

if ($response->isSuccess()) {
    foreach (array_merge(...array_values($response->getEvents(EventScope::Domain))) as $event) {
        $kernel->messaging()?->publish('meterdevice.counter.counter.created', $event);   // MessagingServiceInterface of the DomainKernel
    }
}
```

An **HTTP webhook** goes the same way through the kernel's PSR-18 `httpClient()` (`adapter-http`). A projection or audit trail is not a free projector class: model it as a Process (or an aggregate Command) that the delivery code calls through `process()`.

**Rules:**

- Never `new` publishers / clients — take them from the DomainKernel accessors (V2 / V3).
- Never fill the generated `<Agg>EventRouter` bodies — the tree is hermetic (V1); the router's channel-key comments are documentation for the topic names, nothing more.
- A node runs **synchronously** in the workflow and publishes nothing; the delivery code publishes after the commit and decides nothing about the business.
- A throwing node ends the run as a technical failure: rollback, answer 500, the cause is logged once by the package (`generated-code-workflow-api` §7). A node does not catch an exception to route it; wrap `try/catch` inside the node only to turn a failure into a `reject` or a route.

### 2. Phase-3 cookbook

Paths assume BC `MeterDevice\Counter`, aggregate `Counter`. Aggregate code lives under `{BC}/Model/{Agg}/` (hermetic, never edited); **hand code stands only at the places of the table in `generated-code-extend` §10** — node bodies in `{BC}/Process/<Name>/Command/Handler/Action/` (`@node-id` body-preserve), Closure/Rule bodies in `{BC}/Closure/`, Domain Services in `{BC}/Service/`, delivery code in `App/**`.

**Recipe 1 — Validating a domain concept (in a Process)**

The aggregate's generated Hydrate/Build pipeline is hermetic. A value is checked by the model first: column type, value list and length in the Schema, the process input type. A pattern the model has no setting for (an OBIS code, an IBAN) is a **Closure**: declare a catalog entry with a scalar input and the verdict output (Closure editor or MCP `save_closures`), drop it into the Process as a Rule node, and write the check into the stub body. No free value-object class exists (`generated-code-extend` §10).

Rule stub `{BC}/Closure/ObisCodeIsValid.php` (frame generated, body yours):

```php
final class ObisCodeIsValid extends MeterDeviceContext
{
    public function __invoke(string $obis): RuleResult
    {
        return preg_match('/^\d+-\d+:\d+\.\d+\.\d+\*\d+$/', $obis) === 1
            ? RuleResult::pass()
            : RuleResult::reject(rule: self::class, messageKey: 'counter.obisInvalid', context: ['obis' => $obis]);
    }
}
```

In the Process the Rule node binds the input with the long form `ruleArgs: [{"param": "obis", "source": "obis"}]` (Recipe 10): a violation is `reject(ResponseStatus::RuleViolation, …)` — 422 with the catalog `messageKey` `counter.obisInvalid`, the run ends and rolls back (`reject` is not a route); a passed check takes `Route::OnTrue`. Without a catalog `messageKey` the body's key passes through. For a tenant-specific variant of the Rule see `Closure/v{N}/` (`generated-code-versioning` §1).

**Recipe 2 — External lookup (External-Call node, Domain Service)**

Model an **External-Call** node in the Process Designer. The generator emits the Service stub `{BC}/Service/ResolveMeterLocationName.php` (extends the Domain Context, header-aware merge, `generated-code-extend` §1) with the PSR-18 scaffold via `$this->resource()->httpClient()` (null-guarded) and a docblock with the transport-error convention (`Route::OnFalse` / `Route::OnTimeout`); the node body delegates to it (`return $this->handle(ResolveMeterLocationName::class)->__invoke($context);`). Your part is the body of the Service's `__invoke()`: build the request, call the client, translate the answer into your own vocabulary (ACL), return a `NodeResult`. V9: the Service reads only — it writes nothing in its own BC. The routes the body takes (`onFalse`, `onTimeout`) are drawn in the Process Designer; an undeclared route is a 500. The HTTP client is described in `adapter-http`.

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
2. `Command/Handler/<Name>Handler.php` — the door that starts the run (`ValidateInput`, `CheckActor`, the run step `RunProcess` with `RunOwnedTransaction` around `ProcessRunner::run(...)`, answer closures of `response`), next to the run step `RunProcess.php`, the graph `<Name>Definition.php`, the input gate `<Name>Validator.php`, the typed run view `<Name>Context.php` and (only if the process reads request attributes) `<Name>Actor.php` — `generated-code-workflow-api` §1/§6. ForceOverwrite — regenerated, not edited. Namespace: `…\Process\<Name>\Command\Handler\<Name>Handler`.
3. `Command/Handler/Action/<NodeClass>.php` — one node stub per node. Custom nodes are `CreateIfNotExists` with an `@node-id` marker; **the body is yours** and survives rebuilds. Namespace: `…\Process\<Name>\Command\Handler\Action\<NodeClass>`.
4. A thin-dispatch method on the hermetic `{BC}Process` facade: `return $this->context(<Name>Handler::class, $in, $version)();` — reached via `$bc->process()->{name}($dto)`.

Hand code in a process stands only in the custom-node bodies; a Domain Service lives at `{BC}/Service/` (`generated-code-extend` §10).

Your logic lives in the custom-node bodies. The process has **no aggregate ownership** and invokes whichever aggregate commands/queries it needs via `$this->context(...)` / `$this->handle(...)` from a node.

**Recipe 4 — Event to Kafka (end-to-end)**

A concrete instance of §1. Model a Process whose nodes call the aggregate command and then announce the Domain event with an Event ◇ node (generated, no body to write); the delivery code in `App/**` publishes it after the commit:

```php
// Process CreateCounter: node 1 calls the aggregate command (generated default body),
//                        node 2 is the Event ◇ node `counterCreated` — NodeResult::route(OnTrue)->withEvents(new CounterCreated(...))

// App/** — delivery code, no business logic
$response = $app->counter()->process()->createCounter($dto);

if ($response->isSuccess()) {
    foreach (array_merge(...array_values($response->getEvents(EventScope::Domain))) as $event) {
        $kernel->messaging()?->publish('meterdevice.counter.counter.created', $event);
    }
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

**Input types are the scalar vocabulary of the aggregate door**, not a poorer one: `string`/`int`/`float`/`bool`, `date`, `datetime`, `time`, `decimal`, `uuid`, … (the backend lists them: process load answer `inputTypes`, MCP resource `jardis://process/…`; `save_process` `input.fields[].type`) plus the legacy words `string|array|mixed`, plus a value list as `valueList: <Name>` (instead of `type`; becomes an `enum` in the OpenAPI request in the list's declared order). The DTO type, the route and the OpenAPI schema follow the type (`date` → `DateTimeImmutable`, `format: date|uuid|decimal`), and the process gate checks the value like the aggregate door does: `date` → calendar-strict `Y-m-d`, `datetime` → RFC 3339 normalised to UTC, `time` → `H:i:s`; a violation is `400` `fields[]` with `reason type` before the first node. A field typed `array` or `mixed` carries no scalar shape and warns (`V-INPUT-UNTYPED`); a `valueList` that does not exist in the BC is a blocker (`V-INPUT-VALUELIST`).

**Lists and objects as process input (FIX3).** `list: true` turns a scalar field into a typed list (`{name: "codes", type: "varchar", list: true}` → `public array $codes` with `@param list<string>`); `fields: [...]` makes an object (`{name: "address", fields: [{name: "city", type: "varchar"}]}` → item class `{Process}AddressItem` under `Command/`), `fields` + `list: true` an object list; objects nest at most two levels. A list has no minimum length (`[]` is valid; a required minimum is a Rule answering 422); a missing list is a 400 `missing`, a nullable list may be absent (`null`). Every element is checked at the route and at the gate before the first node: a wrong element answers 400 with its index path (`lines[1].quantity`), never 500. In a node body you read the typed tree directly (`foreach ($cmd->lines as $line) { $line->discount?->percent; }`).

If a process needs data that lives on an aggregate, it does **not** inherit a DTO — it **runs the aggregate query/command** from a node (`$this->context(...)`) and reads the response. There is no merged-DTO / `platform:` parent concept; an unknown field in `input.fields` is a hard build error (no silent fallback).

**Recipe 6 — Response shapes per operation (X-1)**

The Generator emits a fixed, minimal `setData(...)` payload per use-case kind — never the full aggregate (CQRS: Command mutates with a reference-value echo, Query reads with the projected graph). **`data` is flat:** the payload IS the business object and names its own type in the field `@type` — there is no handler-name level and no aggregate-root level around it, so `getData()['identifier']`, never `getData()['GetCounterByIdHandler']['counter']['identifier']`. Layout below for `Counter` (root business key named `counterIdentifier` in the generated code of the example domain; the key names follow the aggregate's business key).

| Use-case kind | Generated `setData(...)` shape (`@type`) |
|---|---|
| **Query ById / By{UniqueKey}** (and hand-modelled single variants) | `['@type' => 'counter'] + get_object_vars(<CounterResponse>)` — the **root fields lie flat** in the array (`getData()['counterNumber']`), but **children stay typed `{Child}Response` objects** (a list of them for `many`, one object for `one`): read them with `->`, e.g. `getData()['registerRelation'][0]->register->obis`; `getData()['registerRelation'][0]['register']` throws at runtime (see Query projection below); nothing found → `[]` (empty payload, no `@type`) |
| **Query ByIds** / **By{PluralKey}** (the latter only on an aggregate with a public unique key) | `['@type' => 'counterList', 'items' => <list<CounterResponse>>]` — every item is a typed `{Agg}Response` **object** (`$item->counterNumber`, children via `->` as well; `$item['counterNumber']` throws), **no `[0]` collapse**, no `total`/`limit`/`offset`; missing ids/keys → partial result, empty input → empty `items` |
| **List** (`{agg}List`, via the read facade) | a plain array `['items' => …, 'total' => …, 'limit' => …, 'offset' => …]` (not a `DomainResponse`); the generated route wraps it as `['@type' => 'counterList'] + $result` |
| **Create** | `['@type' => 'counter', 'counterIdentifier' => …]` — root business key only |
| **Set{Child}** / **Add{Child}** | `['@type' => 'counter', 'counterIdentifier' => …, '<childIdentifier>' => …]` — root key from the cmd-DTO + affected child key resolved via the aggregate walk |
| **Update** / **Remove** / **Remove{Child}** | `['@type' => 'counter', 'counterIdentifier' => …]` — root identity only (Remove{Child} skips the child key) |
| **Process** | the answer of the run is the data of the **last node** only (workflow 4.0) — `@type` (lcfirst process name, or the aggregate root key for a command end) plus what that end node answers: a node with its own `response:` → exactly these fields; a command end (one own-BC aggregate command) → the reference value of the command, e.g. `['@type' => 'invoice', 'invoiceIdentifier' => …]`; a sub-process end → the success form of the inner process; a **rule or event end without `response:`** routes without data and answers with no fields of its own (on the wire `data` carries only the process `@type`, `{"@type":"<processName>"}`) — no answer of an earlier node is borrowed; any other end (dev body, query, cross-BC or external call) answers what its body returns, the model does not name it: the OpenAPI states the open fallback `{"@type":"<processName>"}` and the build warns per such end. A node that needs an earlier answer reads it explicitly (`$ctx->latest(...)`) and returns it — or the model names it with `response:`. Status **200**, never 201. This is the answer **without** a `successOutput` declaration; a process that declares one answers the declaration instead (**Declared success answer** below) |
| **400 / 404 / 409 / 422** | `['@type' => 'validation', 'fields' => [{field, reason, message, messageKey}]]` / `['@type' => 'notFound', 'context']` / `['@type' => 'concurrencyConflict', 'reason', 'aggregate', 'context']` / `['@type' => 'ruleViolation', 'rule', 'messageKey', 'context']` |

Exactly one context of a response may carry data; the generated `DomainResponseTransformer` takes that payload as `data` and throws a `LogicException` when two contexts carry data — it never merges silently.

**`Remove{Child}` does not exist for every child.** A `Remove{Child}` command is only emitted when the child may actually be detached — a DEPEND leaf whose depend-FK is NOT NULL, and a `required` containment child, both get **no** remove mutation at all (rule and rationale: `generated-code-extend` §1). If the row above has no counterpart in your generated aggregate, that is the rule, not a gap.

Substitute the actual root identifier name (e.g. `counterId`, `meterNumber`) where the aggregate uses a different business key. Child responses use the child's business identifier (`<childIdentifier>`, e.g. `counterGatewayId`).

**Business-key resolution (G4 / X-2).** The Generator picks the root identifier by walking the entity for a Single-Column-Unique-Index on a NOT-NULL `string` column. If exactly one such column exists, that is the business key and surfaces in the response. If none exists, the response falls back to the internal `int` PK property (e.g. `counterGatewayId: int` for a keyless `counterGateway` child — not a defect, the only available identity). If multiple ambiguous candidates exist (X-2: two NOT-NULL-unique-string columns), the Build aborts — model an explicit single business key in the Schema instead of letting the response shape become non-deterministic.

**Query projection.** The Generator emits per BC a `{BC}/FieldMap.php` (ForceOverwrite — a pure naming container with one `{table}Columns()` method per BC table, the write-path DTO→column map; there is no `Fields()` method). The **read** projection (internal-PK strip where a business key exists, G4; root-id normalization; FK-column strip; pure-join collapse, F3.1) is aggregate-structural and runs at the **aggregate read edge (the query handler)**, not in FieldMap. The projected aggregate is the typed, recursive `{Agg}Response` class (`{BC}/Model/{Agg}/Query/Response/{Agg}Response.php`, children as `{Child}Response` properties — `public array $orderItem` is a list of `OrderItemResponse` objects); `getData()` spreads only the **root** level into the array, so child access is always `->`. The root `id` is carried by the projected record **only for an aggregate WITHOUT a public unique key** (normalized as `public int $id` on the `{Agg}Response`) — with a public unique key the key replaces it and the `{Agg}Response` has no `id` property (it starts with the key fields). A child entity without a business key of its own keeps its internal `id` (`public int $id` on its `{Child}Response`, the same fallback as the reference value above); a keyed child does not. This concerns the projected aggregate record only — the auto-**list** SELECT is not uniform: on an aggregate WITH a public unique key it leads with that key column `AS {keyField}` instead of `id` (the outward, key-first bulk-read recipe, Recipe 7 below); an aggregate WITHOUT one leads its list with `id`. `DateTimeImmutable` blade values stay inert — JSON/CLI serialization is the caller's job (G5).

**Command response** never carries domain state — only the reference value(s) the caller needs to address what just changed (event-sourcing / correlation). For the full state after a write, the caller issues the matching read-base query — `get{Agg}By{UniqueKey}` with the echoed business key, or `get{Agg}ById` (CQRS).

**Adding a custom field.** The `setData(...)` block sits inside the generated, hermetic operation `__invoke()` body — you do not edit it (V1). To enrich a Process answer, add fields to the flat payload your end node returns (`NodeResult::ok($response->getData() + ['extra' => $value])`); `BuildResponseData` puts `@type` in front and answers with it (not on a process with a `successOutput` declaration: it trims `data` to the declared fields, so an extra field must be declared to reach the wire). Do not use the key `@type` for business fields.

**Declared success answer (`successOutput`, optional process key).** By default a Process answers what it derives from the node it ends on (the row above). A process can instead **declare** its success `data` — set through MCP `save_process`, no Designer editor; a save is a full replace, so carry the value you loaded. The declaration beats the derivation: every success node counts as the determined host, the reference search and the host warning disappear. Without the key nothing changes. The block is `{fields: [...]}` (blocker `V-PROC-SUCCESSOUT`: an empty block, a duplicate field name, the name `@type`, a type outside the column-type vocabulary of `Schema.json`, or a process without a success exit):

| Form | Declaration | The 200 answer | Failure |
|---|---|---|---|
| fields | `successOutput: {"fields": [{"name", "type", "nullable"?}]}` | `@type` = the process name (lcfirst) plus **exactly** these fields, taken from the flat `data` of the node the process ends on; everything else that node returns is trimmed and never reaches the wire | a nullable field without a value answers `null`; a missing or mistyped non-nullable field is a `LogicException` → rollback of the process transaction, **500** |

The OpenAPI states the same: a typed 200 member `{BC}{Process}ProcessSuccess` with every field `required` (instead of the derived `oneOf`). **Blocker:** a declaration that needs data (a non-nullable field) on a process whose success exit is an **event node or a Rule node** — they carry no payload, the run would end in a certain 500 (`V-PROC-SUCCESSOUT`, decided by node kind). The payload always comes from the `data` of the ending node (the last step of `chain()`); nothing from an earlier node is added (`generated-code-workflow-api` §4). The declared answer is still a Process answer: 200, 4xx forms and the transaction bracket (Recipe 14) are unchanged.


**Recipe 7 — Bulk read: list → keys/ids → full aggregate records**

Every aggregate facade carries the uniform read base `get{Agg}ById` / `get{Agg}ByIds` / `get{Agg}By{UniqueKey}` (catalog: `generated-code-extend` §1). An aggregate WITH a public unique key additionally carries the bulk variant `get{Agg}By{PluralKey}` (e.g. `getOrderByOrderNumbers`), and its auto-list items lead with that key column instead of `id` — so the recipe forks:

An aggregate **WITH** a unique key — list → keys → `get{Agg}By{PluralKey}`:

```php
$list  = $bc->order()->orderList($filter);                                             // filtered flat list: plain array {items, total, limit, offset}
$keys  = array_values(array_unique(array_column($list['items'], 'orderNumber')));
$records = $bc->order()->getOrderByOrderNumbers(new QueryOrderByOrderNumbers(orderNumbers: $keys))->getData()['items']; // list<OrderResponse> — objects: $records[0]->orderNumber, children ->; data = {@type: orderList, items}
```

An aggregate **WITHOUT** a unique key — list → ids → `get{Agg}ByIds`:

```php
$list  = $bc->heartbeatSignal()->heartbeatSignalList($filter);                               // filtered flat list
$ids   = array_values(array_unique(array_column($list['items'], 'id')));
$records = $bc->heartbeatSignal()->getHeartbeatSignalByIds(new QueryHeartbeatSignalByIds(ids: $ids))->getData()['items'];  // list<HeartbeatSignalResponse> — objects: $records[0]->serviceName, children ->; data = {@type: heartbeatSignalList, items}
```

Edge behaviour is plain IN semantics either way: empty input → `[]` · duplicates → one aggregate record · missing keys/ids → partial result without error · no order guarantee — match per key (or `id`), which every aggregate record carries. `get{Agg}ByIds` keeps being emitted family-internally regardless of a unique key — only the outward (public surface/OpenAPI) bulk-read surface and the list-item handle switch to the key when one exists.

**Recipe 8 — Sub-process node: calling another process synchronously**

A sub-process node is a **typed Dev-Stub** (`CreateIfNotExists` + `@node-id` body-preserve) — not a No-Op. The Generator emits the whole call frame on first build; subsequent builds preserve the developer body unchanged.

**Generated skeleton** (example: main process `CounterChange`, sub-process node `NotifyOps` calling `SendOpsNotification`):

```php
// {BC}/Process/CounterChange/Command/Handler/Action/NotifyOps.php
// @node-id 2a48bb04

public function __invoke(CounterChangeContext $ctx): NodeResult
{
    // Input mapping of the sub-process SendOpsNotification: the developer's to complete.
    /** @var DomainResponseInterface $response */
    $response = $this->context(
        SendOpsNotificationHandler::class,
        new SendOpsNotification(
            counterId: \call_user_func(static fn(): mixed => throw new \RuntimeException('Sub-DTO-Feld counterId fuellen: NotifyOps')),   // open place: the developer's
            reason: $ctx->input()->reason   // derivable (same name and type in the calling input): handed on
        ),
        actor: null,
        depth: $ctx->depth() + 1
    )();

    return (new MapResponse())($response);
}
```

**What the developer does:** replace each open place (`Sub-DTO-Feld <field> <Node>`) with the real value from the surrounding run — `$ctx->input()->…`, or explicitly from an earlier node, `$ctx->latest(Node::class)?->data()['…']`. The inner call (`$this->context(<Inner>Handler::class, …, depth: $ctx->depth() + 1)()`) and the `MapResponse` mapping are generated — do not touch them, and never map a response by hand.

**What `MapResponse` does with the inner answer** (`JardisSupport\Workflow\Handler\Node\MapResponse`): success → `ok((array) data)` **carrying the Domain events of the inner run**, so they travel up to the outer answer (an inner run that was rolled back reports none; `Internal` events stay behind); a 4xx → a `reject` with the **same** status and the callee's plain context (so the outer wire form equals the inner one); a 5xx → throws a `CalleeFailureException` (the inner door has reported the cause). Events are part of the result, not of the data — there is no channel to fill.

**Routing instead of rejecting:** to carry on after a refused inner run, pass a `Route` as second argument (`(new MapResponse())($response, Route::OnFalse)`) — the node then routes without data, the inner savepoint is rolled back, and the outer run continues on that edge. That is a Dev decision; add the matching edge (here `onFalse`) in the Process Designer — an undeclared route is a 500 (`UnroutedStatusException`, `generated-code-workflow-api` §3). A business verdict (true/false) is data and is routed via a downstream decision node.

**`subprocessOnly` flag:** a process that is only called as a sub-process (never directly via `$bc->process()`) should have `subprocessOnly: true` in its definition (UI toggle "In API sichtbar" (visible in API), default ON). This suppresses the thin-dispatch method on the `{BC}Process` facade — the process DTO, orchestrator, and node stubs are always generated regardless of the flag.

**Rules:**
- Never `new` the Sub-Handler or call it by hand — the generated `$this->context(…)()` call is the only way (V2 / V3).
- Never return the `DomainResponse` upstream — `MapResponse` is the one mapping.
- The sub-process node body survives rebuilds (unlike ForceOverwrite nodes) — keep the `@node-id` marker intact.

**Recipe 9 — Cross-BC write: translate → foreign `process()` → map response (G7)**

A Cross-BC-Call node whose target **mutates** state in a foreign BC must target that BC's **process**, never its aggregate — the Designer/Validator enforce this (`V-XBC-WRITE-TARGET`): `consumedCalls` for a foreign write offers only process methods of the target BC. Reads stay on the foreign `{Agg}Read` (unrestricted, no process mandate). The generated Service (`{BC}/Service/<Name>.php` of the calling BC, `generated-code-extend` §1 and §10) is the ACL — it never passes the caller's DTO through unchanged.

```php
// {BC}/Service/<Name>.php — generated scaffolding, __invoke() body is yours
final class CheckStockInCatalog extends EcommerceContext
{
    public function __invoke(ProcessContext $context): NodeResult
    {
        /** @var CrossBcServiceDemo $cmd */
        $cmd = $context->input();

        /** @var Catalog $catalog */
        $catalog = $this->handle(Catalog::class);          // the foreign BC facade

        // 1) read the foreign current state via the foreign READ facade
        $read = $catalog->product()->getProductByIdentifier(
            new QueryProductByIdentifier(identifier: $cmd->productIdentifier)
        );
        if (!$read->isSuccess() || $read->getData() === []) {
            return NodeResult::reject(ResponseStatus::NotFound, 'catalog.productNotFound', ['productIdentifier' => $cmd->productIdentifier]);   // 404, the run ends
        }
        $current = $read->getData();   // root fields flat: ['@type' => 'product', 'identifier' => …, …]; children are {Child}Response objects (->); [] when not found

        // 2) translate the own input into the foreign PROCESS input DTO (ACL) —
        //    only the changed field is overridden, the rest mirrors the current state
        $update = new UpdateProductInCatalog(new UpdateProduct(
            productIdentifier: $current['identifier'],
            /* … remaining root fields copied from $current[...]; child objects read with -> … */
            price: $cmd->newPrice,
        ));

        // 3) write exclusively via the foreign process() — never the foreign aggregate
        $response = $catalog->process()->updateProductInCatalog($update);

        // 4) map the response back into the caller's own vocabulary (ACL) — never
        //    pass the foreign DTO through unchanged
        $answer = ['productIdentifier' => $current['identifier'], 'newPrice' => $cmd->newPrice];

        return $response->isSuccess() ? NodeResult::ok($answer) : NodeResult::route(Route::OnFalse, $answer);
    }
}
```

**Cross-BC read: declare `crossBcCall`.** A node whose `consumedCalls` resolve into a foreign BC (its `{Agg}Read` methods) is a Cross-BC-Call node on its own; declare `crossBcCall: {ServiceName}` on it so that one shared Service per target serves every node with that target (without the declaration the generator derives one shared Service per target, named from the target's facade segments after the domain prefix and its call key, e.g. `BillingInvoiceGetById`). The generated Action contains no `use` of the foreign BC and delegates to the Service, and the Service reads through the foreign read facade (step 1 above). A missing foreign record is your Service's decision: a `reject` (404), or a `Route::OnFalse` when the process should carry on. Rules keep reading only in their own BC (M9) — a cross-BC existing-data check is process territory.

**Declarations the body needs:** the `reject(ResponseStatus::NotFound, …)` status belongs in the node's `rejects[]` (`V-NODE-REJECT-UNDECLARED`), and the `Route::OnFalse` branch needs an `onFalse` edge on the node (`V-PROC-ROUTE-UNDECLARED` warns, an undeclared route is a 500 at run time).

**Rules:** the foreign BC facade itself (`$this->handle({TargetBC}::class)`) is fine to hold — `product()`/`process()` are its own public surface, not an internal hop; a foreign aggregate, its write facade, its repository and SQL stay off-limits (V6). Same-BC writes stay on the kernel seam (Recipe 3 Case A) — this recipe is only for a write into a **different** BC.

**Dev-body 4xx failure form.** A dev body (custom, sub-process, cross-BC or external-call node) refuses with `NodeResult::reject(ResponseStatus, $messageKey, $context, $errors)` — only 4xx is allowed, a 5xx case is an exception, not a `reject()` (workflow 4.0, `generated-code-workflow-api` §2/§7). The door builds the failure form from the **context keys it knows** (`BuildFailureForm`, package `response`): 400 `validation` from `fields`, 404 `notFound`, 409 `concurrencyConflict` from `reason` / `aggregate` / `violated`, 422 `ruleViolation` from `rule`; every other context key travels as the `context` of the form, and a form field you do not name stays empty (nothing is filled from `errors` or the node name). Any other 4xx (401/403/405) has no form of its own and is passed through as `{messageKey, context}`. The message key a node declares for the status (`rejects(status, key)` on the node's exit) joins `data['messageKey']` of every status >= 400; without one the key named in `reject(...)` is used. The status must be declared in the node's `rejects[]` (`V-NODE-REJECT-UNDECLARED`). So to answer 404 or 422 from a cross-BC service, `reject` with that status and the form's context keys, not free-form business keys. **Contract side:** a Process door declares 422 `ruleViolation` in its OpenAPI whenever a Rule or a dev body of its **own** BC is reachable (a Custom/Query node with a body, also through sub-processes) — with an own-BC dev body reachable the `messageKey` is an open string (no enum), with only Rules reachable it stays the enum of the Rule keys; a cross-BC or external call alone declares no 422 in the contract (it still passes through at runtime). `responses[]` never carries the 422 itself.

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

        $record = $read->getData();   // root fields flat (children would be {Child}Response objects, read with ->); [] when the counter does not exist
        if ($record === [] || ($record['status'] ?? null) !== 'active') {
            return RuleResult::reject(
                rule: self::class,
                messageKey: 'counter.must_be_active',   // declare the same key on the catalog entry — the catalog key is what the 422 carries
                context: ['identifier' => $cmd->identifier],
            );
        }

        return RuleResult::pass();
    }
}
```

**What's generated, what's yours:** the Generator emits the stub signature + the `Closure/Data/RuleResult.php` VO + a hermetic `Closure/Guard/GuardUpdateCounter.php` that runs the bound chain (AND, short-circuit) from inside the generated `UpdateCounterHandler` — you never call the Guard yourself, and you never wire the rejection into a response: a chain rejection surfaces as `ResponseStatus::RuleViolation` (422) with `{rule, messageKey, context}` automatically. When the catalog entry declares a `messageKey`, the generated Guard (and the generated Process Rule node) sets that key as a literal and the `messageKey` you pass to `reject()` is not taken over — the body only decides passed/rejected and supplies `context` (the work package's `output.rejectNote` says so); declare the key in the catalog and pass the same key in the body. Without a catalog `messageKey` the body's key passes through. Your only job is the `__invoke()` body above.

**Rule as a Process node:** the same catalog entry can additionally be dropped as a node in the Process Designer — a catalog reference (matrix-ineligible, like a sub-process node), the generated node is hermetic: `passed` takes `Route::OnTrue`, a violation is `NodeResult::reject(ResponseStatus::RuleViolation, …)` (422, the run ends — `ruleMode` `guard`, the default) or, with `ruleMode: decision`, a `Route::OnFalse` the process goes on over (a guard rule node declares no `onFalse` edge, `V-NODE-GUARD-ONFALSE`). This is for an **early** check in a flow (before expensive work), not a replacement for the endpoint chain — binding the same Rule both at the endpoint and as a node in a process that calls that endpoint is flagged (M7, a build-time warning, not an Error: possible double-execution / inconsistent existing-data reads between the two runs).

**Binding a Rule node's inputs.** Short form `ruleSubject.payloadField` serves a Rule with exactly one `command`/`aggregate` input (a field of the process input). Any other Rule — several inputs, scalar or value-list inputs — uses the long form `ruleArgs: [{param, source}]`, one entry per Rule input in the Rule's own parameter names, never mixed with `ruleSubject` on the same node. `source` is a process input field (bare name) or `{kind: latest, node, field}`: a field of the latest result of an earlier station (a command node: `facade` + `method` set, the fields of its identity chain; a hand-written node: a field of its declared `response:`). That earlier node must be a mandatory station before the Rule node; there is no `all` for Rule arguments. Example (neutral): a check `StockAvailable(sku, quantity)` fed `sku` from the input and `quantity` from the `response:` of a custom node `PriceLine`: `"ruleArgs": [{"param": "sku", "source": "sku"}, {"param": "quantity", "source": {"kind": "latest", "node": "PriceLine", "field": "quantity"}}]`. The Rule outcome stays `passed → OnTrue` / violation → 422 or `OnFalse` (decision mode); the node routes without data of its own — a later node that needs a value reads the earlier node explicitly (`$ctx->latest(...)`), and a process that ends on the Rule node answers with no fields of its own (on the wire `data` carries only the process `@type`).

**Rules:**
- Never `new` a Rule — always `$this->handle({Rule}::class)` (ClassVersion-capable, `Closure/v{N}/`).
- Read existing data only from your **own** BC (V13/M9) — via that BC's read facade, or directly via the kernel seam (`context()`) for a BC-internal read — a declared internal list read with `limit: 1`, decided over `total` — a cross-BC existing-data check is process territory, not a Rule. **Worked example (`query-ist-immer-eine-liste.md`):** "customer has open invoices" — Query `openInvoicesByCustomer` (`internal`, `limit: 1`) declared via `save_queries`, bound via `Closures.json` `reads:`; the Rule body reads `$this->context(GetOpenInvoicesByCustomerHandler::class, new OpenInvoicesByCustomerFilter(customerId: $cmd->customerId, limit: 1))()` and rejects when `total > 0`.
- **An `aggregate` input is the aggregate entity, not just the root:** `{Agg}\Model\{Agg}\Aggregate\Entity\{Agg}` with the child getters `get{Kind}()` (list for `erm: many`). Check children directly (`foreach ($root->getLines() as $line) …`) instead of declaring a helper query in `reads` for data the Guard already holds; the example's `given.root` carries the children under their relation names and `ClosureTestCase::aggregate()` builds the entity. A body that only uses root getters is unaffected.
- A Rule never throws to reject — `RuleResult::reject(...)` is data, not an exception. Only let a genuinely technical failure (DB down) propagate as an exception (→ 500), never mis-signal it as a 422 by wrapping it in `reject()`.
- A freshly generated, **not-yet-implemented** stub throws too — but for the opposite reason: the emitted body is `throw new \RuntimeException('Not implemented: write the rule predicate for ' . self::class)`, not `RuleResult::pass()` (G03). An unfinished Rule fails loud (500) instead of silently letting every Command through — implement `__invoke()` before binding it live.
- Versioning a Rule (`Closure/v2/`) may **tighten** the accepted set, but must keep the payload shape + `messageKey` stable — that's the contract callers (and i18n) depend on (M5, `generated-code-versioning`); a catalog-declared `messageKey` guarantees this across versions.
- TOCTOU is a known v1 boundary (`generated-code-extend` §7) — a concurrent write between the Rule's read and the Command's persist is not locked against. If the invariant is truly hard, close it in the model: a DB constraint in `Schema.json` (Recipe 12) or state with a conditional write (Recipe 11).

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
   OnTrue → N2 "CreateCustomerInvoice"   (unconditional INSERT)
   lost CAS: the call answers 409, `MapResponse` turns it into a reject → the run ends with 409
             (declare the key with `rejects(Conflict, key)`; no edge to draw, a reject is not a route)
```

A convergent rejection — several functionally different predecessor nodes (not found / already
invoiced / validation) lead into the same reject-terminal node — is the **normal case**,
not a special case, and needs no handling of its own.

**Case B — number range/reservation with retry** (the system assigns the key). A small
counter aggregate (`lastNumber: int`):

```
N1 "ReserveInvoiceNumber"  — conditional UPDATE lastNumber: n → n+1
   OnTrue → N2 uses the new value (nothing is handed on: N2 reads it explicitly in its
            node body via `$ctx->latest(ReserveInvoiceNumber::class)?->data()` — dev surface)
   lost CAS: the call answers 409 → reject → the run ends with 409
```

**Handing the read basis to the write (Case B, and any gatekeeper whose target may be recomputed).** The gatekeeper node reads the row (`$current`), computes the target from it and passes what it read as the CAS expectation through the write facade of the own aggregate: `…->update{Root}($dto, $version, expected: ['<outerField>' => $current])` (outer FieldMap name ⇒ value read; family-internal only — the parameter is not on the BC facade, the Rules door, a route or the OpenAPI document; root writers `Update{Root}` / `Set{Root}Computed` only, child writers and `Remove` do not carry it). The generated handler lays the value over the snapshot (the package's `Hydration::hydrate`) after the guard and before the apply, so the UPDATE's `WHERE` checks the value the node actually read; a second writer that read the same value gets 0 rows → 409 `stale_write`. An unknown key in `expected` is a loud error (`InvalidArgumentException` → 500), not a silent no-op.

**Measured picture (20 concurrent workers, one number range, generated process).** MySQL, MariaDB and Postgres: 0 duplicate numbers (`COUNT(*) = COUNT(DISTINCT number)`), the count of 2xx answers equals the count of assigned numbers, every loser answers 409 `stale_write` with `violated: []`. All 20 workers reach a number **only with a client-side retry on 409** — the 409 is the normal loser answer, not a failure. The same run without `expected` assigns duplicates on Postgres.

**A transient database conflict is a 409 too, not a 500 (contracts ≥ v2.6.0, repository ≥ v1.6.0).** The repository package types a transient concurrency failure of the database — MariaDB ≥ 11.6 with `innodb_snapshot_isolation=1` (error 1020), deadlock (1213 / Postgres 40P01), serialization failure (40001) — as `JardisSupport\Contract\Repository\Exception\TransientConflictException`; the generate only catches the type. The loser answers 409 `stale_write` (every write path except delete) or `stale_delete` (delete persist) with `violated: []`, the same envelope as a lost CAS. **How it reaches your node depends on the database:** where the generated persist owns its transaction or can roll back its savepoint (Postgres, or a Command without a process bracket), the 409 arrives at the node as an ordinary answer: a call node's `MapResponse` turns it into a `reject` 409 that ends the run (the engine routes no reject); only a Dev node that inspects the response itself can map it to a route (`Route::OnFalse`) — the retry guard below. On MariaDB/MySQL the 1020/1213 rolls back the **whole** process transaction and the savepoint is gone — the conflict then does **not** arrive as an answer: the `TransientConflictException` is passed through unchanged by the Command handler and by the nodes, the process bracket (outermost owner) rolls back and answers 409 `stale_write` (`aggregate` = process name, `context` empty, no events); a node retry does not take place there. A nested run (sub-process, call from a node) never answers 409 itself; it rolls back its own savepoint if it can and rethrows to the outer owner. With `runInTransaction: false` the exception is thrown on. **The way to repeat is a new transaction — the client retries the whole request on 409 (or the caller starts a new process run); there is no server-side retry.** Dev code in a node that catches `\Throwable` and carries on swallows this type and would then write on after the transaction was destroyed — in autocommit, i.e. a half process; catch only the typed exceptions you translate, and let `TransientConflictException` pass.

**Retry is convenience, not correctness** and needs two obligations: an **upper bound**
(attempt counter via `$ctx->iteration($node)`; `loopLimit(n)` is only the coarse cap of the whole run, default 100) and — the documented defect of this
class — the target value MUST be **recomputed per attempt**, from the freshly read
`current` state. A hard-wired target value (`lastNumber: 1` on every attempt) produces on the
second attempt a no-op UPDATE whose `rowCount()` interpretation turns out
differently depending on the driver (MySQL accidentally reads it correctly as a conflict, Postgres wrongly as success → double
assignment).

**The give-up node must pass the gatekeeper's 409 on.** When the retry guard gives up after its upper bound, its terminal node ends the run with a `NodeResult::reject(ResponseStatus::Conflict, …)` that carries the gatekeeper's 409 (the `reason` and `aggregate` of the answer it kept). A give-up node that answers anything else does not end as the 409.

**Status behaviour at process end.** A gatekeeper conflict must become visible outwardly as 409,
not merely route internally. Without this refinement a healed retry wrongly reports 409 despite actual success
including the side effect (documented, Postgres number range, see below).

This is the three-level separation from `generated-code-workflow-api` §1: **branching** (`OnTrue`/`OnFalse`,
pure path selection) · **response status** (a `reject` carries its 4xx status and ends the run; a routed run
answers 200 — NEVER from an edge declaration) · **transaction** (the branch edge
is transaction-neutral; the Process runs in ONE transaction by default, and a 4xx END status — also
a declared 409 terminal — rolls the whole run back, as does a thrown technical error; the answer
of a rolled-back run carries no domain event).

**Collision sequence (Case A):** both runs load `fakturiert=false`. The first N1 wins. The
second N1 waits on the row lock, re-evaluates the current state after waiting (the UPDATE
is a current read), hits 0 rows → 409 → N2 is never reached. The 409 ends the run, so the bracket
rolls it back — the loser has written nothing, its answer is the 409 and carries no event.

**Limits:**

- **Only via processes in the bracket.** Every process runs in the bracket by default (Recipe 14); the direct BC path (Command without a process) and a process with the explicit opt-out `runInTransaction: false` stay unprotected.
- **SQLite:** without `busy_timeout` SQLite does not wait on the lock but throws
  `SQLITE_BUSY` immediately — the loser ends up via the Throwable path as a **500 with rollback**, not as
  409. The invariant holds (it writes nothing), but status and waiting behaviour are wrong.
  A known item of its own on the dbConnection adapter, not solved here.
- **Under MySQL/MariaDB `REPEATABLE READ` a retry structurally never heals within the same transaction** —
  every snapshot of the loser sees the same `current` as its first read, never the
  winner value committed in the meantime. Under Postgres `READ COMMITTED` the retry sees the committed state after
  waiting and can win (up to the retry bound). Both pictures are correct — a real
  driver delta, not a bug. The loser's answer is the 409; the way to a number is a **client retry in a new transaction**.
- **rowCount() trap as a warning:** the generated CAS persist layer checks success via
  `rowCount() > 0` — unreliable depending on the driver for no-op updates. Affects every gatekeeper whose target value
  may happen to match the actual state — just as relevant for Case A (bool flag) as for
  Case B (counter).

**Initial creation of the status row.** There is no event subscription inside the system — events are
outbound (§1 above), nothing in the generated code reacts to them. The status row in Case A is therefore
created by the process that completes the triggering step ("order delivered"): as a node in the same BC,
or across BCs through a Cross-BC call to the owning BC's `process()`. Rows for data that already exists
come from a fixture seed / a one-off migration step.

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
- Hand code never writes outside the generated persist (no own repository, no SQL): a write is an aggregate Command or a `process()` call, and its unique-key collision is the 409 `duplicate` described above.
- Keep the DB UNIQUE index in `Schema.json` — it is the only carrier; without it nothing throws and the duplicate is stored.
- **Concurrent losers are 409, not 500, also on a transient database conflict** (contracts ≥ v2.6.0, repository ≥ v1.6.0): a snapshot/serialization conflict or deadlock (MariaDB 1020 with `innodb_snapshot_isolation=1`, 1213, 40001, Postgres 40P01) is typed `TransientConflictException` and answers 409 `stale_write` / `stale_delete` with `violated: []` — see Recipe 11 Case B for how it reaches a process node (MariaDB/MySQL: the process bracket answers it, no node retry) and why the repeat is a client retry in a new transaction.

**The 409 names the violated fields (`violated`).** The `duplicate` payload carries one more key: `violated: list<string>` — the **outer** field names (FieldMap) of the unique columns that were hit, so a form can mark the field: `{"@type":"concurrencyConflict","reason":"duplicate","aggregate":"…","context":{…},"violated":["email"]}`. A root field is its plain outer name; a child field is `<kindProperty>.<field>` (e.g. `phones.number`). Columns without an outer name (FKs, the internal `id`) are omitted. `violated` is `[]` — always a JSON list, never `{}` — when the columns are unknown and on every `stale_write` / `stale_delete` answer (those name no field). The columns come from the repository package (`UniqueViolationException::getColumns()` / `getTable()`, contracts ≥ v2.5.0, repository ≥ v1.5.0; `[]` = unknown, never a partial list).

**Recipe 13 — Write a computed field from a process node (`Set{Entity}Computed`)**

A column the process derives (a sum, a counter) is declared `computed: ["net_total", …]` on its entity in `Aggregate.json` (Designer field table switch "berechnet", MCP `save_aggregate` node field `computed`). Such a column is in **no** Create/Set/Add/Update command and in no OpenAPI request — a caller can neither send nor overwrite it (a body naming it is `400 unknown`); Read, list and Akte still carry it. The one write path is the generated, **family-internal** command `Set{Entity}Computed` (one per entity position with computed columns): its DTO is the entity's addressing path (root identifier; for an `erm: many` entity also the selector and the child identifier) plus exactly the computed columns, nothing else. It has no route, no OpenAPI path, no rule binding and no BC-facade method — the only way in is the kernel seam, from a Process node or Service:

```php
// {BC}/Process/{Name}/Command/Handler/Action/RecalculateTotals.php — node body is yours
$response = $this->handle(Offer::class)->setOfferComputed(     // the aggregate WRITE facade, family-internal
    new SetOfferComputed(                                       // {Domain}\{BC}\Model\Offer\Command\SetOfferComputed
        offerIdentifier: $offerIdentifier,
        netTotal: $net,                                         // only the computed columns
        grossTotal: $gross,
    )
);
return (new MapResponse())($response);   // 2xx → ok(reference payload), 4xx (404 missing aggregate, 409 stale write) → reject, the run ends
```

**Rules:** the command is applied through the position's regular aggregate method (root `set{Agg}`, `erm: one` `set{Sub}`, `erm: many` `update{Kind}`) — persist, change tracking and the stale-write 409 are exactly those of the regular write; a missing aggregate answers 404 like every loading command. Like every command it raises **exactly one event** (one event per command): its own `{Agg}ComputedUpdated` at the root, `{Agg}{Fragment}ComputedUpdated` below it — never the event of the position's regular Update/Set — collected by the handler (`EventScope::Internal`, not dispatched; the caller publishes after commit), registered in `{Agg}Events` and `{Agg}EventRouter`, carrying the entity's public fields after the write (children: identifier plus the computed fields). A `computed` column must be nullable or carry a DB default (`V-CALC-1`) — after Create it holds that null/default until this command writes it. A process **input** field named like a computed property is warned (`V-CALC-2`) and a Closure example using `command.<computed>` too (`V-CALC-3`): take the inputs the value is computed FROM, then write the result with this command. A `erm: one` entity that does not exist yet is created by the Set path with the computed values only — write the entity first. In the node catalogue the command is marked `internal` (no HTTP verb); it is a Command for the process classification (loads the aggregate, validated, identity chain).

**Recipe 14 — The process transaction bracket: default, 4xx rollback, events after commit, opt-out**

Every Process runs in **one** transaction by default — nothing to model. `runInTransaction` in the process definition is three-valued: key absent = bracket on, `false` = explicit opt-out (written to the file), `true` = valid but redundant. Every door shows the effective value (process load answer / MCP resource `runsInTransaction`); the Designer switch and `update_process_metadata` write the key only when you touch it, `false` only as an explicit opt-out.

| Run ends with | What happens |
|---|---|
| Success (status < 400) | one `commit` at the end; the domain events collected by the nodes enter the answer **after** the commit |
| **4xx END status** (400/404/409/422, also a declared reject terminal) | the whole run **rolls back**, the answer stays the 4xx — and carries **no domain event**, not even those of nodes that had succeeded before |
| Thrown technical error / 5xx / failing `commit` | rollback, `500`, no event |
| Nested (sub-process, or a node calling `process()` of another BC inside the caller's bracket) | a **savepoint** with a per-run unique name instead of a second transaction; a rolled-back nested part delivers no events to the outer answer, a caller that carries on after the rejection harvests only the events of the part that was not rolled back |

- **A route is a branch, a `reject` is an abort.** The `Route::OnFalse` path may write; only the **END status** of the run decides rollback — a 4xx `reject` or a technical failure rolls the whole run back. Do not model a manual rollback.
- **One connection:** all BCs of a domain write through the same writer, so a Cross-BC write node (Recipe 9) is part of the caller's bracket — no saga.
- **Events (Wurzel 3 unchanged):** the handler collects, nothing is dispatched; the caller publishes the events of the answer after the commit. With the opt-out there is no bracket, nothing is rolled back and the events are harvested unconditionally.
- **Opt-out** `runInTransaction: false` is for a process that must keep partial writes on purpose; the build warns once per process (`V-PROC-TX-OPTOUT`, a warning, never a blocker).
- **Floor:** the savepoints come from the packages (`jardissupport/contracts` ≥ 2.4.0 `SavepointInterface`, `jardissupport/repository` ≥ 1.4.0, `jardisadapter/dbconnection` ≥ 1.2.0) — no raw `SAVEPOINT` SQL in your Dev code either.

### 3. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `LogicException: Cannot resolve ClassName` | BC vs. Model segment swapped in namespace | Namespace is `<Domain>\<BC>\Model\<Agg>\…` — BC and Model are two segments even when they share a name (the `Model/` segment sits between them) |
| Edit under `{BC}/Model/{Agg}/` gone after rebuild | The **whole** aggregate tree is hermetic (ForceOverwrite, V1) — every build truncates and rewrites it; there is no override slot inside it | Move the behaviour to a **Process** (`{BC}/Process/<Name>/Command/Handler/Action/`) or re-model the aggregate in the Designer |
| `on<Event>()` edit gone after rebuild | Bodies were filled in the hermetic `<Agg>EventRouter.php` | Never edit the router — announce a Domain event with an Event ◇ node (`NodeResult::route(Route::OnTrue)->withEvents(...)`) and publish it from `App/**` delivery code after the commit (§1) |
| Versioned variant (`v2/…`) ignored | The call isn't passing `'v2'` as `$version`, or a needed `ClassVersionConfig` fallback entry is missing, or the class is hermetic (a hermetic class takes no variant) | Thread the version through the facade door that starts the run (`$bc->process()->{process}($dto, 'v2')`) — there is **no** domain-wide `version()` default to set instead, the per-call argument is the only lever; the variant must sit in the stub's `v2/` subdir (immediate neighbour of the baseline stub: node, Closure/Rule or Domain Service) — `generated-code-versioning` §1 |
| Build/validation blocked by `V-EVT-NODE-ELIGIBLE` or `V-EVT-MIN-ONE` on an Event ◇ node | A node-source event field (`latest`/`all`) does not point to an aggregate Command of the own BC with an identity chain (it is a Query call, Process call, foreign BC, Sub-Process or Event node, or its aggregate cannot be loaded), or the Event node has no event field at all. Blocker at validation and at every build door; no throwing stub is emitted | Re-point the field to an aggregate Command node of the own BC (or add at least one event field) in the Designer; read the message — it names process, event node, field, source node and reason |
| Directory under `{BC}/Process/` warned about, not deleted | It is not a process of the model and holds no generated node file (`@node-id`) — the build keeps it and names its path in the build result. Only a generator-created directory of a process removed from the model is deleted, with all its content | Move the behaviour into a node body of a modelled process or into `{BC}/Service/`, or delete the directory yourself |
| Process node body lost after rebuild | The custom node lost its `@node-id` marker, or the file had broken syntax so the body-preserve merger could not parse it | Keep the generated `@node-id` DocBlock marker intact; fix the parse error. The merger regenerates the node *header* but preserves the body keyed by `@node-id`. (The Designer's "Force" build path deliberately overwrites a node body.) |
| Process node not invoked though it's in the graph | The node is not declared with `node()` in the `ProcessDefinition`, or the route the previous node took has no declared target — an undeclared route throws `UnroutedStatusException` (500); an end is declared with `terminal(...)` / `onX(null)` | Declare every node as its own `node(...)` and every route a node can take (`onTrue(Next::class)`, `onFalse(...)`, `terminal(...)`) — `generated-code-workflow-api` §3 |
| `Error: Cannot instantiate abstract class` / "Service X not in container" | Direct `new` bypassing `handle()` (V2 / V3) | Replace with `$this->handle(X::class, ...)` from inside the node |
| `Cannot import OtherBC\...` review blocker | V6 violation (foreign-BC internals) | Reach the other BC only through its doors, from a Domain Service (`{BC}/Service/`) or a Cross-BC-Call node: `{Agg}Read` reads, `process()` writes; no foreign aggregate, repository or SQL |
| Process tries to extend an aggregate DTO (`extends platform:…`) | Not supported — a process input is self-contained (Recipe 5) | Declare the fields the process needs in `input.fields`; fetch aggregate data by running its query from a node |
| Event node / node reading `data()['<ctx>']` fails or yields `null` | `data()` of an earlier node is flat — there is no handler-name or root level; a read keyed by context, or with `?? null`, misses; a node that routed without data (`route(...)`) has `data() === null`, and nothing is handed on from it | Read the field flat and explicitly: `$ctx->latest(Node::class)?->data()['<idProp>'] ?? throw …` — let a missing key fail instead of reading `null` |
| PHPStan `throws.unusedType` on a node, Closure or Service body you just filled | A project built before the placeholder rule still carries the `@throws \RuntimeException` the old builder derived from the stub's placeholder `throw` (a freshly generated stub carries none: a `throw` whose statement holds a `Not implemented: …` marker is a placeholder, never a contract). The tag is reconciled against the body at the next build, not when you save the file; a real `throw` you write yourself keeps its tag | Run `build` / `build_domain` after filling the bodies, then PHPStan again |
| Process answer lacks the fields a node computed | The answer is the data of the **last** node only; a field computed in an earlier node is not handed on | Have the end node read it explicitly (`$ctx->latest(Node::class)?->data()`) and return it in `NodeResult::ok([...])`, or name it with `response:` on the end node |
| Process answers 400 although a Rule node or an earlier node should have decided first | By design: the runner's start validation checks the process input (and every command input with the aggregate's `Validate{Command}`) before the first node and before the transaction, so no node has run | Fix the input; the 400 lists one finding per field with `messageKey` `validation.missing` / `validation.invalid` (the package adds it from the `reason`) — a node declaration is not needed; `generated-code-extend` (process gate) |
| Process node throws → whole process fails | A thrown exception is a technical failure: rollback, answer 500, the cause logged once by the package — it is not routed (there is no failure edge) | Catch inside the node only a failure that must not fail the process, and turn it into a `reject` (business refusal, 4xx) or a route; an uncaught exception stays a 500 — §1, `generated-code-workflow-api` §7 |
| Sub-process node body overwritten after rebuild | Sub-process node lost its `@node-id` marker, or the file was built with an older Generator version (formerly ForceOverwrite No-Op) | Keep the `@node-id` DocBlock marker intact; if the file is an old No-Op, delete it — the next build emits the typed Dev-Stub fresh (Recipe 8) |
| Sub-process open input place throws at runtime | Expected — the open place (`Sub-DTO-Feld <field> <Node>`) is a placeholder until the developer fills in the real value | Replace the `\call_user_func(static fn(): mixed => throw new \RuntimeException(…))` by the real value (e.g. `$ctx->input()->counterId`) |
| Sub-process `Domain` events missing in main response | The node did not return the `MapResponse` result (the inner events travel on its `ok(...)->withEvents(...)`), or the inner run was refused/rolled back (a rejected or failed run reports no events), or the events were `Internal` events of an aggregate command (not forwarded) | Return `(new MapResponse())($response, …)` unchanged; a Domain event is announced by an Event ◇ node of the inner process |
| A business field is missing from a 4xx answer | At the exit of a typed 4xx (400/404/409/422) the data is the failure form: only the form's fields plus `context` and a declared `messageKey` remain; a 2xx answer carries the end node's data unchanged | Put extra values into the `context` of the `reject(...)` — they travel as the `context` of the form — and name the form's own keys (`fields`, `reason` / `aggregate`, `rule`) in it |
| Process doesn't appear on `$bc->process()` facade | `subprocessOnly: true` is set — by design | The process is only callable as a sub-process node; call it from a sub-process node (Recipe 8); or unset the flag if the process should also be a public API entry |
| Rule body edit gone after rebuild | Byte-for-byte matched an untouched generated stub (wholesale-migration path) — false-positive risk is a known, documented trade-off of the merge's exact-match check | Make a real edit (any content change) — the merger then treats the method as hand-edited and keeps it 100% verbatim on every future rebuild |
| Rule stub throws `RuntimeException: Not implemented: write the rule predicate for …` | Expected — a freshly generated, not-yet-implemented Rule predicate throws instead of failing open with `RuleResult::pass()` (G03); a Guard-Closure never wraps its Rule dispatch in try/catch, so it propagates uncaught and surfaces through the generated Command handler's generic `catch (\Throwable $e)` as a 500, never the 422 a bound Rule is meant to produce | Implement `__invoke()`: return `RuleResult::pass()` / `RuleResult::reject(...)` per your existing-data check |
| Command rejects with 422 but I expected the Command to just run | A bound Rule in `Closures.json` returned `RuleResult::reject(...)` — check `data.rule`/`data.messageKey` (the catalog key when declared)/`data.context` in the response (`data.@type` is `ruleViolation`) | Expected behaviour, not a bug — either the existing data genuinely fails the Rule, or the binding/chain in `Closures.json` is wrong for this Command |
| `expose: true` binding warns or fails the build | Zero bound Rules only warns (`V-RULE-5`; exposed endpoints should be rule-guarded). A Create-Command is a blocker (`V-RULE-6`: name always collides with `{agg}()`, structurally never exposable). Child commands (`Add{Child}`, `Set…`, `Update…`, `Remove…`) are exposable like root commands | Bind ≥1 Rule before exposing; Create-Commands stay reachable only via a Process |
| Command-calling Process node throws on a 5xx | By design (`MapResponse`: 2xx → `ok`, 4xx → `reject`, every other status throws) — never a blanket `isSuccess() ? ok : route(OnFalse)` | Not a regression — a 4xx ends the run as that status; a genuine 5xx surfaces as an exception (500, cause logged); route a 4xx only where the Dev decision is explicit (`Route::OnFalse` in the node body) — `generated-code-workflow-api` §5 |
| M7 warning ("doppelt gebunden" (bound twice)) on a Rule node | The same Rule is bound both at the endpoint (`Closures.json`) and as a node in a process calling that endpoint | Usually fine (early-check pattern) — only a problem if the two runs can see inconsistent existing data between them; drop the node binding if redundant |
| `$bc->{agg}()` does not exist / no route or OpenAPI path for an aggregate | The aggregate has `visibility: internal` in `Aggregate.json` — it has no outer form by design | Use it family-internally (kernel seam, process node); expose a public aggregate's `{Agg}Read` or a process instead, or set the aggregate `public` |
| Build fails: `V-AGG-VIS-2` / `-3` / `-4` | A `public` query, an `expose: true` binding or a foreign BC's `consumedCalls` points at an `internal` aggregate | Set the query `internal`, drop the exposure, route the foreign call through the target BC's `process()` or a public aggregate |
| Route answers 403 `request.attributeMissing` (`errors.context.attribute` names it) | A scoped door's required request attribute (tenant/subject/session) is absent: the session middleware did not set it | Not a server error: authenticate with a token that carries it; check `middleware.php` order (`session` first) |
| Build fails: `V-QDEF-26` or a path collision (two emissions, different content) | A declared query's class stem equals a derived read way (`{Agg}ById`, `{Agg}ByIds`, `{Agg}By<Key>`) | Rename the query; nothing is silently replaced |
| `save_runtime_env` refuses a value of a BC-declared key (`V-RT-11`/`-12`/`-13`) | The value would load as non-string (number, bool word, `[...]`), is outside the closed `values`, or spans several lines | Use a word value that stays a string; one line per value |

### Anchors

- `generated-code-extend` (hermetic aggregate layout, the customization surfaces incl. the `{BC}/Closure/` catalog, prohibitions incl. M9/V13, decision tree, TOCTOU boundary).
- `generated-code-versioning` (ClassVersion resolution — `LoadClassFromSubDirectory`, per-class `v{N}`).
- `generated-code-workflow-api` (Workflow-Engine API used by the Process orchestrators + node routing referenced above).
- `adapter-messaging`, `adapter-http`, `adapter-eventdispatcher` (event-transport recipes).
- `foundation-testing` (EventCollector fake for Recipe 4).
