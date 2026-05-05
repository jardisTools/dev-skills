---
name: platform-implementation
description: Extending Designer-generated code under the Platform-Dir layout — generator output lives under `{Agg}/Platform/`; developer code parallel directly under `{Agg}/`. CreateIfNotExists stub-slot, 7-stage ClassVersion lookup with `segmentNames: ['', 'Platform']`, V1–V12 prohibitions, decision tree, seven implementation levels, EventRouter override, DomainResponse, Versionierungs-Modell, Phase-3 cookbook, troubleshooting.
zone: post-active
prerequisites: []
next: []
---

### 0. DDD positioning

Jardis uses DDD vocabulary (BC, Aggregate, Domain Event, Repository, VO, Ubiquitous Language) under its own axioms — Closure-Orchestrator (`rules-architecture` §3) and model-driven generation. Closer to Functional DDD (Wlaschin) than to Rich Domain Model (Evans).

| Rule | Consequence |
|---|---|
| **V11** — no business methods on entities | Behaviour lives in Steps (`{Agg}/Aggregate/Step/`, `{Agg}/Command/Handler/Step/`) and Domain Services. Invariants protected by the **pipeline**, not by entity methods. |
| **V12** — responses are `DomainResponse` arrays | One serialisation surface across HTTP / CLI / queue. Typing happens at OpenAPI/Proto layer. |
| **4-hop call chain** | `$app->{bc}()->{agg}()->{command\|query\|event}()->{method}($dto)` — taxonomy is discoverable, `handle()` / ClassVersion resolution anchors at each level. |

Upheld without compromise: aggregates are transaction boundaries with a single root; BCs are strict language boundaries (V6). Rich-Model instinct → reach for a Step override (`{Agg}/Aggregate/Step/`, `{Agg}/Command/Validation/`), a VO (`{Agg}/ValueObject/`), or a Domain Service (`{Agg}/Service/`) instead of entity methods.

### 1. Generated baseline — Platform-Dir layout

Four-level facade chain: Domain → BC → Aggregate → Registry. Example: aggregate `Counter` in BC `Counter` in domain `MeterDevice`.

**The one rule:** Everything under `{Agg}/Platform/` is regenerated on every build — never edit. Everything directly under `{Agg}/` (parallel to `Platform/`) is yours and is never touched after the initial seed.

```
src/                                              ← namespace root = <Domain>
├── <Domain>.php                                  ← Domain facade — Generated, ForceOverwrite
├── Api/
│   ├── FieldMap.php                              ← central FieldMap (Generated)
│   ├── openapi.yaml                              ← REST spec (assembled, ForceOverwrite)
│   ├── asyncapi.yaml                             ← Domain-event spec (assembled)
│   ├── service.proto                             ← gRPC spec (assembled)
│   ├── openapi.custom.yaml                       ← optional Dev-owned slot, never touched
│   ├── asyncapi.custom.yaml                      ← optional Dev-owned slot
│   └── service.custom.proto                      ← optional Dev-owned slot
├── Config/
│   ├── .env
│   ├── .env.database, .env.database.{dev,test}
│   └── .env.logger, .env.logger.{dev,test}
├── Entity/                                       ← Domain entities (shared across BCs)
│   ├── <Entity>.php                              ← #[Table], #[Column], snapshot — Generated
│   └── Validation/<Entity>Validator.php          ← Generated
├── Foundation/
│   └── <Domain>Context.php                       ← extends BoundedContext — Generated
└── <BC>/
    ├── <BC>.php                                  ← BC facade — Generated, ForceOverwrite
    └── <Agg>/                                    ← Dev-owned root (parallel to Platform/)
        ├── <Agg>.php                             ← STUB-SLOT (CreateIfNotExists, extends Platform\<Agg>)
        ├── Aggregate/Step/                       ← Dev-Override am Aggregat-Root
        ├── Command/                              ← Dev-Code: new Commands, Handler/Step, Validation
        │   ├── <NewCmd>.php
        │   ├── Handler/<NewCmd>.php
        │   ├── Handler/Step/Hydrate*.php
        │   └── Validation/Validate*.php
        ├── Query/                                ← Dev-Code: new Queries, Handler
        │   ├── <NewQry>.php
        │   └── Handler/Get*Handler.php
        ├── Repository/                           ← Dev-Override für Q/T/V/P
        ├── Event/<Agg>EventRouter.php            ← Dev-Override (extends Platform\Event\<Agg>EventRouter)
        ├── Api/                                  ← Dev-API surface
        │   ├── <Agg>Commands.php                 ← extends Platform\Api\<Agg>Commands
        │   ├── openapi.custom.yaml               ← optional aggregat-level Custom-Slot
        │   ├── asyncapi.custom.yaml
        │   └── service.custom.proto
        ├── ValueObject/                          ← Phase-3 VOs
        ├── Service/                              ← Phase-3 Domain Services
        ├── Workflow/                             ← Phase-3 Workflows
        ├── v1/, v2/, …                           ← versionierte Dev-Overrides (mirror layout above)
        └── Platform/                             ← ★ GENERIERT, NIE EDITIEREN (ForceOverwrite)
            ├── <Agg>.php                         ← full Aggregate-Facade
            ├── Aggregate/{<Agg>.php, Entity/<Entity>.php, Step/{Set,Add,Remove}<Entity>.php}
            ├── Api/<Agg>{Commands,Queries,Events}.php
            ├── Command/{<Agg>,{Set,Add,Remove}<Entity>}.php  +  Handler/{Create<Agg>,{Set,Add,Remove}<Entity>}.php  +  Handler/Step/{Build*Data,HydrateCreate<Agg>Entities}.php  +  Validation/{<DTO>Validator,Validate<Action>}.php
            ├── Query/{<Agg>,<Agg>ListFilter}.php  +  Handler/Get<Agg>{,List}Handler.php
            ├── Event/{<Agg>{Created,Removed,Updated,…}.php, <Agg>EventRouter.php (empty on<Event>() bodies)}
            ├── Repository/{<Agg>Repository,Query<Agg>,Transform<Agg>,Validate<Agg>,Persist<Agg>,Query<Agg>Additions,Query<Agg>List}.php
            └── v1/, v2/, …                       ← versionierte Generator-Klassen (Platform-side)
```

| Class | Path | Mode | Purpose |
|---|---|---|---|
| Domain Facade | `<Domain>.php` | Generator: ForceOverwrite | `extends JardisApp\|DomainApp`; event-router wiring |
| Domain API specs | `Api/{openapi,asyncapi}.yaml`, `Api/service.proto` | Generator: ForceOverwrite (assembled) | REST / event / gRPC for whole domain |
| Domain Custom slots | `Api/{openapi,asyncapi}.custom.{yaml,proto}` | Developer-owned | Merged by fragment assembler, never overwritten |
| FieldMap | `Api/FieldMap.php` | Generator: ForceOverwrite | One method per entity; `toColumns()` / `fromAggregate()` |
| Config | `Config/.env*` | Generator: CreateIfNotExists | Per-domain ENV defaults — kept on rerun |
| Domain Entity | `Entity/<Entity>.php` | Generator: ForceOverwrite | `#[Table]`, `#[Column]`, `__snapshot` |
| Entity Validator | `Entity/Validation/<Entity>Validator.php` | Generator: ForceOverwrite | Field-level validation |
| Domain Context | `Foundation/<Domain>Context.php` | Generator: ForceOverwrite | `extends BoundedContext` |
| BC Facade | `<BC>/<BC>.php` | Generator: ForceOverwrite | `extends <Domain>Context`; accessors only, **no caching** |
| Aggregate Stub-Slot | `<BC>/<Agg>/<Agg>.php` | Generator: CreateIfNotExists | `class <Agg> extends \…\Platform\<Agg> {}` — written once, then yours |
| Aggregate Facade (full) | `<BC>/<Agg>/Platform/<Agg>.php` | Generator: ForceOverwrite | Exposes `command()`, `query()`, `event()` — internal caching |
| API Registries | `<BC>/<Agg>/Platform/Api/<Agg>{Commands,Queries,Events}.php` | Generator: ForceOverwrite | Public entry for this aggregate |
| Aggregate (Root + Entity + Step) | `<BC>/<Agg>/Platform/Aggregate/{<Agg>.php, Entity/<Entity>.php, Step/{Set,Add,Remove}<Entity>.php}` | ForceOverwrite | Invariants, ONE/MANY accessors, mutation rules |
| Command tree | `<BC>/<Agg>/Platform/Command/{<Action>.php, Handler/<Action>.php, Handler/Step/{Build*Data,Hydrate*Entities}.php, Validation/{<DTO>Validator,Validate<Action>}.php}` | ForceOverwrite | DTO (`readonly`), Orchestrator (no `Handler` suffix), DTO→entity mapping, validators |
| Query tree | `<BC>/<Agg>/Platform/Query/{<Agg>,<Agg>ListFilter}.php` + `Query/Handler/Get<Action>Handler.php` (**WITH `Handler` suffix**) | ForceOverwrite | DTOs + handlers |
| Event tree | `<BC>/<Agg>/Platform/Event/{<Agg>{Created,Removed,Updated,…}.php, <Agg>EventRouter.php}` | ForceOverwrite | DTOs (identifier first, `occurredAt` last); router has empty `on<Event>()` — override under `{Agg}/Event/` |
| Repository tree | `<BC>/<Agg>/Platform/Repository/{<Agg>Repository,Query<Agg>,Transform<Agg>,Validate<Agg>,Persist<Agg>,Query<Agg>{Additions,List}}.php` | ForceOverwrite | Read / write pipeline base; custom queries (generic CRUD = pipeline) |
| Dev-Code root | `<BC>/<Agg>/` (parallel to `Platform/`) | Developer-owned | VOs, services, overrides, custom API fragments, new commands/queries — only place the developer writes |

**Two file modes from the generator:**

- **ForceOverwrite** — rewritten on every build. All Platform/ files plus Domain/BC facades and `<Domain>/Api/{openapi,asyncapi}.yaml`/`service.proto`.
- **CreateIfNotExists** — written once, never touched again. The aggregate stub-slot `{Agg}/{Agg}.php` and `Config/.env*` use this.

**Stub-slot mechanics:** On first build, the generator emits `{Agg}/{Agg}.php` with body `final class <Agg> extends \…\<BC>\<Agg>\Platform\<Agg> {}`. Public FQN `<Domain>\<BC>\<Agg>\<Agg>` stays stable; you may add methods inside the stub afterwards — rebuilds leave the file alone (CreateIfNotExists). Everything the full Platform facade does is inherited.

**BC facade caches nothing** (each `->{agg}()` is a fresh `handle()` resolve). **Aggregate facade caches registries** internally (under `Platform/`). **Never** instantiate handlers directly (V2/V3).

### 2. ClassVersion resolution

`$this->handle(ClassName::class)` resolves an override through the reader `LoadClassFromExtensions`, called with `segmentNames: ['', 'Platform']`. The reader walks a 7-stage lookup chain. First hit wins; later stages are fallback only.

For active version `v_n` (returned by `version()` on the Domain facade — see below), the resolution order is:

1. `<Agg>\v_n\X` — Dev-Override v_n (aggregat-root `{Agg}/v{n}/`)
2. `<Agg>\Platform\v_n\X` — Platform v_n (generated under `{Agg}/Platform/v{n}/`)
3. `<Agg>\v_{n-1}\X` — Dev-Override of older version (fallback chain)
4. `<Agg>\Platform\v_{n-1}\X` — Platform older version
5. `<Agg>\X` — Dev-Baseline (stub-slot for the facade itself; otherwise a direct override at the aggregate root)
6. `<Agg>\Platform\X` — Platform-Baseline (the regenerated class under `{Agg}/Platform/`)
7. Fallback: `class_exists($input)` check, otherwise `InvalidArgumentException`.

Stages 1–4 only run when `version()` returns a non-empty label and `ClassVersionConfig` lists the version with its fallback chain. Stages 5–6 always run. The `''` segment in `segmentNames` produces stages 1, 3, 5; the `'Platform'` segment produces stages 2, 4, 6 — interleaved per version.

```
Generator base (always under Platform/):
  MeterDevice\Counter\Counter\Platform\Command\Handler\Step\HydrateCreateCounterEntities

Dev baseline (override at aggregate root, no version):
  MeterDevice\Counter\Counter\Command\Handler\Step\HydrateCreateCounterEntities
  ↳ extends MeterDevice\Counter\Counter\Platform\Command\Handler\Step\HydrateCreateCounterEntities

Dev versioned (e.g. v2):
  MeterDevice\Counter\Counter\v2\Command\Handler\Step\HydrateCreateCounterEntities
  ↳ extends the baseline above (or directly the Platform base)

Platform versioned (Generator output for v2 — emitted only when a v2 generator variant exists):
  MeterDevice\Counter\Counter\Platform\v2\Command\Handler\Step\HydrateCreateCounterEntities
```

Namespace = `<Domain>\<BC>\<Agg>\…`. Both `Counter` segments above are real: first is BC, second is Aggregate. Dev paths sit directly under the aggregate (`<Agg>\…` or `<Agg>\v{n}\…`); generator paths always carry the `Platform` segment (`<Agg>\Platform\…` or `<Agg>\Platform\v{n}\…`).

**Baseline vs. versioned** — pick one:

- **Baseline** (versionless) covers the 80 % case: "I want this logic replaced, period." No `ClassVersionConfig` setup. Place the file directly at the aggregate root (mirroring the Platform sub-path), `extends` the Platform class, done.
- **Versioned** (`v1`, `v2`, …) for tenant / feature-flag scenarios. Activated by overriding `version()` on the Domain facade.

Setup for versioned resolution (once per domain, only when you need it):

```php
$config = new ClassVersionConfig(
    version:   ['v1' => ['v1'], 'v2' => ['v2'], 'v3' => ['v3']],
    fallbacks: ['v3' => ['v2', 'v1'], 'v2' => ['v1']]
);
```

Then activate in the Domain facade:

```php
final class MeterDevice extends JardisApp
{
    protected function version(): string
    {
        return 'v2';   // or derive from tenant, feature flag, …
    }
}
```

Override skeleton (baseline example — null setup, file lives directly at the aggregate root):

```php
declare(strict_types=1);
namespace MeterDevice\Counter\Counter\Command\Handler\Step;

use MeterDevice\Counter\Counter\Platform\Command\Handler\Step\HydrateCreateCounterEntities as Base;
use MeterDevice\Counter\Counter\Platform\Command\Counter as CommandCounter;
use MeterDevice\Counter\Counter\Platform\Aggregate\Counter;
use MeterDevice\Counter\Counter\ValueObject\ObisCode;

final class HydrateCreateCounterEntities extends Base
{
    protected function hydrateCounterRegister(Counter $handler, CommandCounter $counter): void
    {
        parent::hydrateCounterRegister($handler, $counter);
        foreach ($counter->counterRegister as $reg) {
            $this->handle(ObisCode::class, $reg->obis);
        }
    }
}
```

Versioned variant — same class, namespace adds `\v2\` between `<Agg>` and the rest:

```php
namespace MeterDevice\Counter\Counter\v2\Command\Handler\Step;
```

### 3. Versionierungs-Modell

Versions in Jardis are about behaviour, not data shape. Five Leitsätze govern when to reach for `v{N}`, when to extend the schema, and when to spin up a new aggregate.

**Leitsätze:**

- **Additiv geht vor Version.** New nullable fields, new enum members, new optional tables go into the base schema without bumping a version.
- **Version ändert Verhalten, nie API.** `v1` and `v2` of the same aggregate share identical Commands / Queries / Events / Payloads. Only the implementation differs. There is exactly one `openapi.yaml` per aggregate.
- **Datenbruch = neues Aggregat.** A removed field, flipped type, or shifted semantic of a load-bearing field is honest enough to warrant `<Agg>V4` — its own name, its own tree, its own spec, its own entities.
- **Code-Rettung läuft über Abstraktion, nicht über Mechanik.** When data breaks, behaviour is rescued by Domain Services (`{Agg}/Service/`), not by version-merge tricks. Entity-agnostic logic survives any data break; entity-bound logic on the broken fields does not.
- **Eine `openapi.yaml` pro Aggregat — punkt.** No v{N}-API matrices. No delta-merge specs.

**Daumenregel — when to use what:**

| Change | Path |
|---|---|
| New nullable column / new enum member / new optional relation | Schema additiv erweitern, no version. Goes into base definition; FieldMap learns the new key; rebuild. |
| New business rule, different calculation, tenant variant, tightened validation | **`v{N}` Dev-Override** under `{Agg}/v{N}/`. Activate via `version()` on the Domain facade. Spec stays invariant. |
| Field removed, type flips, semantic of load-bearing field changes | **Neues Aggregat `<Agg>V4`**, eigener Tree, eigene Spec. Generator regeneriert separat. |

**Service-Schicht-Hinweis.** Versionsfreier, entity-agnostischer Code gehört nach `{Agg}/Service/` — direkt am Aggregat-Root, keine `v{N}/`-Variante. Diese Service-Schicht wird von `v1` / `v2` / `v3` UND einem späteren `<Agg>V4` genutzt. Die Konsequenz von Säule 4 (`rules-architecture` — Data-Behavior-Separation): Logik, die nicht entity-typ-abhängig sein muss, jetzt schon als Service extrahieren — sie überlebt jeden Datenbruch.

### 4. Override targets

All paths are **directly under the aggregate**, mirroring the corresponding generator path under `Platform/`. Choose `<BC>/<Agg>/...` for a baseline override, or `<BC>/<Agg>/v{N}/...` for a versioned one.

| Concern | Override path (baseline) |
|---|---|
| Command Step (Hydrate / Validate / Build) | `<BC>/<Agg>/Command/Handler/Step/` |
| Pre-Persist Validator (`Validate<Action>`) | `<BC>/<Agg>/Command/Validation/` |
| Aggregate Step (Set / Add / Remove) | `<BC>/<Agg>/Aggregate/Step/` |
| QueryAdditions / Transform / Validate / Persist | `<BC>/<Agg>/Repository/` |
| Per-aggregate Registries | `<BC>/<Agg>/Api/` |
| Event Router (`on<Event>` bodies) | `<BC>/<Agg>/Event/` (override `<Agg>EventRouter` extending `Platform\Event\<Agg>EventRouter`) |

**Domain-level overrides** — Entity Validators (`<Domain>/Entity/Validation/`) and FieldMap (`<Domain>/Api/FieldMap.php`) live outside an aggregate and are regenerated on build. For domain-level fachlogik either: (a) put the logic into a Domain Service under `<BC>/<Agg>/Service/` and call it from the generated validator via `$this->handle(...)`, or (b) override the respective aggregate-local entry point (Validate / Hydrate step) at the aggregate root. Domain-level `v2/` subdirs do not exist.

**API customisation** (REST paths, AsyncAPI channels, Proto services) flows through `*.custom.{yaml,proto}` files. These live at aggregate level (`<BC>/<Agg>/Api/openapi.custom.yaml`, `…/asyncapi.custom.yaml`, `…/service.custom.proto`) or at domain level (`<Domain>/Api/openapi.custom.yaml`, …). The fragment assembler merges them at build time and never touches them — fragments (`*.fragment.*`) belong to the builder, customs (`*.custom.*`) to you.

### 5. Prohibitions (V1–V12)

| # | Rule |
|---|---|
| V1 | Never edit anything under `{Agg}/Platform/`. Anything directly under `{Agg}/` (parallel to `Platform/`) is yours. |
| V2 | No `new` for Services / VOs / Entities — only `Exception`, `RuntimeException`, `DateTimeImmutable`, `DateTime` |
| V3 | Never bypass `handle()` |
| V4 | No logic in Command / Query Handler Orchestrators — logic belongs in Steps |
| V5 | Never access Aggregate internals from outside — only via AggregateHandler |
| V6 | No cross-BC imports — communicate via Domain Service |
| V7 | No traits, no `abstract` (except Exception hierarchies) |
| V8 | Never skip validation in Command → Entity → Aggregate chain |
| V9 | No persistence in Domain Services — only via `Repository::persist()` |
| V10 | No state in BoundedContext subclasses (transient per call) |
| V11 | No business methods on Entities |
| V12 | Responses are arrays via FieldMapper, not typed DTOs |

### 6. Decision tree — "I need to…"

| Need | Action | Pattern |
|---|---|---|
| Validate a domain concept (OBIS, IBAN) | VO in `{BC}/{Agg}/ValueObject/`, instantiate via `handle()` in Step | Value Object |
| Load data from external API | Domain Service in `{BC}/{Agg}/Service/`, call in Step override | Adapter |
| Read from another BC | Domain Service — never import directly | Adapter |
| Check Aggregate consistency | Baseline override of `Validate{Root}` in `{BC}/{Agg}/Repository/`, `parent::__invoke()` first | Decorator |
| Convert DTO field before persist | Baseline override of `Hydrate*` or `Build*` Step in `{BC}/{Agg}/Command/Handler/Step/`, `parent::__invoke()` first | Decorator |
| Add calculated field in query response | Baseline override of `Transform*` in `{BC}/{Agg}/Repository/` | Decorator |
| Add JOIN to a query | Baseline override of `QueryAdditions*` (protected method) in `{BC}/{Agg}/Repository/` | Decorator |
| New domain operation | New Command DTO + Handler + Registry override, all under `{BC}/{Agg}/Command/` + `{BC}/{Agg}/Api/` | Facade + Factory |
| New query variant | New Query DTO + Handler + Registry override, all under `{BC}/{Agg}/Query/` + `{BC}/{Agg}/Api/` | Facade + Factory |
| Tighten field validation (single field) | Inject via Domain Service called from `{BC}/{Agg}/Command/Handler/Step/` override | Decorator |
| Extend FieldMap | Inject via aggregate-level Hydrate / Transform override in `{BC}/{Agg}/`; FieldMap stays generator-owned | Decorator |
| Emit new event | Emit in baseline override of Persist step in `{BC}/{Agg}/Repository/` | Decorator |
| Tenant-specific variant of any override | Put file under `{BC}/{Agg}/v{N}/...` instead of `{BC}/{Agg}/...` and activate via `version()` on the Domain facade | ClassVersion |

### 7. New-code locations — everything lives parallel to `Platform/`

```
<Domain>/<BC>/<Agg>/                         ← Dev-Code (parallel to Platform/)
├── ValueObject/                             ← VOs (OBIS, MeterNumber)
├── Service/                                 ← external data, cross-BC, calculations
├── Workflow/                                ← long-running flows
├── Command/{<NewCmd>.php, Handler/, Handler/Step/, Validation/}
├── Query/{<NewQry>.php, Handler/}
├── Aggregate/Step/                          ← Set/Add/Remove overrides
├── Repository/                              ← Q/T/V/P overrides
├── Api/{<Agg>Commands.php, openapi.custom.yaml, asyncapi.custom.yaml, service.custom.proto, …}
├── Event/<Agg>EventRouter.php               ← extends Platform\Event\<Agg>EventRouter
├── v1/, v2/, …                              ← versionierte Varianten (mirror layout above)
└── Platform/                                ← ★ GENERIERT, NIE EDITIEREN
```

All developer code — new classes **and** overrides — lives directly under `{BC}/{Agg}/`, parallel to the generated `{Agg}/Platform/` tree. The aggregate stub-slot `{Agg}/{Agg}.php` is also Dev-owned after first creation; everything inheritance-chain-related already extends Platform via the stub.

### 8. Seven implementation levels

No skipping, no parallel levels. PHPStan L8 + tests + review green between levels.

| # | Level | Where | Anchor |
|---|---|---|---|
| 1 | VOs + business validation | `{BC}/{Agg}/ValueObject/` | `jardissupport/validation` |
| 2 | Custom Commands | `{BC}/{Agg}/Command/` (DTO + Handler + Registry baseline in `{Agg}/Api/`) | `jardiscore/foundation` |
| 3 | Custom Queries | `{BC}/{Agg}/Query/` (DTO + Handler + Registry baseline in `{Agg}/Api/`) | `jardissupport/dbquery` |
| 4 | Domain Services | `{BC}/{Agg}/Service/` | `jardiscore/foundation`, `jardisadapter/dbconnection` |
| 5 | Workflows | `{BC}/{Agg}/Workflow/` | `jardissupport/workflow` |
| 6 | Event transport | `{BC}/{Agg}/Event/<Agg>EventRouter.php` (baseline override, extends `Platform\Event\<Agg>EventRouter`) | `jardisadapter/messaging` |
| 7 | Non-functional (cache, logging) | Query Handler override under `{BC}/{Agg}/Query/Handler/`, DomainKernel | `jardisadapter/cache`, `jardisadapter/logger` |

### 9. Event transport — `<Agg>EventRouter.php`

The generator emits the base router at `<Domain>/<BC>/<Agg>/Platform/Event/<Agg>EventRouter.php` (ForceOverwrite, typed on `EventListenerRegistryInterface`). It carries one empty `protected function on<Event>(EventListenerRegistryInterface $registry): void {}` per Domain Event of this aggregate, with the AsyncAPI channel key as a comment in front of each method (= topic / routing key).

To wire transport, create a baseline override at `<BC>/<Agg>/Event/<Agg>EventRouter.php` that **extends** the Platform class and fills the `on<Event>()` bodies. For tenant- or feature-flag variants, mirror the same file under `<BC>/<Agg>/v{N}/Event/<Agg>EventRouter.php`. The `LoadClassFromExtensions` reader picks the highest-priority router according to the active `version()` (§2).

**Foundation wiring.** The generated Domain facade overrides `eventDispatcher()` and feeds every aggregate router of the domain (alphabetically by BC, then by Aggregate) **directly** into the `EventDispatcherHandler`:

```php
return (new EventDispatcherHandler())(
    new \MeterDevice\Counter\Counter\Platform\Event\CounterEventRouter(),
    new \MeterDevice\Counter\Gateway\Platform\Event\GatewayEventRouter(),
    // …
);
```

Direct `new` is mandatory here because `handle()` lives only on `BoundedContext`, not on `JardisApp` / `DomainApp`. The reader-based override resolution still works because the generated router classes are loaded through PHP's class loader after this hook returns — the `eventDispatcher()` override merely registers the router instances, while listener resolution flows through `handle()` inside each `on<Event>()` body. **Kernel platform: no override** — wire manually in your `DomainApp` subclass.

**Kafka / RabbitMQ / Redis** via `jardisadapter/messaging`:

```php
namespace MeterDevice\Counter\Counter\Event;

use MeterDevice\Counter\Counter\Platform\Event\CounterEventRouter as Base;
use MeterDevice\Counter\Counter\Platform\Event\CounterCreated;
use JardisSupport\Contract\EventDispatcher\EventListenerRegistryInterface;

final class CounterEventRouter extends Base
{
    protected function onCounterCreated(EventListenerRegistryInterface $registry): void
    {
        $registry->listen(CounterCreated::class, function (CounterCreated $event): void {
            $this->handle(MessagingService::class)
                ->publish('meterdevice.counter.counter.created', $event);
        });
    }
}
```

**HTTP webhook** via `jardisadapter/http`:

```php
protected function onCounterCreated(EventListenerRegistryInterface $registry): void
{
    $registry->listen(CounterCreated::class, function (CounterCreated $event): void {
        $this->handle(HttpClient::class)->post($webhookUrl, ['json' => (array) $event]);
    });
}
```

**In-process** (projection, audit trail):

```php
protected function onCounterCreated(EventListenerRegistryInterface $registry): void
{
    $registry->listen(
        CounterCreated::class,
        fn (CounterCreated $event) => $this->handle(CounterProjector::class)->onCreated($event),
        priority: 10,   // higher = earlier
    );
}
```

**Rules:**

- Never `new` publishers / clients inside the listener — always `handle()` (V2 / V3).
- Listener runs **synchronously** with dispatch. Long operations → queue consumer, not here. Listener only hands off.
- Listener exceptions bubble to the handler's outer `try/catch` and flip the response to 500. Wrap `try/catch` inside the listener only if "event delivery must not roll back the command".
- Priority ordering: lower = later.

### 10. Constructing `DomainResponse`

In Custom Command / Query handlers extending `<Domain>Context`:

```php
public function __invoke(MyDto $dto): DomainResponseInterface
{
    try {
        // business logic via $this->handle(...)
        $this->result()->setData(['someIdentifier' => $id]);
        $this->result()->addEvent($event);

        return $this->handle(DomainResponseTransformer::class)
            ->transform($this->result(), ResponseStatus::Created);
    } catch (\Throwable $e) {
        $this->result()->addError($e->getMessage());
        return $this->handle(DomainResponseTransformer::class)
            ->transform($this->result(), ResponseStatus::InternalError);
    }
}
```

| Status | Use |
|---|---|
| `Success` (200) | Read with data |
| `Created` (201) | Write created aggregate |
| `NoContent` (204) | Write succeeded, empty body |
| `ValidationError` (400) | DTO / invariant validation failed |
| `Unauthorized` / `Forbidden` (401/403) | Raised by middleware, not here |
| `NotFound` (404) | Target absent |
| `Conflict` (409) | State machine rejects transition |
| `InternalError` (500) | Unexpected `Throwable` |

`$this->result()` helpers:

- `setData(array)` — replace payload (last writer wins)
- `addData(string $key, mixed $value)` — additive under key
- `addError(string $message)` — accumulates → `errors: [...]`
- `addEvent(object $event)` — accumulates. Dispatch happens **separately**: `$this->resource()->eventDispatcher()?->dispatch($event)` (see generated `CreateCounter`)

Contract / enum / transformer live in `core-kernel`.

### 11. Phase-3 cookbook

Paths assume `MeterDevice\Counter\Counter`. All files land directly under `{BC}/{Agg}/`, parallel to `Platform/`.

**Recipe 1 — VO used in Hydrate step (baseline override, null setup)**

VO `src/MeterDevice/Counter/Counter/ValueObject/ObisCode.php`:

```php
namespace MeterDevice\Counter\Counter\ValueObject;

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

Baseline override `Command/Handler/Step/HydrateCreateCounterEntities.php`:

```php
namespace MeterDevice\Counter\Counter\Command\Handler\Step;

use MeterDevice\Counter\Counter\Platform\Command\Handler\Step\HydrateCreateCounterEntities as Base;
use MeterDevice\Counter\Counter\ValueObject\ObisCode;

final class HydrateCreateCounterEntities extends Base
{
    protected function hydrateCounterRegister(Counter $handler, CommandCounter $counter): void
    {
        parent::hydrateCounterRegister($handler, $counter);
        foreach ($counter->counterRegister as $reg) {
            $this->handle(ObisCode::class, $reg->obis);
        }
    }
}
```

Create file, run test: bad OBIS → `ValidationError` + error message. No `ClassVersionConfig` needed — `handle()` finds the baseline override (stage 5 in §2) automatically.

For a tenant-specific variant, put the same file under `{Agg}/v2/Command/Handler/Step/…` and switch it on by overriding `version()` on the Domain facade.

**Recipe 2 — Domain Service for external lookup**

`Service/ResolveMeterLocationName.php`:

```php
namespace MeterDevice\Counter\Counter\Service;

final class ResolveMeterLocationName
{
    public function __construct(private readonly HttpClientInterface $http) {}
    public function __invoke(string $meterLocationIdentifier): string
    {
        $response = $this->http->get("/meter-locations/{$meterLocationIdentifier}");
        return (string) ($response['name'] ?? $meterLocationIdentifier);
    }
}
```

Call from a baseline override of a Build step via `$this->handle(ResolveMeterLocationName::class, $dto->meterLocationIdentifier)`. V9: service reads only, no persistence.

**Recipe 3 — New Command next to generated ones**

Three files directly under the aggregate root:

1. `Command/DeactivateCounter.php` — `readonly class` with `identifier`, `deactivatedAt`.
2. `Command/Handler/DeactivateCounter.php` — `extends <Domain>Context`, loads via `handle(CounterRepository::class)->…`, mutates via aggregate step, persists, emits event. Response per §10.
3. `Api/CounterCommands.php` extends the Platform `…\Platform\Api\CounterCommands`, adds `deactivateCounter(DeactivateCounter $dto): DomainResponseInterface`. Baseline registry override — `handle()` picks it up.

Add the OpenAPI path in `Api/openapi.custom.yaml` — fragment assembler merges at build time.

**Recipe 4 — Event to Kafka**

Create `Event/CounterEventRouter.php` extending the Platform router:

```php
namespace MeterDevice\Counter\Counter\Event;

use MeterDevice\Counter\Counter\Platform\Event\CounterEventRouter as Base;
use MeterDevice\Counter\Counter\Platform\Event\CounterCreated;
use JardisSupport\Contract\EventDispatcher\EventListenerRegistryInterface;

final class CounterEventRouter extends Base
{
    protected function onCounterCreated(EventListenerRegistryInterface $registry): void
    {
        $registry->listen(CounterCreated::class, function (CounterCreated $event): void {
            $this->handle(MessagingService::class)
                ->publish('meterdevice.counter.counter.created', $event);
        });
    }
}
```

Baseline override — `handle()` picks it up when the Domain facade wires the router. Test via `EventCollector` fake (see `rules-testing` §6). For a tenant variant, put the same file under `{Agg}/v2/Event/CounterEventRouter.php`.

### 12. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `LogicException: Cannot resolve ClassName` | BC vs. Aggregate segment swapped in namespace | Namespace is `<Domain>\<BC>\<Agg>\…` — BC and Aggregate are two segments even when they share a name |
| Baseline override at the aggregate root not picked up | Wrong namespace — no `Platform` segment for the override (Platform is generator-only) | `namespace <Domain>\<BC>\<Agg>\<GeneratorSubpath>` (no `Platform`); inside the file, `extends \…\Platform\<GeneratorSubpath>\<Class>` |
| Versioned override (`v2/…`) ignored | Domain facade doesn't return `'v2'` from `version()`, or `ClassVersionConfig` is missing the entry | Override `protected function version(): string` on the `<Domain>.php` facade; ensure `ClassVersionConfig` lists `v2` with its fallback chain — §2 |
| `Error: Cannot instantiate abstract class` / "Service X not in container" | Direct `new` bypassing `handle()` (V2 / V3) | Replace with `$this->handle(X::class, ...)` |
| `on<Event>()` edit gone after rebuild | Bodies were filled directly in the Platform `<Agg>EventRouter.php` | Move the body into `<BC>/<Agg>/Event/<Agg>EventRouter.php` extending `…\Platform\Event\<Agg>EventRouter` — generator only rewrites `Platform/` |
| Edit under `{Agg}/Platform/` gone after rebuild | `Platform/` is `ForceOverwrite` — every build truncates and rewrites it | Move the edit to the matching path directly under `{Agg}/` (parallel to `Platform/`), `extends` the Platform class |
| Stub-slot `{Agg}/{Agg}.php` overwritten with empty body / methods gone | Stub-slot is `CreateIfNotExists` and should never be touched by the generator after the initial seed | File a bug — generator must not overwrite an existing stub-slot. Restore the file from VCS; do not self-help by re-editing without diagnosing |
| `Cannot import OtherBC\...` review blocker | V6 violation | Add Domain Service, call other BC via `handle()` |
| OpenAPI / AsyncAPI path duplicated | Two aggregates emit same schema name | Assembler scopes on collision — wire `BuildOptions.CollidingEntityNames` via `definition.CollidingEntityNames(domainDir)` |
| Custom-slot YAML overwritten on rebuild | Named `*.fragment.yaml` (Builder-owned) | Rename to `*.custom.yaml` — Builder never touches `*.custom.*` (inside `<BC>/<Agg>/Api/` or at domain level) |
| `getData()` empty after `addData()` in override | Override skipped `parent::__invoke(...)` — parent's `setData` ran against a different `$this->result()` | Always `parent::__invoke(...)` first, then augment |
| Listener throws → command rolls back | Default: listener exception flips response to 500 | Wrap listener body in `try/catch` only if delivery failure must not roll back — §9 |

### 13. Anchors

- `rules-architecture` (Säule 4 Data-Behavior-Separation drives §3 Service-Schicht-Hinweis), `rules-patterns`, `rules-testing`
- Definition YAMLs: `tools-definition`
- `DomainResponse`, `ResponseStatus`, `DomainResponseTransformer`: `core-kernel`
- `MessagingService`, `MessagePublisher`: `adapter-messaging`
- `EventListenerRegistryInterface`, `EventDispatcher`, `EventCollector`: `adapter-eventdispatcher`
- `HttpClientInterface`, `HttpClient`: `adapter-http`
