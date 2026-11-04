### 0. DDD positioning

Jardis uses DDD vocabulary (BC, Aggregate, Domain Event, Repository, VO, Ubiquitous Language) under its own axioms — Closure-Orchestrator (`foundation-architecture` §3) and model-driven generation. See `foundation-architecture` § Positioning for the Functional-DDD stance this skill operates under.

| Rule | Consequence |
|---|---|
| **V11** — no business methods on entities | Behaviour lives in Actions and Domain Services, not on entities. Aggregate invariants are protected by the generated **pipeline**; cross-aggregate behaviour is authored as a **Process** (`{BC}/Process/`). |
| **V12** — responses are `DomainResponse` envelopes with an array payload | One serialisation surface across HTTP / CLI / queue. Typing is the transport adapter's job, not the domain's. The `data` array is flat at the root; inside a read answer the children are typed `{Child}Response` objects (`->`), which `json_encode` serialises like arrays. The generated `{Agg}/Command/Response/{Command}Response.php` classes do **not** change this: they are contract types describing the payload, never a handler return type. |
| **Call chains** | Aggregate reads (public surface): `$bc->{agg}()->get{Agg}ById\|ByIds\|By{Key}\|By{PluralKey}\|{agg}List(...)` (an aggregate WITH a public unique key carries `By{Key}`/`By{PluralKey}` and NO `ById`/`ByIds` on `{Agg}Read` — those two stay family-internal via the kernel seam; one without a key carries `ById`/`ByIds`) — the `{Agg}Read` facade is the call surface, no aggregate-root hop; an aggregate with no read door at `domain` or wider (every read row of its block `closed` in `Doors.json`) has no `{agg}()`/`{Agg}Read` at all and is reached only family-internally. Aggregate writes are **not** on the BC facade (G2) — the write facade `{Agg}` is reachable only family-internally, via the kernel seam: `$this->handle({Agg}::class)->{command}($dto)` (typically from a Process node body). BC-level processes (the other half of the public surface): `$bc->process()->{process}($dto)`. A command door at stance `domain` or wider (row of `Doors.json`) is a third entry of the public surface: `$bc->{command}($dto)` (incl. `$bc->create{Agg}($dto)`; Rules-Layer G10). A column declared `computed` in `Aggregate.json` is in no Create/Set/Add/Update command or request body; its one write path is the family-internal `$this->handle({Agg}::class)->set{Entity}Computed($dto)` (raises its own `{Agg}[{Fragment}]ComputedUpdated` event like every command, no outer door — `generated-code-recipes` Recipe 13). Taxonomy is discoverable; `handle()` / ClassVersion resolution anchors at each level. |

Upheld without compromise: aggregates are transaction boundaries with a single root; BCs are strict language boundaries (V6). The aggregate is **100 % generated** — you do not extend it in place. A Rich-Model instinct (custom validation, a VO, a domain service, coordinating two aggregates) is satisfied by **modelling a Process** in the Process Designer, never by editing the aggregate tree.

When Domain logic needs a Jardis runtime tool (cache, mail, queue, repository, HTTP client, …), look up the responsible package skill in §8 (Toolbox) before re-deriving the API.

### 1. Generated baseline — Platform-free hermetic layout

Facade chain: Domain → BC → **Aggregate Read facade** (`{Agg}Read`, reached via `$bc->{agg}()`) → query method — this is the BC facade's public surface, plus a parallel Domain → BC → Process facade → process chain (`$bc->process()`) and one direct method per command door at `domain` or wider (`$bc->{command}($dto)`, delegating into the command handler). The **Aggregate Write facade** (`{Agg}`, same directory) hosts the commands + `event()`; it sits behind the kernel seam (`handle()`/`context()`) and is reachable only from inside the Context family (classes extending the Domain Context) — never from the BC facade. Example: aggregate `Counter` in BC `Counter` in domain `MeterDevice`.

**Route convention of the public surface.** Every build also writes a machine-readable description of this same surface — one OpenAPI 3.1 document per domain at `Api/{Domain}/openapi.yaml`, a sibling of `App/` outside the PSR-4 tree. The routes it declares are the canonical HTTP shape of the public surface, and a transport layer (hand-written or generated) is expected to mount exactly these:

| Command / entry | Route |
|---|---|
| `Update{Agg}` | `PUT /{bc}/{aggs}/{identifier}` *(full replacement of the root scalars; `PATCH` stays free for a future partial-update concept)* |
| `Remove{Agg}` | `DELETE /{bc}/{aggs}/{identifier}` |
| `Add{Agg}{Child}` | `POST /{bc}/{aggs}/{identifier}/{children}` |
| `Remove{Agg}{Child}` | `DELETE /{bc}/{aggs}/{identifier}/{children}/{childKey}` |
| `Set{Something}` | `PUT /{bc}/{aggs}/{identifier}/{something}` |
| Process | `POST /{bc}/{name}` *(lcfirst of the process name — `processes/` no longer appears in the path; `operationId` is unchanged, still `{bc}_processes_{name}`)* |

**Exposing child commands.** Exposure is not limited to root commands: every aggregate command — root (`Update{Agg}`, `Remove{Agg}`) and child (`Add{Child}`, `Set{Entity}`, `Update{Entity}`, `Remove{Child}`) — opened on the BC facade by the stance of its row in `Doors.json` (block = the aggregate, row = the class name of the command, `closed` by default): at `domain` or wider it is the facade door `{lcfirst(Command)}({Cmd}DTO)` (e.g. `addGoodsReceiptLine()` on the generated `Purchasing` facade), at `outside` also a route and a right. A command door at `domain` or wider without a bound Rule only raises the warning `V-RULE-5` (its message says that process rules do not apply on this path). The key `bindings.{cmd}.expose` of `Closures.json` is retired (`V-DOOR-0`).

**Create is a door like every other.** The row is `Create{Agg}` (one name in every form: facade method `create{Agg}()`, handler `Create{Agg}`, guard `GuardCreate{Agg}`, right `{bc}.{agg}:create{Agg}`). At `domain` or wider it is the facade door `{BC}::create{Agg}({Agg}DTO)`; at `outside` it also has the route `POST /{bc}/{aggs}` (answer 201) and a right. A method name that collides with another facade method of the BC (also with `{agg}()`) is `V-QDEF-23` (blocker). A creation that needs more than the aggregate's own Create still runs through a Process — model one, open its row, and it appears as `POST /{bc}/{name}`.

**`Remove{Agg}{Child}` is not emitted for every child.** The table above gives the route *shape*; whether the command exists at all depends on the child. Two independent rules suppress the remove mutation of an `erm:one` child — and when either applies, there is **no** `Aggregate/Action/Remove{Child}.php`, **no** AggregateHandler method, **no** command and therefore **no** route:

| Case | Why |
|---|---|
| **DEPEND leaf whose depend-FK is NOT NULL** | removing it would null a mandatory column — the database would reject it |
| **`required` containment child** | there is no valid removed state |

A depend leaf whose FKs are all nullable keeps its remove mutation. `Remove{Agg}` (the root) is always emitted and is unaffected by this rule. So: if you expect a `remove{Child}()` on the write facade and it is missing, check the depend-FK's nullability first — that absence is the rule working, not a defect. Model the removal as a Process if the business really needs it (which then also has to say what replaces the mandatory reference).

Path parameters carry the **business identifier** wherever the aggregate or child has one; the internal id is a documented fallback only, and the build emits a warning when it has to fall back, so the gap stays visible. A door appears in the document only at the stance `outside` or wider (`Doors.json`; `domain` is facade only, `closed` nothing) — changing a stance moves it in and out of the document without touching a line of PHP. The document carries no `servers:` block: base URL and mount prefix are the surrounding app's business.

**`openapi.yaml` has a sibling artefact:** `Api/{Domain}/routes.php`, a hermetic `jardiscore/app` route-registration closure built from the same route table — how a transport layer wires it, decoding/dispatch/envelope details, and its `Api/{Domain}/` neighbourhood: `generated-code-wire-transport` §4 "Generated route registration".

**Notation:** aggregate code lives under `{BC}/Model/{Agg}/`; this skill abbreviates that directory as `{Agg}/`. Processes live one level up, under `{BC}/Process/` — a sibling of `{BC}/Model/`, **not** under any aggregate.

**The one rule:** The **entire** `{Agg}/` tree (`{BC}/Model/{Agg}/…`) is regenerated on every build (ForceOverwrite per file) — never edit anything inside it. (The collector segment is named `Model/`, not `Aggregate/`, renamed 2026-09-23; a BC-wide build cleans up a pre-existing `{BC}/Aggregate/` tree left over from before this rename, and dev-code imports of the old `…\Aggregate\{Agg}\…` namespace are rewritten to `…\Model\{Agg}\…` automatically by the merge.) There is **no `Platform/` segment**, in neither path nor namespace; the directory `{Agg}/` *is* the hermetic generator output. The directory hosts **two** facades: the write facade `{Agg}/{Agg}.php` (Commands + `event()`) and the read facade `{Agg}/{Agg}Read.php` (`get{Agg}ById`/`ByIds`/`By{Key}` + lists). Only the read facade is reached via the BC accessor `$bc->{agg}()` (the public surface, G2); the write facade is reached exclusively family-internally via the kernel seam, `$this->handle({Agg}::class)` — the same `protected` `handle()`/`context()` corridor every generated class in the Context family shares (code outside the Context family cannot call either method; PHP visibility enforces it at the type-system level, not just by convention). Hand code stands only at the places of the one table in §10 — never in the aggregate tree. The Generator emits the command-path segment: `Command/{Name}.php` DTO (`ForceOverwrite`), `Command/Handler/{Name}Handler.php` orchestrator (`ForceOverwrite`), `Command/Handler/Action/<NodeClass>.php` node stubs (`CreateIfNotExists` + `@node-id` body-preserve for custom and sub-process nodes), and event-data classes under `Event/` (`ForceOverwrite`, hermetic). Custom-node stubs are `CreateIfNotExists` with `@node-id` body-preserve, where your process logic lives. A versioned variant exists only for a stub class (node, Closure/Rule, Domain Service): it lives in a per-class `v{N}/` subdir resolved by ClassVersion (see `generated-code-versioning`); a hermetic class has none.

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
    │                                                 direct method per command door at `domain` or wider
    │                                                 — Rules-Layer G10)
    ├── Closure/                                   ← ★ HAND-CODE PLACE — BC-level Closure catalog (sibling of Process/)
    │   ├── <RuleName>.php                        ← Closure stub, DeveloperOwned (tag RuleClass)
    │   │                                            __invoke(...) — 0..n typed parameters per the
    │   │                                            catalog's declared `input[]`, returns the judgement
    │   │                                            `RuleResult` or a plain scalar per `output` (own BC
    │   │                                            only via the kernel seam, M9) ← your logic
    │   ├── Guard/Guard<Command>.php               ← hermetic AND-chain closure per bound endpoint
    │   │                                            — Generated, ForceOverwrite
    │   └── v{N}/<RuleName>.php                   ← versioned Closure override, ClassVersion-resolved
    ├── Service/                                  ← ★ HAND-CODE PLACE — Domain Services (sibling of Closure/ and Process/), the one location
    │   ├── <ServiceName>.php                     ← Domain Service stub, body yours (own BC read-only; a foreign BC only through its doors, §10)
    │   └── v{N}/<ServiceName>.php                ← versioned Service override, ClassVersion-resolved
    ├── Process/                                  ← BC-level Processes (siblings of Model/); hand code only in the ★ Action/ node bodies
    │   ├── <BC>Process.php                       ← Process facade — Generated, ForceOverwrite, HERMETIC
    │   │                                            (thin dispatch, one method per process, no body merge)
    │   └── <Name>/                               ← one process
    │       ├── Command/                          ← Generator-owned (ForceOverwrite, hermetic)
    │       │   ├── <Name>.php                    ← Input DTO
    │       │   └── Handler/
    │       │       ├── <Name>Handler.php         ← the door; the graph is its definition()
    │       │       │                                (regenerated, not edited)
    │       │       ├── <Name>Context.php         ← typed run view, one getter per node
    │       │       ├── <Name>Validator.php       ← input gate (only when the input carries a rule)
    │       │       ├── <Name><Value>.php         ← object value of a `gives` with fields
    │       │       └── Action/<NodeClass>.php    ← Node stubs, one file per node
    │       │                                        (custom: CreateIfNotExists + @node-id body-preserve;
    │       │                                         sub-process: CreateIfNotExists + @node-id body-preserve,
    │       │                                         typed Dev-Stub — see generated-code-recipes §2 Recipe 8) ← your logic
    │       └── Event/<EventClass>.php            ← Event-data classes (Generator, ForceOverwrite)
    ├── FieldMap.php                              ← ★ BC-LEVEL FieldMap — Generated, ForceOverwrite
    │                                                (ONE per BC, namespace <Domain>\<BC>; one {table}Columns() method per BC table, all BC tables)
    ├── Data/<ValueListName>.php                  ← value-list enum, one per entry of the BC's ValueLists.json — Generated, ForceOverwrite
    ├── Entity/                                   ← ★ BC-LEVEL schema layer — Generated, ForceOverwrite
    │   ├── <Entity>.php                          ←   persistence entity (#[Table]/#[Column]), one per BC table
    │   └── Validation/<Entity>Validator.php      ←   field-level validator, one per BC table
    └── Model/<Agg>/                              ← ★ GENERATED, NEVER EDIT (whole tree ForceOverwrite)
        ├── <Agg>.php                             ← WRITE facade (extends <Domain>Context), hosts inline
        │                                           Command methods + event(); family-internal only
        │                                           ($this->handle(<Agg>::class) — no BC-facade accessor)
        ├── <Agg>Read.php                         ← READ facade (extends <Domain>Context), hosts inline
        │                                           Query/list methods; reached via $bc->{agg}() (the public surface)
        ├── Aggregate/{<Agg>.php, Entity/<Entity>.php, Action/{Set,Add,Remove}<Entity>.php}   ← DOMAIN aggregate entity (#[Aggregate]/#[Relation]) — distinct from the BC-root persistence Entity above
        ├── Command/{<DTO>.php}  +  Handler/{Create<Agg>,{Set,Add,Remove}<Entity>}.php  +  Handler/Action/{Build*Data,HydrateCreate<Agg>Entities}.php  +  Validation/{<DTO>Validator,Validate<Action>}.php
        ├── Query/{<Agg>ById,<Agg>ByIds,<Agg>By<Key>,<Agg>By<PluralKey>,<Agg>By<Custom>…,<Agg>ListFilter}.php  +  Handler/Get<Agg>{ById,ByIds,By<Key>,By<PluralKey>,By<Custom>…,List}Handler.php  (`By<PluralKey>` only with a unique key)
        ├── Event/{<Agg>{Created,Removed,Updated,…}.php, <Agg>EventRouter.php, <Agg>Events.php}
        └── Repository/{<Agg>Repository,Query<Agg>{ById,ByIds,By<Key>,By<PluralKey>,…},Transform<Agg>,Validate<Agg>,Persist<Agg>,Query<Agg>Additions,Query<Agg>List}.php
```

> **Note:** there is no `{Agg}/Api/` directory and no `Platform/` directory. The aggregate use-case API surface is split across two sibling facades in the same directory: the **write** facade `{Agg}/{Agg}.php` (FQN `<Domain>\<BC>\Model\<Agg>\<Agg>`, Commands + `event()`, family-internal via the kernel seam) and the **read** facade `{Agg}/{Agg}Read.php` (FQN `…\<Agg>\<Agg>Read`, Queries/lists, reached directly via `$bc->{agg}()` — the public surface). When BC and aggregate share a name, the BC facade imports the read facade under an alias `{Agg}AggregateRead` (e.g. `CounterAggregateRead`) to avoid a class-name collision with the BC's own class name; the accessor itself stays `{agg}()` either way (same pattern the write facade already used: `{Agg}Aggregate`). The Events registry lives under `{Agg}/Event/{Agg}Events.php`. **Processes** are BC-level (`{BC}/Process/{Name}/`, sibling of `{BC}/Model/`), reached via `$bc->process()->{process}()` through the hermetic `{BC}Process` facade. The process follows the same segment convention as the aggregate: Generator emits `Command/` (DTO + `Handler/` + `Handler/Action/`) and `Event/`; hand code stands only in the node bodies of `Handler/Action/` (§10).

**Vocabulary glossary** (binding for this skill set — the canonical term table; sibling skills point here):

| Term | Meaning |
|---|---|
| **Aggregate facade** (write) | `{Agg}/{Agg}.php` — Commands + `event()`; the aggregate is the write model (DDD). Family-internally reachable via the kernel seam, not via the BC facade. |
| **Read facade** `{Agg}Read` | `{Agg}/{Agg}Read.php` — one method per read door at `domain` or wider (`Doors.json`): `get{Agg}ById`/`ByIds` for an aggregate WITHOUT a public unique key, `By{Key}`/`By{PluralKey}` (no `ById`/`ByIds`) for one WITH it; `{agg}List` only if `Queries.json` declares it; declared queries of the root entity at `domain` or wider. Incl. `$version`, reachable via `$bc->{agg}()`. |
| **Public surface** | The public methods of the BC facade: `{agg}(): {Agg}Read` per aggregate + `process(): {BC}Process` + one direct method entry per **command door at `domain` or wider** (incl. `create{Agg}()`; Rules-Layer G10). No general write accessor. |
| **Kernel seam** | `handle()` / `context()` on `<Domain>Context` (the generated, hermetic base-class body — no package base class) — the family corridor through which all generated classes reach one another (incl. the aggregate write facade). `protected` — code outside the Context family cannot call `handle()`/`context()`; PHP visibility enforces it at the type-system level. |
| **Context family** / family-internal | Classes that extend `<Domain>Context` (aggregate facades, Process handlers, node actions, services, Rules, …) — they share the kernel seam among themselves. Code outside the family (transport layer, controllers) has no `handle()` access and must go through the public surface. |
| **Closure** | `{BC}/Closure/{Name}.php` — a `Closures.json` catalog entry (DeveloperOwned body), reusable across aggregates. Its signature follows the entry's declared contract: **0..n** named, typed `input[]` parameters (`command`/`aggregate`/`scalar`/`valuelist`, each optionally `optional`/a list) and one `output` — either the judgement type **verdict** (`RuleResult`) or a plain scalar from the closed column-type vocabulary. A **Rule** is simply the verdict-output case — in DDD vocabulary a **Specification**: input a subject, output `passed` or `rejected(reason)`. Callable exclusively via `$this->handle({Closure}::class)` (ClassVersion-capable, `Closure/v{N}/`) — never `new`. **Guard-bindable** (usable in `bindings.{Command}.chain`) requires additionally at most one `command` input **and** at most one `aggregate` input, no `scalar`/`valuelist` input (V-RULE-11) — the single-input `__invoke({Cmd}DTO): RuleResult` shape used throughout this section is exactly that bindable case, not the only signature the catalog allows; a freely composed Closure (e.g. a calculation with several scalar inputs) is equally valid but only reachable via `uses`, never via a Guard chain. Reads existing data only from its **own** BC (M9 prohibition: no foreign-BC import) — via its own read facade for facade-routed queries, or directly via the kernel seam (`$this->context({Handler}::class, $payload)()`) for a BC-internal read — the declared list with `limit: 1`, decided over `total` (see the Rule stub row below). |
| **`aggregate` input** | Carries the **aggregate entity** (`{Agg}\Model\{Agg}\Aggregate\Entity\{Agg}`) — the root plus its children through typed getters `get{Kind}()` (a list for `erm: many`) — never the read-facade DTO. The same type is in the stub parameter, the Guard `$root` and the Rule-node loader, so a Rule checks children it already holds instead of declaring a helper query (`reads`) for data that is loaded anyway; a body that uses only root getters runs unchanged. An example's `given.root` (for an input named `root`) carries the root fields plus the children under their relation names (nested objects / lists; V-RULE-22 reports an unknown key), and `ClosureTestCase::aggregate()` builds the entity from it; the work package lists the child getters in `types[].children`. At a Guard chain (a Command endpoint), the generated CommandHandler hands in the root it already loaded (`$handler->getData()`) — the Closure never loads it itself. A Create-Command has no root yet, so an `aggregate` input cannot bind to a Create-Command's chain (V-RULE-11). A Rule-node in a Process loads the root itself, through a generated, hermetic `{Agg}/Repository/Load{Agg}ForRule.php` closure — the same Query+Repository path the CommandHandler uses, never the read facade. When that lookup finds nothing, the node rejects (`NodeResult::reject`) with `RuleViolation` (422), a fixed `{lcfirst(bc)}.{lcfirst(agg)}.notFound` messageKey, `context: {identifier}` and no `rule` key (the rejection did not come from any catalog Closure's predicate). |
| **Guard** | `{BC}/Closure/Guard/Guard{Command}.php` (for the Create command `GuardCreate{Agg}.php`; a `Guard{Agg}.php` of an older build stays orphaned — delete it by hand) — generated, hermetic Chain-of-Responsibility Closure per bound endpoint: runs the configured Rule chain as AND with short-circuit. The generated CommandHandler only calls `$this->handle(Guard{Command}::class)($cmd)` — no Guard logic of its own. |
| **RuleViolation (422)** | Rejection status of a Rule (requires `jardiscore/kernel` ≥ v1.1.0) — payload `{rule, messageKey, context}`, stable across Rule versions. When the catalog entry declares a `messageKey`, the 422 carries exactly that key (set as a literal by the generated Guard and Process Rule node) — the `messageKey` passed to `RuleResult::reject()` is then not taken over; without a catalog key the body's key passes through. No exception path; the handler builds the 422 response directly. A Command door declares the `messageKey` of its 422 as a closed enum of the catalog keys of all Rules that can reject it (Rule-Sets resolved), as does a Process door while only Rules with catalog keys are reachable; the 422 stays open (plain `RuleViolation`, no enum) as soon as a reachable Rule has no catalog key or a dev body of the door's own BC is reachable, or a cross-BC call, external call or custom node with a foreign-BC call is reachable next to the Rules (its service body can answer 422 with its own key); a cross-BC or external call alone declares none. |

