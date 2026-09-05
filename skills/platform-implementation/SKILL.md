---
name: platform-implementation
description: Extending Designer-generated code — the whole `{BC}/Aggregate/{Agg}/` tree is Generator-owned and hermetic (ForceOverwrite, never edit). The BC facade's Außentür exposes `{agg}(): {Agg}Read` (read-only queries + lists), `process()`, and an optional direct method per exposed rule-guarded Command; the write facade `{Agg}` (Commands + `event()`) is family-internal via the protected Kernel-Naht (`$this->handle()`). Developer surface: BC-level Processes under `{BC}/Process/{Name}/` (Generator-owned DTO/orchestrator/node stubs + `Event/`; Dev-owned `Query/`/`Repository/`/`Service/`) and the `{BC}/Rule/` catalog (own-BC-read-only Rule bodies, M9). Covers V1–V13 prohibitions, decision tree, generated-file modes, DomainResponse construction incl. `RuleViolation` 422. ClassVersion → `platform-versioning`. Workflow API → `platform-workflow`. Recipes → `platform-cookbook`.
zone: post-active
persona: C
prerequisites: []
next: [platform-versioning, platform-workflow, platform-cookbook, platform-usage]
---

### 0. DDD positioning

Jardis uses DDD vocabulary (BC, Aggregate, Domain Event, Repository, VO, Ubiquitous Language) under its own axioms — Closure-Orchestrator (`rules-architecture` §3) and model-driven generation. See `rules-architecture` § Positioning for the Functional-DDD stance this skill operates under.

| Rule | Consequence |
|---|---|
| **V11** — no business methods on entities | Behaviour lives in Actions and Domain Services, not on entities. Aggregate invariants are protected by the generated **pipeline**; cross-aggregate behaviour is authored as a **Process** (`{BC}/Process/`). |
| **V12** — responses are `DomainResponse` arrays | One serialisation surface across HTTP / CLI / queue. Typing is the transport adapter's job, not the domain's. The generated `{Agg}/Command/Response/{Command}Response.php` classes do **not** change this: they are contract types describing the payload, never a handler return type. |
| **Call chains** | Aggregate reads (Außentür): `$bc->{agg}()->get{Agg}ById\|ByIds\|By{Key}\|By{PluralKey}\|{agg}List(...)` (the `By{PluralKey}` bulk variant only when the aggregate has a unique key) — the `{Agg}Read` facade is the call surface, no aggregate-root hop. Aggregate writes are **not** on the BC facade (G2) — the write facade `{Agg}` is reachable only family-internally, via the Kernel-Naht: `$this->handle({Agg}::class)->{command}($dto)` (typically from a Process node body). BC-level processes (the other half of the Außentür): `$bc->process()->{process}($dto)`. Taxonomy is discoverable; `handle()` / ClassVersion resolution anchors at each level. |

Upheld without compromise: aggregates are transaction boundaries with a single root; BCs are strict language boundaries (V6). The aggregate is **100 % generated** — you do not extend it in place. A Rich-Model instinct (custom validation, a VO, a domain service, coordinating two aggregates) is satisfied by **modelling a Process** in the Process Designer, never by editing the aggregate tree.

When Domain logic needs a Jardis runtime tool (cache, mail, queue, repository, HTTP client, …), look up the responsible package skill in §8 (Toolbox) before re-deriving the API.

### 1. Generated baseline — Platform-free hermetic layout

Facade chain: Domain → BC → **Aggregate Read facade** (`{Agg}Read`, reached via `$bc->{agg}()`) → query method — this is the Außentür (the BC facade's public surface), plus a parallel Domain → BC → Process facade → process chain (`$bc->process()`). The **Aggregate Write facade** (`{Agg}`, same directory) hosts the commands + `event()`; it sits behind the Kernel-Naht (`handle()`/`context()`) and is reachable only from inside the Context-Familie (classes extending the Domain Context) — never from the BC facade. Example: aggregate `Counter` in BC `Counter` in domain `MeterDevice`.

**Route convention of the Außentür.** Every build also writes a machine-readable description of this same surface — one OpenAPI 3.1 document per domain at `Api/{Domain}/openapi.yaml`, a sibling of `App/` outside the PSR-4 tree. The routes it declares are the canonical HTTP shape of the Außentür, and a transport layer (hand-written or generated) is expected to mount exactly these:

| Command / entry | Route |
|---|---|
| `Update{Agg}` | `PUT /{bc}/{aggs}/{identifier}` *(full replacement of the root scalars; `PATCH` stays free for a future partial-update concept)* |
| `Remove{Agg}` | `DELETE /{bc}/{aggs}/{identifier}` |
| `Add{Agg}{Child}` | `POST /{bc}/{aggs}/{identifier}/{children}` |
| `Remove{Agg}{Child}` | `DELETE /{bc}/{aggs}/{identifier}/{children}/{childKey}` |
| `Set{Something}` | `PUT /{bc}/{aggs}/{identifier}/{something}` |
| Process | `POST /{bc}/processes/{name}` |

**There is deliberately no create route.** The create command DTO is named after the entity (it *is* the aggregate's data shape — the verb lives on the handler `Create{Agg}`), so it can never be exposed as a direct facade method (its method name would collide with the read accessor `{agg}()`, caught by `V-RULE-6`). **Creation at the Außentür runs exclusively through a Process** — model one, expose it, and it appears as `POST /{bc}/processes/{name}`.

**`Remove{Agg}{Child}` is not emitted for every child.** The table above gives the route *shape*; whether the command exists at all depends on the child. Two independent rules suppress the remove mutation of an `erm:one` child — and when either applies, there is **no** `Aggregate/Action/Remove{Child}.php`, **no** AggregateHandler method, **no** command and therefore **no** route:

| Case | Why |
|---|---|
| **DEPEND leaf whose depend-FK is NOT NULL** | removing it would null a mandatory column — the database would reject it |
| **`required` containment child** | there is no valid removed state |

A depend leaf whose FKs are all nullable keeps its remove mutation. `Remove{Agg}` (the root) is always emitted and is unaffected by this rule. So: if you expect a `remove{Child}()` on the write facade and it is missing, check the depend-FK's nullability first — that absence is the rule working, not a defect. Model the removal as a Process if the business really needs it (which then also has to say what replaces the mandatory reference).

Path parameters carry the **business identifier** wherever the aggregate or child has one; the internal id is a documented fallback only, and the build emits a warning when it has to fall back, so the gap stays visible. A process carries `visibility: public | internal` in its YAML — `internal` keeps it out of the document without touching a line of PHP. The document carries no `servers:` block: base URL and mount prefix are the surrounding app's business.

**`openapi.yaml` has a geschwisterliches Erzeugnis:** `Api/{Domain}/routes.php`, a hermetic `jardiscore/app` route-registration closure built from the same route table — how a transport layer wires it, decoding/dispatch/envelope details, and its `Api/{Domain}/`-Nachbarschaft: `platform-usage` §4 „Generated route registration".

**Notation:** aggregate code lives under `{BC}/Aggregate/{Agg}/`; this skill abbreviates that directory as `{Agg}/`. Processes live one level up, under `{BC}/Process/` — a sibling of `{BC}/Aggregate/`, **not** under any aggregate.

**The one rule:** The **entire** `{Agg}/` tree (`{BC}/Aggregate/{Agg}/…`) is regenerated on every build (ForceOverwrite per file) — never edit anything inside it. There is **no `Platform/` segment**, in neither path nor namespace; the directory `{Agg}/` *is* the hermetic generator output. The directory hosts **two** facades: the write facade `{Agg}/{Agg}.php` (Commands + `event()`) and the read facade `{Agg}/{Agg}Read.php` (`get{Agg}ById`/`ByIds`/`By{Key}` + lists). Only the read facade is reached via the BC accessor `$bc->{agg}()` (the Außentür, G2); the write facade is reached exclusively family-internally via the Kernel-Naht, `$this->handle({Agg}::class)` — the same `protected` `handle()`/`context()` corridor every generated class in the Context-Familie shares (code outside the Context-Familie cannot call either method; PHP visibility enforces it at the type-system level, not just by convention). The **only** developer surface is the BC-level **Process** scope under `{BC}/Process/{Name}/`. The Generator emits the command-path segment: `Command/{Name}.php` DTO (`ForceOverwrite`), `Command/Handler/{Name}Handler.php` orchestrator (`ForceOverwrite`), `Command/Handler/Action/<NodeClass>.php` node stubs (`CreateIfNotExists` + `@node-id` body-preserve for custom and sub-process nodes), and event-data classes under `Event/` (`ForceOverwrite`, hermetic). The segments `Query/`, `Repository/`, and `Service/` are **KI-/Dev-owned**: the Generator never creates them — they are authored contextually when a process needs them. Custom-node stubs are `CreateIfNotExists` with `@node-id` body-preserve, where your process logic lives. Versioned variants of any generated class live in a per-class `v{N}/` subdir resolved by ClassVersion (see `platform-versioning`).

```
src/                                              ← namespace root = <Domain>
├── <Domain>.php                                  ← Domain facade — Generated, ForceOverwrite
│                                                    (carries the classVersion() override → LoadClassFromSubDirectory)
├── <Domain>Context.php                           ← generated hermetic Context base (implements GeneratedContextInterface, no package base class) — Generated, ForceOverwrite
├── Config/
│   ├── .env
│   ├── .env.database, .env.database.{dev,test}
│   └── .env.logger, .env.logger.{dev,test}
└── <BC>/
    ├── <BC>.php                                  ← BC facade — Generated, ForceOverwrite
    │                                                (exposes ->{agg}() and ->process(), plus one
    │                                                 direct method per exposed rule-guarded Command
    │                                                 — Rules-Layer G10)
    ├── Rule/                                     ← ★ DEVELOPER SURFACE — BC-level Rule catalog (sibling of Process/)
    │   ├── <RuleName>.php                        ← Rule stub, DeveloperOwned (tag RuleClass)
    │   │                                            __invoke(<Cmd>DTO): RuleResult — your bestand-check
    │   │                                            (own BC only via the Kernel-Naht, M9) ← your logic
    │   ├── Data/RuleResult.php                   ← hermetic readonly VO — Generated, ForceOverwrite
    │   ├── Guard/Guard<Command>.php               ← hermetic AND-chain closure per bound endpoint
    │   │                                            — Generated, ForceOverwrite
    │   └── v{N}/<RuleName>.php                   ← versioned Rule override, ClassVersion-resolved
    ├── Process/                                  ← ★ DEVELOPER SURFACE — BC-level Processes (siblings of Aggregate/)
    │   ├── <BC>Process.php                       ← Process facade — Generated, ForceOverwrite, HERMETIC
    │   │                                            (thin dispatch, one method per process, no body merge)
    │   └── <Name>/                               ← one process
    │       ├── Command/                          ← Generator-owned (ForceOverwrite, hermetic)
    │       │   ├── <Name>.php                    ← Input DTO
    │       │   └── Handler/
    │       │       ├── <Name>Handler.php         ← Workflow orchestrator (full-graph config())
    │       │       │                                (regenerated, not edited)
    │       │       └── Action/<NodeClass>.php    ← Node stubs, one file per node
    │       │                                        (custom: CreateIfNotExists + @node-id body-preserve;
    │       │                                         sub-process: CreateIfNotExists + @node-id body-preserve,
    │       │                                         typed Dev-Stub — see platform-cookbook §2 Recipe 8) ← your logic
    │       ├── Event/<EventClass>.php            ← Event-data classes (Generator, ForceOverwrite)
    │       ├── Query/ + Handler/                 ← KI-/Dev-owned — NOT generated; author when needed
    │       ├── Repository/                       ← KI-/Dev-owned — NOT generated; author when needed
    │       └── Service/                          ← KI-/Dev-owned — NOT generated (Process-Plus vs. Aggregate)
    ├── FieldMap.php                              ← ★ BC-LEVEL FieldMap — Generated, ForceOverwrite
    │                                                (ONE per BC, namespace <Domain>\<BC>; one {table}Columns() method per BC table, all BC tables)
    ├── Entity/                                   ← ★ BC-LEVEL schema layer — Generated, ForceOverwrite
    │   ├── <Entity>.php                          ←   persistence entity (#[Table]/#[Column]), one per BC table
    │   └── Validation/<Entity>Validator.php      ←   field-level validator, one per BC table
    └── Aggregate/<Agg>/                          ← ★ GENERATED, NIE EDITIEREN (whole tree ForceOverwrite)
        ├── <Agg>.php                             ← WRITE facade (extends <Domain>Context), hosts inline
        │                                           Command methods + event(); family-internal only
        │                                           ($this->handle(<Agg>::class) — no BC-facade accessor)
        ├── <Agg>Read.php                         ← READ facade (extends <Domain>Context), hosts inline
        │                                           Query/list methods; reached via $bc->{agg}() (the Außentür)
        ├── Aggregate/{<Agg>.php, Entity/<Entity>.php, Action/{Set,Add,Remove}<Entity>.php}   ← DOMAIN aggregate entity (#[Aggregate]/#[Relation]) — distinct from the BC-root persistence Entity above
        ├── Command/{<DTO>.php}  +  Handler/{Create<Agg>,{Set,Add,Remove}<Entity>}.php  +  Handler/Action/{Build*Data,HydrateCreate<Agg>Entities}.php  +  Validation/{<DTO>Validator,Validate<Action>}.php
        ├── Query/{<Agg>ById,<Agg>ByIds,<Agg>By<Key>,<Agg>By<PluralKey>,<Agg>By<Custom>…,<Agg>ListFilter}.php  +  Handler/Get<Agg>{ById,ByIds,By<Key>,By<PluralKey>,By<Custom>…,List}Handler.php  (`By<PluralKey>` only with a unique key)
        ├── Event/{<Agg>{Created,Removed,Updated,…}.php, <Agg>EventRouter.php, <Agg>Events.php}
        ├── Repository/{<Agg>Repository,Query<Agg>{ById,ByIds,By<Key>,By<PluralKey>,…},Transform<Agg>,Validate<Agg>,Persist<Agg>,Query<Agg>Additions,Query<Agg>List}.php
        └── …/v{N}/<Class>.php                    ← versioned override: a v{N}/ subdir *next to a class*
                                                    (e.g. Command/Handler/v2/CreateCounter.php), ClassVersion-resolved,
                                                    NOT Generator-emitted — the only non-generated artefact in the tree
```

> **Note:** there is no `{Agg}/Api/` directory and no `Platform/` directory. The aggregate use-case API surface is split across two sibling facades in the same directory: the **write** facade `{Agg}/{Agg}.php` (FQN `<Domain>\<BC>\Aggregate\<Agg>\<Agg>`, Commands + `event()`, family-internal via the Kernel-Naht) and the **read** facade `{Agg}/{Agg}Read.php` (FQN `…\<Agg>\<Agg>Read`, Queries/lists, reached directly via `$bc->{agg}()` — the Außentür). When BC and aggregate share a name, the BC facade imports the read facade under an alias `{Agg}AggregateRead` (e.g. `CounterAggregateRead`) to avoid a class-name collision with the BC's own class name; the accessor itself stays `{agg}()` either way (same pattern the write facade already used: `{Agg}Aggregate`). The Events registry lives under `{Agg}/Event/{Agg}Events.php`. **Processes** are BC-level (`{BC}/Process/{Name}/`, sibling of `{BC}/Aggregate/`), reached via `$bc->process()->{process}()` through the hermetic `{BC}Process` facade. The process follows the same segment convention as the aggregate: Generator emits `Command/` (DTO + `Handler/` + `Handler/Action/`) and `Event/`; `Query/`, `Repository/`, and `Service/` are KI-/Dev-owned (no aggregate ownership, not generated).

**Vokabular-Glossar** (verbindlich für dieses Skill-Set — die kanonische Begriffstabelle, Geschwister-Skills verweisen hierher):

| Begriff | Bedeutung |
|---|---|
| **Aggregat-Fassade** (Write) | `{Agg}/{Agg}.php` — Commands + `event()`; das Aggregat ist das Schreibmodell (DDD). Familienintern über die Kernel-Naht erreichbar, nicht über die BC-Fassade. |
| **Lese-Fassade** `{Agg}Read` | `{Agg}/{Agg}Read.php` — `get{Agg}ById`/`ByIds` immer; `By{Key}`/`By{PluralKey}` nur mit öffentlichem Unique Key; `{agg}List` nur, wenn `Queries.yaml` sie deklariert. Inkl. `$version`, über `$bc->{agg}()` erreichbar. |
| **Außentür** | Die öffentlichen Methoden der BC-Fassade: `{agg}(): {Agg}Read` je Aggregat + `process(): {BC}Process` + (Rules-Layer G10) optional ein direkter Methoden-Eintrag je **exponiertem rule-guarded Command**. Kein allgemeiner Write-Accessor. |
| **Kernel-Naht** | `handle()` / `context()` auf `<Domain>Context` (dem generierten, hermetischen Basisklassen-Körper — keine Paket-Basisklasse) — der Familien-Korridor, über den alle Generat-Klassen sich gegenseitig erreichen (inkl. der Aggregat-Write-Fassade). `protected` — Code außerhalb der Context-Familie kann `handle()`/`context()` nicht aufrufen, PHP-Sichtbarkeit erzwingt es typsystemisch. |
| **Context-Familie** / familienintern | Klassen, die den `<Domain>Context` erben (Aggregat-Fassaden, Process-Handler, Node-Actions, Services, Rules, …) — sie teilen sich die Kernel-Naht untereinander. Code außerhalb der Familie (Transport-Layer, Controller) hat keinen `handle()`-Zugriff und muss über die Außentür gehen. |
| **Rule** | `{BC}/Rule/{Name}.php` — ein endpunkt-gebundener, synchroner Ja/Nein-Wächter (`__invoke({Cmd}DTO): RuleResult`), DeveloperOwned, BC-scoped, wiederverwendbar über Aggregate hinweg. Rufbar ausschließlich via `$this->handle({Rule}::class)` (ClassVersion-fähig, `Rule/v{N}/`) — nie `new`. Liest Bestand nur aus dem **eigenen** BC (M9-Verbot: kein Fremd-BC-Import) — über die eigene Lese-Fassade für fassadengeführte Queries, oder direkt über die Kernel-Naht (`$this->context({Handler}::class, $payload)()`) für einen BC-internen Lesezugriff — die deklarierte interne Liste mit `limit: 1`, entschieden über `total` (s. Rule-Stub-Zeile unten). |
| **Guard** | `{BC}/Rule/Guard/Guard{Command}.php` — generierte, hermetische Chain-of-Responsibility-Closure je gebundenem Endpunkt: führt die konfigurierte Rule-Kette als UND mit Kurzschluss aus. Der generierte CommandHandler ruft nur `$this->handle(Guard{Command}::class)($cmd)` — keine Guard-Eigenlogik. |
| **RuleViolation (422)** | Ablehnungs-Status einer Rule (benötigt `jardiscore/kernel` ≥ v1.1.0) — Payload `{rule, messageKey, context}`, stabil über Rule-Versionen. Kein Exception-Pfad; der Handler baut die 422-Response direkt. |

Die Naht ist `protected` — laufzeit- **und** typsystemhart: Code außerhalb der Context-Familie kann `handle()`/`context()` nicht aufrufen (PHP-Sichtbarkeitsfehler bei einem Fremdaufruf).

| Class | Path | Mode | Purpose |
|---|---|---|---|
| Domain Facade | `<Domain>.php` | Generator: ForceOverwrite | `final class`, no `extends` (JardisCore-free). Constructor takes the DomainKernel `DomainKernelInterface $kernel`; self-registers every aggregate's event router via `$kernel->eventListenerRegistry()` (nullable-safe, unconditional); each BC accessor `new`s the BC facade directly (`new <BC>($this->kernel)`) — the Domain facade itself sits outside the Context-Familie. The `classVersion()`/`classVersionConfig()` hooks live on the generated `<Domain>Context` (next row) — see `platform-versioning` |
| FieldMap (BC-level) | `{BC}/FieldMap.php` | Generator: ForceOverwrite | **ONE FieldMap per BC** (namespace `<Domain>\<BC>`), built from the whole BC schema — one `{table}Columns()` write-map method per BC table (each BC table appears exactly once; shared tables collapse losslessly across the BC's aggregates). **NOT aggregate-kapsel:** BC-rooted and BC-complete, not emitted per aggregate. The read path (root-id normalisation) lives at the aggregate read edge (query handler), not in the FieldMap. |
| Config | `Config/.env*` | Generator: CreateIfNotExists | Per-domain ENV defaults — kept on rerun |
| Persistence Entity (BC-level) | `{BC}/Entity/<Entity>.php` | Generator: ForceOverwrite | Schema-driven persistence entity (`#[Table]`, `#[Column]`, `__snapshot`), namespace `<Domain>\<BC>\Entity`, one per BC table. **Distinct** from the DOMAIN aggregate entity (`#[Aggregate]`/`#[Relation]`) under `{Agg}/Aggregate/Entity/<Entity>.php` — the schema-driven layer (Entity/Validation/Data) lives on the BC root, the domain aggregate entity stays in the aggregate tree. |
| Entity Validator (BC-level) | `{BC}/Entity/Validation/<Entity>Validator.php` | Generator: ForceOverwrite | Field-level validation for the BC-root persistence entity, one per BC table |
| Domain Context | `<Domain>Context.php` | Generator: ForceOverwrite | Generated, hermetic base class every BC facade and both Aggregate write/read facades in the domain extend — `implements GeneratedContextInterface` (the `jardissupport/contracts` marker), **no package base class**. Hosts the Kernel-Naht `handle()`/`context()` (`protected`), `resource()/payload()/version()/result()`, and the `classVersion()`/`classVersionConfig()` hooks. Sits directly under the Domain root — no `Foundation/` subdir. **Measuring stick:** this generated Context body is judged by the **Generat-Regeln in this skill** (hermetic, ForceOverwrite, never hand-edited) — not by the Closure-Orchestrator constitution (`rules-architecture` §3) that governs hand-written Jardis code |
| BC Facade | `<BC>/<BC>.php` | Generator: ForceOverwrite | `extends <Domain>Context`; accessors only, **no caching**. This is the Außentür (G2): exposes `->{agg}()` (returns the **read** facade `{Agg}Read` via `handle(<Agg>Read::class)` — aliased `{Agg}AggregateRead` when BC and aggregate share a name) and `->process()` (returns the `<BC>Process` facade). No general write accessor — the write facade is not reachable from here. **Rules-Layer addendum (G10):** for every Command exposed in `Rules.yaml` (`bindings.{command}.expose: true`), the facade additionally carries a direct method `{lcfirst(Command)}({Cmd}DTO): DomainResponseInterface`, delegating via `$this->context({HandleClass}::class, $cmd)()` into the same generated CommandHandler — the Rule chain (Guard) runs structurally, in the dispatch, not at this door. A Create-Command can never be exposed this way (name collision with `{agg}()`, caught at build time). |
| Rule stub | `{BC}/Rule/<RuleName>.php` | DeveloperOwned (tag `RuleClass`) | `extends <Domain>Context`. `__invoke(<Cmd>DTO): RuleResult` — your bestand-check logic, reading only your own BC's state (M9) — via `$this->handle({BC}::class)->{agg}()` for a facade-exposed query, or directly via `$this->context({Handler}::class, $payload)()` for a BC-internal read — the declared internal list with `limit: 1`, decided over `total` (e.g. `$this->context(Get{Agg}ListHandler::class, new {Agg}ListFilter(limit: 1, …))()`, see example below). Merged on every rebuild by the RuleClass Header-Splice — mechanics (which header line follows the generator, where the splice starts): [[beschreibt-bauweise-von::builder-generat-bauweise]] §7.2. In practice: a rewritten docblock, a renamed Command DTO or a renamed Rule reach an already-materialised stub; your `__invoke` BODY, your own properties/constants and any helper method you added stay 100% verbatim, and your own imports are merged in additively. A docblock you write directly above `__invoke` survives too. **A freshly generated, not-yet-implemented body throws** — `throw new \RuntimeException('Not implemented: write the rule predicate for ' . self::class)` — instead of returning `RuleResult::pass()` (G03, `wissensbasis/stub-ausfallphilosophie.md`, go-target-lab). A Guard-Closure never wraps its Rule dispatch in try/catch, so this propagates uncaught and surfaces through the generated Command handler's `catch (\Throwable $e)` branch as a 500 InternalError — never the 422 `RuleViolation` a bound Rule is meant to produce — until you implement the predicate. ClassVersion-fähig (`Rule/v{N}/<RuleName>.php`) — the **payload structure + messageKey** are the stable API across versions, only the accepted set may tighten (M5). |
| RuleResult | `{BC}/Rule/Data/RuleResult.php` | Generator: ForceOverwrite | Hermetic readonly VO, named constructors `RuleResult::pass()` / `RuleResult::reject($rule, $messageKey, $context)`. No Kernel-/Contract-type — purely BC-scoped. |
| Guard closure | `{BC}/Rule/Guard/Guard<Command>.php` | Generator: ForceOverwrite | Chain-of-Responsibility: runs the bound Rule chain as AND with short-circuit; dispatched from inside the generated CommandHandler at a per-handler-family anchor (Create/Set/Add: after `Validate`, before Apply/persist; Remove: after payload+Load, before Apply, since Remove has no `Validate` call). A rejection never throws — the handler builds a `RuleViolation` (422) `DomainResponseInterface` directly, payload `{rule, messageKey, context}`. |
| Aggregate Write Facade | `<BC>/Aggregate/<Agg>/<Agg>.php` | Generator: ForceOverwrite | `extends <Domain>Context`. Hosts the inline Command methods + `event(): <Agg>Events`; each command delegates `return $this->context(<Handler>::class, $dto, $version)();`. **Family-internal only** — reached via the Kernel-Naht (`$this->handle(<Agg>::class)`), never via the BC facade (G2/G3). Fully regenerated — no merge, no `@flow-id`. |
| Aggregate Read Facade | `<BC>/Aggregate/<Agg>/<Agg>Read.php` | Generator: ForceOverwrite | `extends <Domain>Context`. The aggregate's call surface for the Außentür, reached directly via `$bc->{agg}()`. Hosts the inline Query/list methods: `get<Agg>ById`/`ByIds` always, `By<Key>`/`By<PluralKey>` only on an aggregate WITH a public unique key, `<agg>List` only where `Queries.yaml` declares it. Each delegates `return $this->context(<Handler>::class, $dto, $version)();`. Dünne Fassade, keinerlei eigene Logik (Gremium B5). Fully regenerated — no merge, no `@flow-id`. |
| Process Facade (BC-level) | `<BC>/Process/<BC>Process.php` | Generator: ForceOverwrite (HERMETIC) | `extends <Domain>Context`. Bundles every process of the BC behind one facade, reached via `$bc->process()`. One thin-dispatch method per process: `return $this->context(<Name>Handler::class, $in, $version)();`. Fully regenerated — **no body merge, no `@process-id`**; process logic lives in the orchestrator's nodes, not here. |
| Aggregate (Root + Entity + Action) | `{Agg}/Aggregate/{<Agg>.php, Entity/<Entity>.php, Action/{Set,Add,Remove}<Entity>.php}` | ForceOverwrite | Invariants, ONE/MANY accessors, mutation rules |
| Command tree | `{Agg}/Command/{<DTO>.php, Handler/<Action>.php, Handler/Action/{Build*Data,Hydrate*Entities}.php, Validation/{<DTO>Validator,Validate<Action>}.php}` | ForceOverwrite | DTO (`readonly`), Orchestrator (no `Handler` suffix), DTO→entity mapping, validators |
| Query tree | `{Agg}/Query/{<Agg>ById,<Agg>ByIds,<Agg>By<Key>,<Agg>By<PluralKey>,<Agg>By<Custom>…,<Agg>ListFilter}.php` + `Query/Handler/Get<Agg>…Handler.php` (**WITH `Handler` suffix**) | ForceOverwrite | Read-base DTOs (ById/ByIds/By{UniqueKey}/By{PluralKey}, the last only with a unique key) + hand-modelled Source-query DTOs + handlers — no suffix-less `get<Agg>` exists |
| Event tree | `{Agg}/Event/{<Agg>{Created,Removed,Updated,…}.php, <Agg>EventRouter.php, <Agg>Events.php}` | ForceOverwrite | Event DTOs (identifier first, `occurredAt` last); router has empty `on<Event>()` bodies; `<Agg>Events.php` is the events registry. Transport wiring is authored in a **Process node**, not by editing the router (see `platform-cookbook`). |
| Repository tree | `{Agg}/Repository/{<Agg>Repository,Query<Agg>{ById,ByIds,By<Key>,By<PluralKey>,…},Transform<Agg>,Validate<Agg>,Persist<Agg>,Query<Agg>{Additions,List}}.php` | ForceOverwrite | Read / write pipeline base; one root-fetch class per read variant (`WHERE pk =` / `pk IN` / `unique =` / `unique IN` [only with a unique key] / hand-modelled); custom queries (generic CRUD = pipeline) |
| Process DTO | `<BC>/Process/<Name>/Command/<Name>.php` | Generator: ForceOverwrite | Input DTO for a BC-level process. A field may be a **typed Command field** (typed batch input): declared in `input.fields` as `accepts: [{Agg}.{Command}, …]` (+ optional `list: bool`), it renders as a Composition over the **same-BC** aggregate Command DTOs — a Union `A\|B $field` (single) or `public array $field` + `/** @param array<A\|B> */` (list), Command FQCNs pulled in via `use`. It only **references** the hermetic Command DTOs (never `extends`); no per-entry class is generated, no dispatch loop is emitted (the `foreach … match(instanceof)` is your node code). Command names are the **DTO class names** (e.g. `Counter.Counter`), not handler names. Namespace: `…\Process\<Name>\Command\<Name>`. |
| Process Orchestrator | `<BC>/Process/<Name>/Command/Handler/<Name>Handler.php` | Generator: ForceOverwrite | `extends <Domain>Context`. **Full-graph** workflow orchestrator: `__invoke()` builds the `Workflow`, runs `$workflow($this->config(), $cmd)` over the **whole** painted graph, harvests Domain events from the result chain (`getChain()` → `addEvent(…, EventScope::Domain)`), and returns the transformed `DomainResponse`. A private `config(): WorkflowConfigInterface` registers every node via `addNode()` (see `platform-workflow` §2). **Regenerated, not edited** — the graph is the Process Designer truth. Namespace: `…\Process\<Name>\Command\Handler\<Name>Handler`. |
| Process Node | `<BC>/Process/<Name>/Command/Handler/Action/<NodeClass>.php` | Generator: CreateIfNotExists + `@node-id` body-preserve (custom nodes + sub-process nodes) | Custom node: action stub (`__invoke(WorkflowContextInterface): WorkflowResultInterface`) — Generator writes the stub + Mini-PRD-DocBlock + an `@node-id` marker once; the body is yours and survives rebuilds via the `@node-id` body-preserve merger. **Aggregat-Aufruf-Knoten get a generated default body instead of the throw stub** at first generation, when the node declares **exactly one** same-BC aggregate-Command `consumedCalls` entry with a green drift verdict — what counts as green and what falls back to the throw stub (cross-BC calls, multiple calls, non-Command/non-aggregate calls, a non-green verdict): [[beschreibt-bauweise-von::builder-generat-bauweise]] §6.3. The default body calls the aggregate's **write facade directly through the Kernel-Naht** (`$this->handle({Agg}::class)->{useCase}($cmd->{field})` — not via the BC facade, which exposes no write accessor, G2/A5), the argument taken from the single unambiguous input Command field — an `@todo`-only resolver stub covers none-or-ambiguous), routes `ON_SUCCESS`/`ON_FAIL` off `$response->isSuccess()` when the node has a painted `onFail` edge, and returns `'data' => $response->getData()`. It carries a **marker comment** ("Generierter Default-Body — gegen die Knoten-Requirements pruefen, erweitern oder ersetzen") — the KI-Implementierungs-Phase's cue that mechanics are done but domain correctness is unchecked; treat it exactly like a stub that already compiles, review it against the node's actual requirements before trusting it. Sub-process node: **typed Dev-Stub** (`CreateIfNotExists` + `@node-id` body-preserve) — the Generator writes a typed `logic()` body with the real Sub-DTO field names as named constructor args, a `$this->context({SubHandler}::class, $in)()` call, status mapping (`$res->isSuccess() ? ON_SUCCESS : ON_FAIL`), and flat Domain-event bubbling; werfende Resolver pro Sub-DTO-Feld als PHPStan-gültige Platzhalter — only the DTO-field values are your code (see `platform-cookbook` §2 Recipe 8). A node flagged **Event ◇** (`mode: async`) instead is pre-filled to emit a Domain event: the Designer's `ProcessEventFieldEditor` authors `eventFields: [{label, source}]` bindings — either a process-input Command field, or a **Kollektor-Quelle** pointing at another node's aggregate-call result (`kind: latest` = that node's last run, `kind: all` = every run as a list; the leading use case is binding the post-persist id of a keyless Create) — the Generator resolves those to the affected aggregate's model identity and renders a `final readonly` event-data class under `Event/<EventClass>.php` with one property per resolved identity chain link plus the two automatic standard fields `eventId` (UUID7) and `occurredAt` — the node's `logic()` body is **fully generated, no hand-fillable stub** (see `platform-workflow` §Event-Kasten). Namespace: `…\Process\<Name>\Command\Handler\Action\<NodeClass>`. |
| Cross-BC-Call / Externer-Call node | `<BC>/Process/<Name>/Command/Handler/Action/<NodeClass>.php` | Generator: `CreateIfNotExists` + `@node-id` body-preserve (**same mechanism as Custom/Sub-Process nodes** — no new write mode for the Action file itself) | A node declaring `crossBcCall: {ServiceName}` (the cross-BC target stays in the node's own `consumedCalls[0]` — no duplicate facade/method field) or `externalCall: {service, level: process\|bc\|domain}` gets a **working body at first generation instead of the throw stub** — unconditionally, no drift-verdict gating (unlike the Kollektor-Quellen default-body case, because the delegation target here is always the generated Service, never an aggregate directly): `return $this->handle({Service}::class)->__invoke($context);`. The body is still `@node-id`-preserved like any other node, so you may customize it, but there is normally nothing to fill in — the business logic lives in the Service (below), not here. Mutually exclusive with `subProcess` on the same node; `mode: async` excludes all three specializations. |
| Service (Closure) stub | `{Domain}/Service/<Name>.php` (Cross-BC-Call; also Externer-Call `level: domain`) · `{BC}/Service/<Name>.php` (Externer-Call `level: bc`) · `{BC}/Process/{Name}/Service/<Name>.php` (Externer-Call `level: process`, the default) | Generator: `DeveloperOwned=true` + `ForceOverwrite=false`, **header-bewusster Merge** (`ServiceClass` tag — a third write mode distinct from `@node-id` body-preserve and from `MergeDeveloperFile`) | One `__invoke()` Closure (Closure-Orchestrator, `rules-architecture` §3) per declared service name — reused across every node/process that targets the **same** thing (same Facade+Method for Cross-BC-Call, same `level` for Externer-Call; a different target is a different service, `V-SVC-TARGET-CONSISTENT`). Pre-wired scaffolding: **Cross-BC-Call** gets `$this->handle({CallerBC}::class)`/`handle({TargetBC}::class)` calls plus an **ACL docblock nudge** ("translate the response into your own vocabulary, never pass the foreign DTO through unchanged" — a Nudge, not a static enforcement); **Externer-Call** gets a PSR-18 gerüst via `$this->resource()->httpClient()` (null-guarded) plus a docblock documenting the transport-error → `ON_FAIL`/`ON_TIMEOUT` convention (and the built-in `jardisadapter/http` retry). **Cross-BC write target (G7):** when the Service call is a **write**, the target must be the foreign BC's `process()` (a modelled Process there) — never its aggregate write facade; the Designer/Validator enforce this (`V-XBC-WRITE-TARGET`, identical for UI and MCP) so a foreign-write `consumedCalls` entry only offers process methods. Reads may target the foreign `{Agg}Read` methods unrestricted. See `platform-cookbook` §2 Recipe 9 for the end-to-end DTO-translation → foreign `process()` → response-mapping pattern. Extends `{Domain}Context` like every generated behaviour class — **no constructor**. The write mode is the interesting part: on every rebuild the **class-level docblock is regenerated** (union of every declaring node's Mini-PRD description, deterministically sorted — "the docblock follows the node"), while the `__invoke()` body and any helper methods/`use`-imports you add are preserved exactly, same as a normal developer-merged file. This is the one place in the generated tree where a file's *header* is generator-owned and its *body* is dev-owned within the **same** file. The Service never appears in the generated Api-Spec. |
| Dev-Code (process) | `<BC>/Process/<Name>/Command/Handler/Action/<NodeClass>.php` | Developer-owned (custom-node body) | **The primary developer surface.** Process logic lives in the custom-node bodies — orchestrator + facade are hermetic. Additional developer segments (`Query/`+`Handler/`, `Repository/`, `Service/`) are authored as needed, not generated (the two exceptions above are the *generated* Service stubs a Cross-BC-Call/Externer-Call node declares — their `__invoke()` body is still yours to fill). |

**KI-Weg: a Rule guards a Command with an internal-list bestand-check**. Prosa in the Rule-stub docblock → the KI designs an `internal` Query with `limit` → MCP `save_queries` → `save_rules` with `reads:` pointing at that query → `build` → the Rule body reads over the Kernel-Naht with `limit: 1` and decides over `total`. Example: "Kunde hat offene Rechnungen" — Query `openInvoicesByCustomer` (`internal`, `limit: 1`) → `total > 0` → `RuleResult::reject(...)`. **Reader hint:** if a Rule or Process node body names a `Get…Handler::class` the build does not generate (e.g. a stale `Get{Agg}SelectorHandler` reference), the drift category `dev_code_dangling_handler` (`internal/devcodescan`, `appsvc/rules_drift.go`) flags it as "Dev-Code anpassen" — comment-blind, it does not read the docblock recipe.

**Three file modes from the generator:**

- **ForceOverwrite** — rewritten on every build. The **entire** `{Agg}/` tree, Domain/BC facades, the hermetic `{BC}Process` facade, Process DTO (`Command/<Name>.php`) + orchestrator (`Command/Handler/<Name>Handler.php`) files, event-data classes (`Event/<EventClass>.php`), and the Rule-Layer hermetic pair `{BC}/Rule/Data/RuleResult.php` + `{BC}/Rule/Guard/Guard<Command>.php`.
- **CreateIfNotExists** — written once, then preserved. `Config/.env*` files; Process custom-node stubs `{BC}/Process/{Name}/Command/Handler/Action/<NodeClass>.php` (one file per node).
- **`@node-id` body-preserve** — Process custom nodes **and sub-process nodes**. On rebuild the developer body + helpers are preserved, keyed by the `@node-id` marker (merge mechanics: [[beschreibt-bauweise-von::builder-generat-bauweise]] §7.2). For sub-process nodes: the first build emits a typed Dev-Stub (real Sub-DTO field names, `context()` call, status mapping, flat event bubbling, werfende Resolver); subsequent builds preserve the developer body unchanged. (The Process-Designer "Force" build path can deliberately overwrite a node body instead.) **Rule stubs** (`{BC}/Rule/<RuleName>.php`) use a related but distinct, per-method merge rule (no `@node-id` — a Rule has no single declaring node; same reference for mechanics): an **untouched** generated method migrates wholesale to the new signature/body, a **hand-edited** method is kept 100% verbatim (including its docblock) — for every generated method on a DeveloperOwned class, not just `__invoke`.

**Aggregate facade mechanics.** The aggregate's public surface for reads is `{Agg}/{Agg}Read.php` (`extends <Domain>Context`), reached via `$bc->{agg}()` → `handle(<Agg>Read::class)` — the Außentür (G2), public FQN `<Domain>\<BC>\Aggregate\<Agg>\<Agg>Read`. The write facade `{Agg}/{Agg}.php` (public FQN `<Domain>\<BC>\Aggregate\<Agg>\<Agg>`) hosts the commands + `event()` and is reached only family-internally via the Kernel-Naht, `$this->handle(<Agg>::class)` — never through `$bc->{agg}()`. Both facades are fully regenerated (ForceOverwrite) — there is no body-merge, no `@flow-id`. You never add methods to either; new aggregate behaviour is modelled in the Designer (structure) or authored as a Process (behaviour). ClassVersion resolves a versioned variant `<Domain>\<BC>\Aggregate\<Agg>\v{N}\<Agg>` (write) or `…\v{N}\<Agg>Read` (read) when the per-call `$version` argument is non-empty (see `platform-versioning`).

**Read base — every aggregate's read facade (`{Agg}Read`) carries the same uniform read API** (hermetic, emitted on every build, no switch):

| Method | Query DTO | Response data block |
|---|---|---|
| `get<Agg>ById($q)` | `Query\<Agg>ById { id }` — type follows the PK | `['<agg>' => Akte\|null]` |
| `get<Agg>ByIds($q)` | `Query\<Agg>ByIds { ids }` — `list<PK type>` | `['<agg>' => list<Akte>]` — **no `[0]` collapse** |
| `get<Agg>By<UniqueKey>($q)` | `Query\<Agg>By<Key> { <keyField> }` — camelCase column, no entity prefix | `['<agg>' => Akte\|null]` |
| `get<Agg>By<PluralKey>($q)` | `Query\<Agg>By<PluralKey> { <keyField>s }` — `list<key type>` | `['<agg>' => list<Akte>]` — **no `[0]` collapse** — only on an aggregate WITH a unique key |

`By<UniqueKey>` exists exactly once per aggregate — the identifier chain picks the unique business key (e.g. `getCounterByIdentifier(new QueryCounterByIdentifier(identifier: $i))`); an aggregate without a unique key gets only ById/ByIds. An aggregate WITH a unique key additionally gets the bulk variant `By<PluralKey>` (e.g. `getOrderByOrderNumbers`) — the Außentür-facing counterpart to ByIds for these aggregates (see Bulk-read recipe below). Further `get<Agg>By<Custom>` methods come from queries DECLARED in the BC's `Queries.yaml` (`Source.yaml` has no `queries:` text path — a leftover one is rejected, `V-QDEF-23`); `<agg>List(<Agg>ListFilter)` is the flat list — **not** part of the uniform read base above: it is an ordinary DECLARED query in the BC's `Queries.yaml` (the Grundliste, seeded there once when an aggregate is first saved), so an aggregate whose `Queries.yaml` carries no such query has no `<agg>List` method. A suffix-less `get<Agg>` does not exist. The ids are the **internal**, family-wide handle of the domain API (ById/ByIds keep being emitted regardless of a unique key) — the facade is no public REST surface; exposure is the surrounding app's decision — and FK/child internals stay encapsulated either way.

> **DTO class names vs. alias.** The read/write DTOs are really named **`<Agg>`** (Command, e.g. `Counter`) and **`<Agg>ById` / `<Agg>ByIds` / `<Agg>By<Key>`** (Query, e.g. `CounterById`) in their own `Command\` / `Query\` namespaces. The `Command…` / `Query…` prefix used throughout this skill (e.g. `CommandCounter`, `QueryCounterById`, `QueryCounterByIds`) is a **`use … as …` alias defined inside the aggregate's write facade** (Command) **or read facade** (Query), not the real class name. Code **outside** either facade must import the real name (`use …\Query\CounterById;`) or add its own `as` alias.

**Bulk-read recipe** (the recipe forks on whether the aggregate has a public unique key):

An aggregate **WITHOUT** a unique key: list items lead with the root id, so the set pattern is:

```php
$list  = $bc->counter()->counterList($filter);
$ids   = array_values(array_unique(array_column($list['items'], 'id')));
$akten = $bc->counter()->getCounterByIds(new QueryCounterByIds(ids: $ids));
// match per id — every Akte carries it
```

An aggregate **WITH** a unique key: the list SELECT leads with that key column instead of `id`, and the bulk read goes through the `By<PluralKey>` variant:

```php
$list  = $bc->order()->orderList($filter);
$keys  = array_values(array_unique(array_column($list['items'], 'orderNumber')));
$akten = $bc->order()->getOrderByOrderNumbers(new QueryOrderByOrderNumbers(orderNumbers: $keys));
// match per orderNumber — every Akte carries it
```

Edge behaviour (existing infrastructure semantics, nothing new underneath): empty input → empty list · duplicates → deduped (IN semantics) · missing keys/ids → partial result, no error (consistent with ById/By<Key> → `null`) · no order guarantee. Family-internal callers can still use `getCounterByIds`/`getById` either way — only the list-item handle and the Außentür (OpenAPI) surface are key-first when a unique key exists.

**Process facade mechanics.** `{BC}/Process/{BC}Process.php` (`extends <Domain>Context`) is hermetic — fully regenerated on every build (`ForceOverwrite`, no body merge, no `@process-id`). It carries one thin-dispatch method per process; the process logic lives in the orchestrator's custom-node bodies under `{BC}/Process/{Name}/Command/Handler/Action/`. **`subprocessOnly` flag:** a process with `subprocessOnly: true` in its YAML is a pure sub-process — the facade emits **no method** for it (it is only reachable as a sub-process node inside another process). The flag controls only the facade; the process DTO, orchestrator, and node stubs are always generated. A process without the flag (default) is an API entry point **and** usable as a sub-process node. The flag is set via the Designer-UI toggle „In API sichtbar" (default ON).

**BC facade caches nothing** (each `->{agg}()` / `->process()` is a fresh `handle()` resolve). **All facades** delegate through `context()` / `handle()`. **Never** instantiate handlers directly (V2/V3).

### 2. Where customization goes — the aggregate has no edit slot

The aggregate tree is hermetic; **nothing** under `{Agg}/` is a developer override point. There are exactly four sanctioned ways to change behaviour:

| You want to… | Do this |
|---|---|
| Add or change **behaviour** (validation, calculation, cross-aggregate coordination, event transport, external calls) | Model a **Process** in the Process Designer. Generator emits the DTO + orchestrator + node skeleton under `{BC}/Process/{Name}/Command/` and `Command/Handler/Action/` (plus `Event/` for event nodes), and a thin-dispatch method on the hermetic `{BC}Process` facade (`$bc->process()`). Your logic lives in the custom-node bodies (`@node-id` preserve). Additional segments (`Query/`, `Repository/`, `Service/`) are authored by the developer as needed. A process has **no aggregate ownership** and may coordinate several aggregates. |
| Change the aggregate's **structure / invariants** (entities, relations, keys, `required`/`depend` rules, commands/queries) | Re-model the aggregate in the Aggregate Designer (`Aggregate.yaml`) and rebuild. The generated tree changes accordingly. |
| Provide a **versioned variant** of a generated class (tenant / feature-flag) | Put the variant under `{Agg}/.../v{N}/<Class>.php` and select it **per call** via the `$version` argument threaded through every facade method — there is no domain-wide default (the Domain facade is `final`, `{Domain}Context` hermetic). ClassVersion (`LoadClassFromSubDirectory`) resolves `…\v{N}\<Class>` before the baseline. **Note:** version *creation* is not wired by any generator today — the *resolution* is. See `platform-versioning`. |
| Guard a Command with a **business rule / bestand-check** before it runs (Rules-Layer) | Declare a catalog entry + endpoint binding in `Rules.yaml` (BC-level, sibling of Process/Steckbrief). Generator emits a Rule stub under `{BC}/Rule/{RuleName}.php` (`__invoke({Cmd}DTO): RuleResult`) plus a hermetic Guard closure that runs the bound chain (AND, short-circuit) ahead of the Command. Your logic lives in the Rule stub body — bestand-checks stay within your **own BC** (M9), via the read facade or directly via the Kernel-Naht (`context()`) for a BC-internal read; anything needing a cross-BC read or a multi-step/side-effecting flow is Prozess-Territorium, not a Rule. **Until you implement it, the stub throws** (500), it does not fail open — see the Rule stub row in §1 and `platform-cookbook` §3 Troubleshooting. |

**Migration note (path collision).** In the pre-Platform-removal layout a file directly under `{Agg}/Command/…` was a **shadow override** that the ClassVersion reader (`segmentNames: ['', 'Platform']`) found *before* the generator class under `{Agg}/Platform/Command/…`. The Generator writes to `{Agg}/Command/…` itself (ForceOverwrite) — an old baseline override at that path **collides** and is overwritten. There is no auto-migration. To migrate such an override: move the behaviour into a Process (`{BC}/Process/`), or re-express it as a `v{N}/` versioned override, or re-model in the Designer. The build deletes the orphan `{Agg}/Platform/` directory on regen.

**Domain support code (VOs, Domain Services) a process needs** lives within the Process scope under `{BC}/Process/{Name}/` — never under the aggregate. The Generator owns only `Command/`, `Command/Handler/`, `Command/Handler/Action/`, and `Event/`; `Query/`, `Repository/`, and `Service/` are developer-authored segments. The exact shared-VO/Service home within the process scope is a developer convention (the generator does not dictate it).

**Exception — generated Service stubs are not always process-scoped.** A Cross-BC-Call node's Service always lands one level up, at `{Domain}/Service/` (the *calling* domain); an Externer-Call node's Service lands at whichever tier its `level` names — `{BC}/Process/{Name}/Service/` (`process`, default), `{BC}/Service/` (`bc`, new sibling of the aggregates), or `{Domain}/Service/` (`domain`, same Ablageort as Cross-BC-Call). These three Ablageorte are **generator-decided** (from the node's declared target/level) — you don't choose the directory by hand, only the fachlicher Service-Name and, for Externer-Call, the level. See §1 for the write mode (header-bewusster Merge).

**FieldMap + Entity Validators are generated** (`{BC}/FieldMap.php`, `{BC}/Entity/Validation/<Entity>Validator.php`, ForceOverwrite) — both are **BC-level** (BC-rooted, one per BC table over all BC tables), not aggregate-scoped. The FieldMap exposes one `{table}Columns()` write-map method per BC table. Logic that must participate in those generated entry points belongs in a **Process node** that runs before/after the aggregate call — never as an in-place edit of the FieldMap or validator.

**Rename & rebuild (object rename).** Domain, BC, aggregate, and process can be renamed in the Designer **even after a build exists**. A rename updates the definition and all referencing YAMLs immediately (`subProcess`, `consumedCalls` — cross-BC included, `accepts`), stamps the renamed artefact and every affected consumer "rebuild needed", and leaves a rename trace; the **next build** pulls the code along. What that means for your code:

- **Developer code survives every rename.** Dev-code-bearing process trees are **moved** (not deleted + regenerated): file **bodies** stay byte-identical; only the mechanical header (`namespace` line + `use` lines pointing at renamed generated namespaces) is patched — PSR-4 stays intact, PHPStan stays green. Hermetic trees (aggregate) are simply regenerated under the new name and the old tree is cleaned up. `v{N}/` directories move physically with the tree (no version-specific namespace follow-up yet).
- **Consumers show a reason note.** A consumer process whose `consumedCalls`/`subProcess` reference was rewritten carries a persisted note (old → new, affected calls), visible in its detail panel; a successful build of that consumer clears note + stamp.
- **Hand code outside the output tree is NOT rewritten.** App/bootstrap wiring or external event listeners that mention renamed FQCNs by hand get a naming warning in the rename preview — updating them is your job.
- **Node rename** (Ticket panel) keeps the `@node-id` body across the file rename; event-mode nodes get their `Event/` class swept along.
- Renaming back (new → old) is a normal rename with the same rules; a rename preview (impact list incl. foreign-BC consumers and dev-surface hints) runs before every level rename in the UI.

### 3. Prohibitions (V1–V13)

| # | Rule |
|---|---|
| V1 | Never edit anything under `{BC}/Aggregate/{Agg}/` — the whole aggregate tree is hermetic (ForceOverwrite). Your code lives in `{BC}/Process/{Name}/Command/Handler/Action/` (custom-node bodies) and in the developer-authored segments (`Query/`, `Repository/`, `Service/`). |
| V2 | No `new` for Services / VOs / Entities — only `Exception`, `RuntimeException`, `DateTimeImmutable`, `DateTime` |
| V3 | Never bypass `handle()` |
| V4 | No logic in Command / Query Handler Orchestrators — logic belongs in Actions |
| V5 | Never access Aggregate internals from outside — only via AggregateHandler |
| V6 | No cross-BC imports — communicate via a Domain Service, or via a modelled **Cross-BC-Call** node (generator emits the Service scaffolding, §1) |
| V7 | No traits, no `abstract` (except Exception hierarchies) |
| V8 | Never skip validation in Command → Entity → Aggregate chain |
| V9 | No persistence in Domain Services — only via `Repository::persist()` |
| V10 | No state in `{Domain}Context` subclasses (transient per call) — the generated base class of the Context-Familie (§1) |
| V11 | No business methods on Entities |
| V12 | Responses are arrays via FieldMapper, not typed DTOs. ⚠️ **Precision:** the generator also emits one `{Agg}/Command/Response/{Command}Response.php` per command — a plain data class carrying the reference values (root identifier + affected child reference) the handler writes into `$this->result()->setData([...])`. That class is a **contract type**, published so a client can be typed against the write door; it is **not** a return type. The handler signature stays `DomainResponseInterface`, its payload stays the array, and nothing about the dispatch changes — **do not rewrite a handler to return one of these classes.** They are hermetic like the rest of the tree (never edit), and derived from the same source the handler body is rendered from, so they cannot drift from what the handler actually writes. |
| V13 | A Rule (`{BC}/Rule/{Name}.php`) reads bestand only from its **own BC** — via that BC's read facade (`$this->handle({BC}::class)->{agg}()`) for a facade-exposed query, or directly via the Kernel-Naht (`$this->context({Handler}::class, $payload)()`) for a BC-internal read such as an internal list read with `limit: 1` — no foreign-BC import, no cross-BC read from inside a Rule (M9). Enforced by convention + a stub docblock nudge, not yet by static analysis. A cross-BC bestand-check belongs in a Process, not a Rule. |

### 4. Decision tree — "I need to…"

Every row resolves to a **Process** (the developer surface), a **Designer re-model**, or a **versioned override** — never an in-place edit of the aggregate.

| Need | Action | Pattern |
|---|---|---|
| Validate a domain concept (OBIS, IBAN) via a VO | Author a VO in the Process scope and use it from a custom-node body under `{BC}/Process/{Name}/Command/Handler/Action/` | Value Object |
| Load data from an external API | Model an **Externer-Call** node in the Process Designer (Ebenen-Wahl `process\|bc\|domain`) — the generator emits a Service stub pre-wired with a PSR-18 `resource()->httpClient()` gerüst + a transport-error docblock (§1). Or, for anything the two first-class node kinds don't fit, a hand-authored Domain Service in the Process scope (`Service/`), called from a custom-node body. | Adapter |
| Read from another BC | Model a **Cross-BC-Call** node in the Process Designer — the generator emits the target-facade wiring (reusing the node's `consumedCalls[0]`) plus a Service stub with pre-wired `handle()` calls and an ACL docblock nudge (§1); never import another BC directly. Target: the foreign `{Agg}Read` methods. | Adapter |
| Write to another BC | Model a **Cross-BC-Call** node targeting the foreign BC's **process** (never its aggregate) — the Designer only offers foreign process methods for a write target (`V-XBC-WRITE-TARGET`); the generated Service DTO-translates your input, calls `$this->handle({TargetBC}::class)->process()->{process}($dto)`, and maps the response back into your own vocabulary (ACL) — never a direct aggregate write on the foreign BC. See `platform-cookbook` §2 Recipe 9. | Adapter |
| New BC-level operation / coordinate several aggregates | Model a **Process**. Generator emits DTO + orchestrator + node skeleton under `{BC}/Process/{Name}/Command/` and `Command/Handler/Action/`, and a thin-dispatch method on the `{BC}Process` facade (`$bc->process()`). Logic lives in the custom-node bodies. | Facade + Workflow |
| New aggregate command/query, or tighten an aggregate's invariants | Re-model the aggregate in the Aggregate Designer (`Aggregate.yaml`) and rebuild — the generated command/query/validator tree changes with it. A new **query** surfaces inline on the read facade `{Agg}Read.php` (`$bc->{agg}()`, the Außentür); a new **command** surfaces inline on the write facade `{Agg}.php`, reachable only family-internally (`$this->handle({Agg}::class)`) — model a Process around it to expose it externally via `$bc->process()`. There is no slot to hand-edit either facade. | Designer |
| Add a calculated field to a query response | Run the aggregate query from a Process node, then enrich the response in the node body | Decorator (at process level) |
| Emit / route a domain event to a transport (Kafka, webhook, …) | Author the transport in a Process node that runs after the aggregate command — see `platform-cookbook` event-transport recipe. The generated `<Agg>EventRouter` is hermetic. | Adapter (at process level) |
| Remove an entity with a NOT-NULL `depend:` parent | **Not allowed** — the Build rejects Remove on an entity another entity depends on via NOT-NULL FK (F3.6 / V8-sibling). Re-model the aggregate to drop the NOT-NULL constraint, or model a soft-delete Process so dependents stay resolvable. | Designer + Process |
| Remove a `required` child (no `Remove…` command exists, or the last entry refuses to delete) | **By design** — a relation marked `required` (default; opt-out `required: false`) is an existence invariant. **`required·one`** emits **no `Remove…`** (only `Set…`); **`required·many`** keeps `Add…`/`Remove…(id)` but the Remove handler carries a **min-guard** that throws when count would hit 0 (Create also demands ≥1, `Count::min(1)`). To allow emptying, re-model as `required: false` and rebuild. | Designer (Aggregate.yaml) |
| Tenant- / feature-flag variant of any generated class | Put the variant under `{Agg}/.../v{N}/<Class>.php` and select it **per call** via the `$version` argument — no domain-wide default (see `platform-versioning`). Resolution is wired; creation is out of current scope. | ClassVersion |

### 5. New-code locations — everything developer-owned lives under `{BC}/Process/`

```
<Domain>/<BC>/                               ← Bounded-Context dir
├── Rule/                                    ← ★ Rule catalog (sibling of Process/ and Aggregate/)
│   ├── <RuleName>.php                       ← ★ your bestand-check body (DeveloperOwned, tag RuleClass)
│   ├── Data/RuleResult.php                  ← hermetic VO (Generated, ForceOverwrite)
│   ├── Guard/Guard<Command>.php             ← hermetic AND-chain closure (Generated, ForceOverwrite)
│   └── v{N}/<RuleName>.php                  ← versioned Rule override (ClassVersion)
├── Process/                                 ← ★ THE developer surface (sibling of Aggregate/)
│   ├── <BC>Process.php                      ← hermetic facade (Generated, ForceOverwrite) — $bc->process()
│   └── <Name>/
│       ├── Command/                         ← Generator-owned (ForceOverwrite, hermetic)
│       │   ├── <Name>.php                   ← Input DTO
│       │   └── Handler/
│       │       ├── <Name>Handler.php        ← orchestrator (regenerated, not edited)
│       │       └── Action/<NodeClass>.php   ← ★ custom-node body — your process logic
│       │                                      (CreateIfNotExists + @node-id preserve)
│       ├── Event/<EventClass>.php           ← event-data classes (Generated, ForceOverwrite)
│       ├── Query/ + Handler/                ← KI-/Dev-owned: author when the process needs reads
│       ├── Repository/                      ← KI-/Dev-owned: author when the process needs a repo
│       └── Service/                         ← KI-/Dev-owned: Domain Services, VOs (Process-Plus)
└── Aggregate/<Agg>/                         ← ★ GENERATED, NIE EDITIEREN (whole tree, no Platform/ segment)
    └── … (read/write facades, Aggregate, Command, Query, Event, Repository, v{N}/)   ← FieldMap + Entity/ are BC-level (one level up), NOT in the aggregate tree
```

All developer code — new classes **and** behaviour — lives under `{BC}/Process/{Name}/`. The Generator owns the `Command/` segment (DTO + orchestrator + node stubs) and `Event/`; the segments `Query/`, `Repository/`, `Service/` are developer-authored. The aggregate tree `{BC}/Aggregate/{Agg}/` is hermetic: never add or edit files there. The only in-aggregate non-generated artefact is a `v{N}/` versioned override (ClassVersion), which the generator neither emits nor deletes.

There is no aggregate-root file to extend, no `{Agg}/Api/` registry, and no `Platform/` directory. The aggregate's two facades host the inline registries of generated Commands (write facade, family-internal via the Kernel-Naht) and Queries/lists (read facade, reached via `$bc->{agg}()`, the Außentür); BC-level operations route through the `{BC}Process` facade via `$bc->process()`.

### 6. Implementation levels

No skipping, no parallel levels. PHPStan L8 + tests + review green between levels. All developer levels are expressed as **Process** work — the aggregate itself is generated.

| # | Level | Where | Anchor |
|---|---|---|---|
| 1 | Aggregate structure + invariants | Aggregate Designer (`Aggregate.yaml`) → generated `{Agg}/` tree | `tools-builder-engine`, `schema-authoring` |
| 2 | BC-level processes (behaviour) | Process Designer: `{BC}/Process/{Name}/Command/` (DTO) + `Command/Handler/` (orchestrator) + `Command/Handler/Action/` (one `<NodeClass>.php` per node) — your logic in the custom-node bodies (`@node-id` preserve) | `jardissupport/workflow` |
| 2a | Endpoint business rule (bestand-check gate before a Command runs) | `Rules.yaml` catalog + binding → `{BC}/Rule/{Name}.php` stub, your logic in `__invoke()` (own BC only via the Kernel-Naht, M9). **A stub not yet implemented throws (500), never `RuleResult::pass()`** — implement it before binding it live. | — (Rules-Layer, no separate package skill) |
| 3 | VOs + business validation used by a process | VO authored in the Process scope, used from a custom-node body | `jardissupport/validation` |
| 4 | Domain Services (external data, cross-BC, calculations) | Process scope, called from a custom-node body | `jardisadapter/dbconnection` |
| 5 | Workflows / long-running flows | Process orchestrator graph (`{Name}Handler::config()`) + custom nodes | `jardissupport/workflow`, `platform-workflow` |
| 6 | Event transport | Process node that runs after the aggregate command (the generated `<Agg>EventRouter` is hermetic) | `jardisadapter/messaging` |
| 7 | Non-functional (cache, logging) | Process node + DomainKernel wiring | `jardisadapter/cache`, `jardisadapter/logger` |

### 7. Constructing `DomainResponse`

In Process custom-node bodies (and any handler extending `<Domain>Context`):

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
| `ValidationError` (400) | DTO / invariant validation failed — thrown as a dedicated `ValidationException` in generated Validate classes, caught **before** the catch-all `\Throwable`; precedes Rule evaluation (form before business) |
| `RuleViolation` (422, Rules-Layer; requires `jardiscore/kernel` ≥ 1.1.0) | A bound Rule chain rejected the Command — built directly by the generated Guard-calling handler code, **never thrown**; payload `{rule, messageKey, context}` (stable across Rule versions, i18n `messageKey` resolved by the surrounding app). A **technical** failure inside a Rule's bestand-check (DB down, timeout) is never a 422 — it falls through to 500. |
| `Unauthorized` / `Forbidden` (401/403) | Raised by middleware, not here |
| `NotFound` (404) | Target absent |
| `Conflict` (409) | State machine rejects transition |
| `InternalError` (500) | Unexpected `Throwable` |

**Status staircase (Rules-Layer):** `400` (form) → `422` (business, Rule chain) → `500` (technical) — a Command dispatch checks field validation first (cheap, local), then the Rule chain (potentially DB-bound), so a malformed request never pays for a bestand-check it can't pass anyway. A Process node calling a Command translates this deterministically: `422 → ON_FAIL` (a painted edge, same as any other business rejection); `5xx → the exception path`, never `ON_FAIL` — never a blanket `isSuccess() ? ON_SUCCESS : ON_FAIL` mapping, which cannot tell the two apart.

**TOCTOU boundary (v1, known limitation).** A Rule's bestand-check and the Command's subsequent Apply/persist are two separate steps — nothing in the generated Guard or CommandHandler locks between them. A concurrent write between the Rule's read and the Command's write can pass a Rule that would have rejected the now-changed state (time-of-check-to-time-of-use). This is a deliberate v1 boundary, not a bug: harden it yourself where it matters — either with an optimistic-locking recheck on the write side, or (preferably, since the Aggregate persists unconditionally against whatever the schema allows) as a hard invariant in the DB schema (`UNIQUE`, `CHECK`, FK) that fails the write outright rather than relying on the Rule alone. A general recheck/optimistic-locking mechanism is not yet part of the generated code.

`$this->result()` helpers:

- `setData(array)` — replace payload (last writer wins)
- `addData(string $key, mixed $value)` — additive under key
- `addError(string $message)` — accumulates → `errors: [...]`
- `addEvent(object $event, EventScope $scope = EventScope::Internal)` — accumulates only; the handler does **not** dispatch. Generated aggregate events are all `EventScope::Internal`. Publication is the **caller's** job after commit: a Process node reads `$response->getEvents()` and hands each to a transport (see `platform-cookbook` §1); `EventScope::Domain` + a „verkünden" switch are planned, not yet emitted. `getEvents(?EventScope $scope = null)` filters by scope; with no argument it returns all scopes. `EventScope` is a `jardissupport/contracts` enum.

`DomainResponse`/`ContextResponse`/`DomainResponseTransformer` are **generated per domain** under `{Domain}\Response\` (the Response-Trio) — not package classes. The `DomainResponseInterface`/`ContextResponseInterface` contracts, the `ResponseStatus` enum, and `EventScope` live in `jardissupport/contracts` (no dedicated dev-skill).

### 8. Toolbox — which skill covers which Jardis package

When a Process node needs a Jardis runtime tool (cache, mail, queue, repository …), consult the package skill below before re-deriving the API. All packages are constructor-injected via `handle(<Service>::class, …)` from inside the node — never `new` (V2 / V3).

| Need | Skill |
|---|---|
| Cache (PSR-16, multi-layer, write-through) | `adapter-cache` |
| Logger (PSR-3, fluent builder, enrichers) | `adapter-logger` |
| Mail (SMTP, STARTTLS, attachments, retry) | `adapter-mailer` |
| HTTP client (PSR-18, cURL transport, retry) | `adapter-http` |
| Filesystem (local + S3, streams) | `adapter-filesystem` |
| Messaging (Kafka / Redis / RabbitMQ / DB / InMemory) | `adapter-messaging` |
| Event dispatcher (PSR-14, priorities, type-hierarchy) | `adapter-eventdispatcher` |
| DB connection (pool, read/write split, MySQL/Postgres/SQLite) | `adapter-dbconnection` |
| DB query builder (fluent SELECT/INSERT/UPDATE/DELETE, CTE, window) | `support-dbquery` |
| Repository (generic CRUD on top of dbquery) | `support-repository` |
| Validation (composite, fluent, object graph) | `support-validation` |
| Workflow engine (multi-step orchestration) | `support-workflow` (and `platform-workflow` for the Process Designer-Orchestrator API) |
| Auth (session, password hash, RBAC) | `support-auth` |
| Scheduling / cron parsing | `support-scheduling` |
| Secret resolution (encrypted `.env` values) | `support-secret` |
| Dotenv loader (cascade, type casting) | `support-dotenv` |
| Hydration, identity, FieldMapper, UUID/NanoID | `support-data` |
| ClassVersion loader / namespace injection | `support-classversion` (Designer-side: see `platform-versioning`) |
| Factory / container | `support-factory` |
| `DomainKernelInterface` (the DomainKernel) + Bootstrap-Packer `BuildDomainKernelFromEnv` (ENV-driven DomainKernel assembly) | `core-kernel` — `DomainApp`/`BoundedContext`/`ServiceRegistry` do not exist; their role is carried by the generated Domain facade + `<Domain>Context` (this skill, §1) |
| `DomainResponse`/`ContextResponse`/`DomainResponseTransformer` (generated per domain, `{Domain}\Response\`) | — Generat, no package skill (Response-Trio, §7 below) |
| One-time ENV-driven App entry point (`App/bootstrap.php` + `App/.env`, wires the DomainKernel + every workspace domain) | `core-kernel` (Bootstrap-Packer) — `jardiscore/foundation` does not exist; never reach for it |

### 9. Anchors

- `rules-architecture` (Säule 4 Data-Behavior-Separation drives the Service-Schicht-Hinweis in `platform-versioning` §2), `rules-patterns`, `rules-testing`
- `platform-versioning` (ClassVersion resolution + Versionierungs-Modell — `LoadClassFromSubDirectory`, `v{N}/` per-class)
- `platform-workflow` (Workflow-Engine API used by Process Designer orchestrators)
- `platform-cookbook` (Phase-3 recipes, event transport, troubleshooting)
- `DomainResponse`, `ContextResponse`, `DomainResponseTransformer`: generated per domain (`{Domain}\Response\`, Response-Trio, §7 above) — not package classes; `ResponseStatus` + the response/context interfaces: `jardissupport/contracts`
- `MessagingService`, `MessagePublisher`: `adapter-messaging`
- `EventListenerRegistryInterface`, `EventDispatcher`, `EventCollector`: `adapter-eventdispatcher`
- `HttpClientInterface`, `HttpClient`: `adapter-http`
- M9-Leseweg-Begründung (a Rule reads only its own BC): `wissensbasis/bc-interner-leseweg-rules.md` (go-target-lab) — there are no Rules-Layer implementation docs in the Builder repo
