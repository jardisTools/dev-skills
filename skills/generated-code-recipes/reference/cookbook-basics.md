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

No method is hand-written and nothing under `{Agg}/` is edited — both facades are fully regenerated (no `@flow-id`, no body merge). A new command has **no** external caller by itself (G6, row `closed` by default): open its row in `Doors.json` (`domain` = facade door `{BC}::{command}()`, `outside` = route + right) for a single guarded command, or wrap it in a Process (Case B) to expose it via `$bc->process()`.

**Case B — a BC-level operation or one that coordinates several aggregates.** Model a **Process** in the Process Designer. The Generator emits, under `{BC}/Process/<Name>/`:

1. `Command/<Name>.php` — the self-contained input DTO (`readonly`, its own `input.fields` — Recipe 5). Namespace: `…\Process\<Name>\Command\<Name>`.
2. `Command/Handler/<Name>Handler.php` — the door that starts the run (`ValidateInput`, `RunOwnedTransaction` around `ProcessRunner::run(...)`, answer closures of `response`; the graph is its private `definition()`), next to the input gate `<Name>Validator.php` (only when the input carries a rule), the typed run view `<Name>Context.php` (one getter per node) and one object-value class `<Name><Value>.php` per `gives` with fields (tenant and user of the signed-in user are the fields `tenant`/`user` of the process input, read as `$ctx->input()->tenant`; no process class of its own for them) — `generated-code-workflow-api` §1/§6. ForceOverwrite — regenerated, not edited. Namespace: `…\Process\<Name>\Command\Handler\<Name>Handler`.
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
