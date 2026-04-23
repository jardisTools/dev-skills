---
name: platform-implementation
description: Extending Jardis Designer-generated code with fachlogik — one Extensions/ directory per aggregate holds all developer-owned code (VOs, services, overrides, custom API fragments). Everything outside Extensions/ is regenerated every build. ClassVersion v{N} override mechanism, V1–V12 prohibitions, decision tree, seven implementation levels, event transport, DomainResponse construction, Phase-3 cookbook, troubleshooting.
zone: post-active
prerequisites: []
next: []
---

### 0. DDD positioning

Jardis uses DDD vocabulary (BC, Aggregate, Domain Event, Repository, VO, Ubiquitous Language) under its own axioms — Closure-Orchestrator (`rules-architecture` §3) and model-driven generation. Closer to Functional DDD (Wlaschin) than to Rich Domain Model (Evans).

| Rule | Consequence |
|---|---|
| **V11** — no business methods on entities | Behaviour lives in Steps (`Aggregate/Step/`, `Command/Handler/Step/`) and Domain Services. Invariants protected by the **pipeline**, not by entity methods. |
| **V12** — responses are `DomainResponse` arrays | One serialisation surface across HTTP / CLI / queue. Typing happens at OpenAPI/Proto layer. |
| **4-hop call chain** | `$app->{bc}()->{agg}()->{command\|query\|event}()->{method}($dto)` — taxonomy is discoverable, `handle()` / ClassVersion resolution anchors at each level. |

Upheld without compromise: aggregates are transaction boundaries with a single root; BCs are strict language boundaries (V6). Rich-Model instinct → reach for a Step override (`Extensions/Aggregate/Step/`, `Extensions/Command/Validation/`), a VO (`Extensions/ValueObject/`), or a Domain Service (`Extensions/Service/`) instead of entity methods.

### 1. Generated baseline

Four-level facade chain: Domain → BC → Aggregate → Registry. Example: aggregate `Counter` in BC `Counter` in domain `MeterDevice`.

**The one rule:** Everything outside `<Agg>/Extensions/` is regenerated every build. Everything inside `<Agg>/Extensions/` is never touched by the builder.

```
src/                                              ← namespace root = <Domain>
├── <Domain>.php                                  ← Domain facade — extends JardisApp | DomainApp
├── Api/
│   ├── FieldMap.php                              ← central FieldMap (one method per entity)
│   ├── openapi.yaml                              ← REST spec
│   ├── asyncapi.yaml                             ← Domain-event spec
│   └── service.proto                             ← gRPC spec
├── Config/
│   ├── .env
│   ├── .env.database, .env.database.{dev,test}
│   └── .env.logger, .env.logger.{dev,test}
├── Entity/                                       ← Domain entities (shared across BCs)
│   ├── <Entity>.php                              ← #[Table], #[Column], snapshot
│   └── Validation/
│       └── <Entity>Validator.php                 ← Skeleton
├── Foundation/
│   └── <Domain>Context.php                       ← extends BoundedContext
└── <BC>/
    ├── <BC>.php                                  ← BC facade — accessors only: $bc->counter()
    └── <Agg>/
        ├── <Agg>.php                             ← Aggregate facade — command()/query()/event() (cached)
        ├── Api/
        │   ├── <Agg>Commands.php                 ← Command registry — public entry
        │   ├── <Agg>Queries.php                  ← Query registry
        │   └── <Agg>Events.php                   ← Event registry
        ├── Aggregate/
        │   ├── <Agg>.php                         ← AggregateRoot
        │   ├── Entity/<Entity>.php               ← extends Domain entity
        │   └── Step/{Set,Add,Remove}<Entity>.php ← Skeleton
        ├── Command/
        │   ├── <Agg>.php                         ← Create DTO
        │   ├── {Set,Add,Remove}<Entity>.php      ← Operation DTOs
        │   ├── Handler/                          ← Command orchestrators — NO `Handler` suffix
        │   │   ├── Create<Agg>.php               ← Skeleton
        │   │   ├── {Set,Add,Remove}<Entity>.php
        │   │   └── Step/
        │   │       ├── Build<Action>Data.php     ← Skeleton
        │   │       └── HydrateCreate<Agg>Entities.php
        │   └── Validation/
        │       ├── <DTO>Validator.php            ← Skeleton
        │       └── Validate<Action>.php          ← Skeleton
        ├── Query/
        │   ├── <Agg>.php                         ← Query DTO
        │   ├── <Agg>ListFilter.php
        │   └── Handler/Get<Agg>{,List}Handler.php ← Skeleton — WITH `Handler` suffix
        ├── Event/                                ← Event DTOs + router
        │   ├── <Agg>{Created,Removed,Updated}.php
        │   ├── <Agg><Entity>{Added,Removed,Updated}.php
        │   └── <Agg>EventRouter.php              ← Generated (override via Extensions/)
        ├── Repository/                           ← Flat, no sub-dirs
        │   ├── <Agg>Repository.php
        │   ├── Query<Agg>.php
        │   ├── Query<Agg>Additions.php
        │   ├── Transform<Agg>.php
        │   ├── Validate<Agg>.php
        │   ├── Persist<Agg>.php
        │   └── Query<Agg>List.php
        └── Extensions/                           ← ★ DEVELOPER-OWNED — builder never writes here
            └── .gitkeep                          ← seeded on first build; add any structure below
```

| Class | Path | Status | Purpose |
|---|---|---|---|
| Domain Facade | `<Domain>.php` | Generated | `extends JardisApp\|DomainApp`; event-router wiring |
| Domain API specs | `Api/{openapi,asyncapi}.yaml`, `Api/service.proto` | Generated | REST / event / gRPC for whole domain (Custom slots via `*.custom.*`) |
| FieldMap | `Api/FieldMap.php` | Generated | One method per entity; `toColumns()` / `fromAggregate()` |
| Config | `Config/.env*` | Generated (overwriteable) | Per-domain ENV defaults |
| Domain Entity | `Entity/<Entity>.php` | Generated | `#[Table]`, `#[Column]`, `__snapshot` |
| Entity Validator | `Entity/Validation/<Entity>Validator.php` | Skeleton | Field-level validation |
| Domain Context | `Foundation/<Domain>Context.php` | Generated | `extends BoundedContext` |
| BC Facade | `<BC>/<BC>.php` | Generated | `extends <Domain>Context`; accessors only, **no caching** |
| Aggregate Facade | `<BC>/<Agg>/<Agg>.php` | Generated | Exposes `command()`, `query()`, `event()` with internal caching |
| API Registries | `<BC>/<Agg>/Api/<Agg>{Commands,Queries,Events}.php` | Generated | Public entry for this aggregate |
| Aggregate Root | `<BC>/<Agg>/Aggregate/<Agg>.php` | Skeleton | Aggregate invariants |
| Aggregate Entity | `<BC>/<Agg>/Aggregate/Entity/<Entity>.php` | Generated | Adds ONE/MANY accessors |
| Aggregate Step | `<BC>/<Agg>/Aggregate/Step/{Set,Add,Remove}<Entity>.php` | Skeleton | Mutation rules |
| Command DTO | `<BC>/<Agg>/Command/<Action>.php` | Generated | `readonly`; payload only |
| Command Handler | `<BC>/<Agg>/Command/Handler/<Action>.php` | Skeleton | Orchestrator — **no `Handler` suffix** |
| Command Step | `<BC>/<Agg>/Command/Handler/Step/{Build*Data,Hydrate*Entities}.php` | Skeleton | DTO → entity-data mapping |
| DTO Validator | `<BC>/<Agg>/Command/Validation/<DTO>Validator.php` | Skeleton | DTO field validation |
| Pre-Persist Validator | `<BC>/<Agg>/Command/Validation/Validate<Action>.php` | Skeleton | Cross-field / aggregate invariants |
| Query DTO | `<BC>/<Agg>/Query/{<Agg>,<Agg>ListFilter}.php` | Generated | `readonly` |
| Query Handler | `<BC>/<Agg>/Query/Handler/Get<Action>Handler.php` | Skeleton | **WITH `Handler` suffix** |
| Event DTO | `<BC>/<Agg>/Event/<Agg>{Created,Removed,Updated,...}.php` | Generated | `readonly`; identifier first, `occurredAt` last |
| Event Router | `<BC>/<Agg>/Event/<Agg>EventRouter.php` | Generated | Base `on<Event>()` empty; override via Extensions/ |
| Repository | `<BC>/<Agg>/Repository/<Agg>Repository.php` | Skeleton | Custom queries (generic CRUD = pipeline) |
| Repository Steps | `<BC>/<Agg>/Repository/{Query,Transform,Validate,Persist,QueryAdditions,QueryList}<Agg>.php` | Skeleton | Read / write pipeline |
| Extensions root | `<BC>/<Agg>/Extensions/` | Developer-owned | VOs, services, overrides, custom API fragments — only place the developer writes |

**File types:**

- **Generated** — `Generated by *Generator` docblock. Rewritten on every build. Override via ClassVersion inside `Extensions/` (§2).
- **Skeleton** — empty `__invoke()` awaiting fachlogik. Builder leaves alone on rerun (written once, then detected as developer-occupied).
- **Extensions** — anything under `<Agg>/Extensions/`. Builder never writes here after the initial `.gitkeep`. Structure is entirely up to the developer.

**BC facade caches nothing** (each `->{agg}()` is a fresh `handle()` resolve — properties cannot merge across multi-aggregate BCs). **Aggregate facade caches registries** internally (generated atomically). **Never** instantiate handlers directly (V2/V3).

### 2. ClassVersion resolution via Extensions/

`$this->handle(ClassName::class)` resolves an override by looking inside the aggregate's `Extensions/` directory. The reader (`LoadClassFromExtensions`) walks three steps:

1. **If a runtime version is active** (e.g. `'v1'`) — try `{Agg}\Extensions\v{N}\X\Y\Z` for each element of the fallback chain. First hit wins.
2. **Versionless baseline override** — try `{Agg}\Extensions\X\Y\Z`. Null-setup: create the file, it's found.
3. **Generator base** — fall back to `{Agg}\X\Y\Z`.

```
Base:        MeterDevice\Counter\Counter\Command\Handler\Step\HydrateCreateCounterEntities
Baseline:    MeterDevice\Counter\Counter\Extensions\Command\Handler\Step\HydrateCreateCounterEntities
Versioned:   MeterDevice\Counter\Counter\Extensions\v1\Command\Handler\Step\HydrateCreateCounterEntities
```

Namespace = `<Domain>\<BC>\<Agg>\…`. Both `Counter` segments above are real: first is BC, second is Aggregate. The `Extensions` segment is inserted immediately after the aggregate namespace; everything after it mirrors the generator path.

**Baseline vs. versioned** — pick one:

- **Baseline** (versionless) covers the 80 % case: "I want this logic replaced, period." No `ClassVersionConfig` setup. Place file, done.
- **Versioned** (`v1`, `v2`, …) for tenant / feature-flag scenarios. Activated by overriding `version()` on the domain facade (`extends JardisApp|DomainApp`).

Setup for versioned resolution (once per domain, only when you need it):

```php
$config = new ClassVersionConfig(
    version:   ['v1' => ['v1'], 'v2' => ['v2'], 'v3' => ['v3']],
    fallbacks: ['v3' => ['v2', 'v1'], 'v2' => ['v1']]
);
```

Then activate in the domain facade:

```php
final class MeterDevice extends JardisApp
{
    protected function version(): string
    {
        return 'v2';   // or derive from tenant, feature flag, …
    }
}
```

Override skeleton (baseline example — null setup):

```php
declare(strict_types=1);
namespace MeterDevice\Counter\Counter\Extensions\Command\Handler\Step;

use MeterDevice\Counter\Counter\Command\Handler\Step\HydrateCreateCounterEntities as Base;
use MeterDevice\Counter\Counter\Command\Counter as CommandCounter;
use MeterDevice\Counter\Counter\Aggregate\Counter;
use MeterDevice\Counter\Counter\Extensions\ValueObject\ObisCode;

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

Versioned variant — same class, namespace adds `Extensions\v2\`:

```php
namespace MeterDevice\Counter\Counter\Extensions\v2\Command\Handler\Step;
```

### 3. Override targets

All paths are **under the aggregate's `Extensions/`** — mirroring the generator path. Choose `Extensions/...` for a baseline override or `Extensions/v{N}/...` for a versioned one.

| Concern | Override path (baseline) |
|---|---|
| Command Step (Hydrate/Validate/Build) | `<BC>/<Agg>/Extensions/Command/Handler/Step/` |
| Pre-Persist Validator (`Validate<Action>`) | `<BC>/<Agg>/Extensions/Command/Validation/` |
| Aggregate Step (Set/Add/Remove) | `<BC>/<Agg>/Extensions/Aggregate/Step/` |
| QueryAdditions / Transform / Validate / Persist | `<BC>/<Agg>/Extensions/Repository/` |
| Per-aggregate Registries | `<BC>/<Agg>/Extensions/Api/` |
| Event Router (`on<Event>` bodies) | `<BC>/<Agg>/Extensions/Event/` (override `<Agg>EventRouter` and fill bodies) |

**Domain-level overrides** — Entity Validators (`<Domain>/Entity/Validation/`) and FieldMap (`<Domain>/Api/FieldMap.php`) live outside an aggregate and therefore outside `Extensions/`. These are regenerated on build. For domain-level fachlogik either: (a) put the logic into a Domain Service under `<BC>/<Agg>/Extensions/Service/` and call it from the generated validator via `$this->handle(...)`, or (b) override the respective aggregate-local entry point (Validate/Hydrate step) in `Extensions/` and tighten from there. Domain-level `v2/` subdirs no longer exist — the unified one-place rule applies.

**API customisation** (REST paths, AsyncAPI channels, Proto services) continues to flow through `*.custom.yaml` / `*.custom.proto` files. These live either inside `<BC>/<Agg>/Extensions/Api/` or at domain level (`<Domain>/Api/*.custom.yaml`). The fragment assembler merges them at build time and never touches them.

### 4. Prohibitions (V1–V12)

| # | Rule |
|---|---|
| V1 | Never edit generated files |
| V2 | No `new` for Services/VOs/Entities — only `Exception`, `RuntimeException`, `DateTimeImmutable`, `DateTime` |
| V3 | Never bypass `handle()` |
| V4 | No logic in Command/Query Handler Orchestrators — logic belongs in Steps |
| V5 | Never access Aggregate internals from outside — only via AggregateHandler |
| V6 | No cross-BC imports — communicate via Domain Service |
| V7 | No traits, no `abstract` (except Exception hierarchies) |
| V8 | Never skip validation in Command → Entity → Aggregate chain |
| V9 | No persistence in Domain Services — only via `Repository::persist()` |
| V10 | No state in BoundedContext subclasses (transient per call) |
| V11 | No business methods on Entities |
| V12 | Responses are arrays via FieldMapper, not typed DTOs |

### 5. Decision tree — "I need to…"

| Need | Action | Pattern |
|---|---|---|
| Validate a domain concept (OBIS, IBAN) | VO in `{BC}/{Agg}/Extensions/ValueObject/`, instantiate via `handle()` in Step | Value Object |
| Load data from external API | Domain Service in `{BC}/{Agg}/Extensions/Service/`, call in Step override | Adapter |
| Read from another BC | Domain Service — never import directly | Adapter |
| Check Aggregate consistency | Baseline override of `Validate{Root}` in `Extensions/Repository/`, `parent::__invoke()` first | Decorator |
| Convert DTO field before persist | Baseline override of `Hydrate*` or `Build*` Step in `Extensions/Command/Handler/Step/`, `parent::__invoke()` first | Decorator |
| Add calculated field in query response | Baseline override of `Transform*` in `Extensions/Repository/` | Decorator |
| Add JOIN to a query | Baseline override of `QueryAdditions*` (protected method) in `Extensions/Repository/` | Decorator |
| New domain operation | New Command DTO + Handler + Registry override, all under `Extensions/Command/` + `Extensions/Api/` | Facade + Factory |
| New query variant | New Query DTO + Handler + Registry override, all under `Extensions/Query/` + `Extensions/Api/` | Facade + Factory |
| Tighten field validation (single field) | Inject via Domain Service called from `Extensions/Command/Handler/Step/` override | Decorator |
| Extend FieldMap | Inject via aggregate-level Hydrate/Transform override in `Extensions/`; FieldMap stays generator-owned | Decorator |
| Emit new event | Emit in baseline override of Persist step in `Extensions/Repository/` | Decorator |
| Tenant-specific variant of any override | Put file under `Extensions/v{N}/...` instead of `Extensions/...` and activate via `version()` on the domain facade | ClassVersion |

### 6. New-code locations — everything lives in Extensions/

```
<Domain>/<BC>/<Agg>/Extensions/          ← single developer-owned root per aggregate
├── ValueObject/                         ← VOs (OBIS, MeterNumber)
├── Service/                             ← external data, cross-BC, calculations
├── Command/                             ← NEW Command DTOs + Handlers for custom commands
│   ├── <NewCommand>.php
│   └── Handler/<NewCommand>.php
├── Command/Handler/Step/                ← baseline override of generated Hydrate/Build steps
├── Command/Validation/                  ← baseline override of Validate<Action>
├── Query/                               ← NEW Query DTOs + List filters
│   └── Handler/Get<New>Handler.php
├── Aggregate/Step/                      ← baseline override of Set/Add/Remove steps
├── Repository/                          ← baseline override of QueryAdditions/Transform/Validate/Persist
├── Api/                                 ← per-aggregate API customisation
│   ├── <Agg>Commands.php                 (baseline registry override — add custom command methods)
│   ├── openapi.custom.yaml              (REST additions merged by fragment assembler)
│   ├── asyncapi.custom.yaml
│   └── service.custom.proto
├── Event/                               ← baseline override of <Agg>EventRouter (fill on<Event>() bodies)
└── v1/ · v2/ · …                       ← optional versioned variants mirroring everything above
```

Never touch generated files. All developer code — new classes **and** overrides — lives under `<Agg>/Extensions/`. One directory, one rule, no exceptions (aside from domain-level `*.custom.yaml` slots that continue to work alongside the fragment assembler).

### 7. Seven implementation levels

No skipping, no parallel levels. PHPStan L8 + tests + review green between levels.

| # | Level | Where | Anchor |
|---|---|---|---|
| 1 | VOs + business validation | `{BC}/{Agg}/Extensions/ValueObject/` | `jardissupport/validation` |
| 2 | Custom Commands | `{BC}/{Agg}/Extensions/Command/` (DTO + Handler + Registry baseline) | `jardiscore/foundation` |
| 3 | Custom Queries | `{BC}/{Agg}/Extensions/Query/` (DTO + Handler + Registry baseline) | `jardissupport/dbquery` |
| 4 | Domain Services | `{BC}/{Agg}/Extensions/Service/` | `jardiscore/foundation`, `jardisadapter/dbconnection` |
| 5 | Workflows | `{BC}/{Agg}/Extensions/Workflow/` | `jardissupport/workflow` |
| 6 | Event transport | `{BC}/{Agg}/Extensions/Event/<Agg>EventRouter.php` (baseline override) | `jardisadapter/messaging` |
| 7 | Non-functional (cache, logging) | Query Handler override in `Extensions/`, DomainKernel | `jardisadapter/cache`, `jardisadapter/logger` |

### 8. Event transport — `<Agg>EventRouter.php`

Generator emits a base `<Agg>EventRouter.php` with an empty `on<Event>()` closure per event. The channel key from `asyncapi.yaml` sits in the preceding line comment = topic/routing key. To wire transport, create a baseline override at `<BC>/<Agg>/Extensions/Event/<Agg>EventRouter.php` that extends the generated class and fills the `on<Event>()` bodies. The `handle()` resolver picks the Extensions/ version automatically when the domain facade invokes the router.

**Kafka / RabbitMQ / Redis** via `jardisadapter/messaging`:

```php
protected function onCounterCreated(EventListenerRegistryInterface $registry): void
{
    $registry->listen(CounterCreated::class, function (CounterCreated $event): void {
        $this->handle(MessagingService::class)
            ->publish('meterdevice.counter.counter.created', $event);
    });
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

- Never `new` publishers/clients inside the listener — always `handle()` (V2/V3).
- Listener runs **synchronously** with dispatch. Long operations → queue consumer, not here. Listener only hands off.
- Listener exceptions bubble to the handler's outer `try/catch` and flip the response to 500. Wrap `try/catch` inside the listener only if "event delivery must not roll back the command".
- Priority ordering: lower = later.

Foundation platform wires the Router via `eventDispatcher()` on the generated Domain Facade. Kernel platform: wire manually in `DomainApp` subclass.

### 9. Constructing `DomainResponse`

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

### 10. Phase-3 cookbook

Paths assume `MeterDevice\Counter\Counter`. All files land under `<Agg>/Extensions/` — one directory, one rule.

**Recipe 1 — VO used in Hydrate step (baseline override, null setup)**

VO `src/MeterDevice/Counter/Counter/Extensions/ValueObject/ObisCode.php`:

```php
namespace MeterDevice\Counter\Counter\Extensions\ValueObject;

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

Baseline override `Extensions/Command/Handler/Step/HydrateCreateCounterEntities.php`:

```php
namespace MeterDevice\Counter\Counter\Extensions\Command\Handler\Step;

use MeterDevice\Counter\Counter\Command\Handler\Step\HydrateCreateCounterEntities as Base;
use MeterDevice\Counter\Counter\Extensions\ValueObject\ObisCode;

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

Create file, run test: bad OBIS → `ValidationError` + error message. No `ClassVersionConfig` needed — `handle()` finds the baseline override automatically.

For a tenant-specific variant, put the same file under `Extensions/v2/Command/Handler/Step/…` and switch it on by overriding `version()` on the domain facade.

**Recipe 2 — Domain Service for external lookup**

`Extensions/Service/ResolveMeterLocationName.php`:

```php
namespace MeterDevice\Counter\Counter\Extensions\Service;

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

Three files under `Extensions/`:

1. `Extensions/Command/DeactivateCounter.php` — `readonly class` with `identifier`, `deactivatedAt`.
2. `Extensions/Command/Handler/DeactivateCounter.php` — `extends <Domain>Context`, loads via `handle(CounterRepository::class)->…`, mutates via aggregate step, persists, emits event. Response per §9.
3. `Extensions/Api/CounterCommands.php` extends the generated `CounterCommands`, adds `deactivateCounter(DeactivateCounter $dto): DomainResponseInterface`. Baseline registry override — `handle()` picks it up.

Add the OpenAPI path in `Extensions/Api/openapi.custom.yaml` — fragment assembler merges at build time.

**Recipe 4 — Event to Kafka**

Create `Extensions/Event/CounterEventRouter.php` that extends the generated router:

```php
namespace MeterDevice\Counter\Counter\Extensions\Event;

use MeterDevice\Counter\Counter\Event\CounterEventRouter as Base;
use MeterDevice\Counter\Counter\Event\CounterCreated;
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

Baseline override — `handle()` picks it up when the domain facade wires the router. Test via `EventCollector` fake (see `rules-testing` §6).

### 11. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `LogicException: Cannot resolve ClassName` | BC vs. Aggregate segment swapped in namespace | Namespace is `<Domain>\<BC>\<Agg>\…` — BC and Aggregate are two segments even when they share a name |
| Baseline override in `Extensions/…` not picked up | Wrong namespace — `Extensions` segment must sit directly after the aggregate namespace, mirroring the generator path | `namespace <Domain>\<BC>\<Agg>\Extensions\<GeneratorSubpath>` — no detours |
| Versioned override (`Extensions/v2/…`) ignored | Domain facade doesn't return `'v2'` from `version()` | Override `protected function version(): string` on the `{Domain}.php` facade to the label you want active — §2 |
| `Error: Cannot instantiate abstract class` / "Service X not in container" | Direct `new` bypassing `handle()` (V2/V3) | Replace with `$this->handle(X::class, ...)` |
| `on<Event>()` edit gone after rebuild | Bodies were filled directly in the generated `<Agg>EventRouter.php` | Move the body into `Extensions/Event/<Agg>EventRouter.php` extending the generated class — generator will never touch Extensions |
| Builder error "write under Extensions/ is forbidden" | Generator handler emits a file under `Extensions/` (programming error, not a user-facing issue) | File a bug — `FileWriter.WriteGuarded` is the guard; only `.gitkeep` on an empty Extensions/ is allowed |
| `Cannot import OtherBC\...` review blocker | V6 violation | Add Domain Service, call other BC via `handle()` |
| OpenAPI / AsyncAPI path duplicated | Two aggregates emit same schema name | Assembler scopes on collision — wire `BuildOptions.CollidingEntityNames` via `definition.CollidingEntityNames(domainDir)` |
| Custom-slot YAML overwritten on rebuild | Named `*.fragment.yaml` (Builder-owned) | Rename to `*.custom.yaml` — Builder never touches `*.custom.*` (inside `Extensions/Api/` or at domain level) |
| `getData()` empty after `addData()` in override | Override skipped `parent::__invoke(...)` — parent's `setData` ran against a different `$this->result()` | Always `parent::__invoke(...)` first, then augment |
| Listener throws → command rolls back | Default: listener exception flips response to 500 | Wrap listener body in `try/catch` only if delivery failure must not roll back — §8 |

### 12. Anchors

- `rules-architecture`, `rules-patterns`, `rules-testing`
- Definition YAMLs: `tools-definition`
- `DomainResponse`, `ResponseStatus`, `DomainResponseTransformer`: `core-kernel`
- `MessagingService`, `MessagePublisher`: `adapter-messaging`
- `EventListenerRegistryInterface`, `EventDispatcher`, `EventCollector`: `adapter-eventdispatcher`
- `HttpClientInterface`, `HttpClient`: `adapter-http`
