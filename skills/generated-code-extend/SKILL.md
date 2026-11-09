---
name: generated-code-extend
description: "Extending Designer-generated code: hermetic `{BC}/Model/{Agg}/` tree, facade surface, the one table of hand-code places (node bodies, Closures, Domain Services, App/**), V1-V13 prohibitions. Use when adding hand code, a Closure, a Domain Service, or computed fields to generated code."
zone: post-active
persona: C
profile: jardis
prerequisites: [generated-code-request-path]
next: [generated-code-versioning, generated-code-workflow-api, generated-code-recipes, generated-code-wire-transport]
---

This skill is an index; the detail lives in `reference/`, one leaf per topic. Read exactly the leaf whose question matches your task, not all of them.
Hand code stands only at the places of the one table (`reference/ablage.md`); the hard prohibitions V1-V13 are kept in this index.
Never edit `{BC}/Model/{Agg}/` — fix the model, then rebuild.

## Triggers

Extending Designer-generated code — the whole `{BC}/Model/{Agg}/` tree is Generator-owned and hermetic (ForceOverwrite, never edit). The BC facade's public surface exposes `{agg}(): {Agg}Read` (read-only queries + lists), `process()`, and a direct method per command door at `domain` or wider (stance rows of `Doors.json`); the write facade `{Agg}` (Commands + `event()`) is family-internal via the protected kernel seam (`$this->handle()`). Hand code stands only at the places of the one table in §10: process node bodies under `{BC}/Process/{Name}/Command/Handler/Action/`, Closure/Rule bodies under `{BC}/Closure/` (own-BC-read-only, M9), Domain Services under `{BC}/Service/`, and `App/**` delivery code without business logic. Covers computed fields (`Set{Entity}Computed`, family-internal), the aggregate entity with child getters at the Rule `aggregate` input, cross-BC reads as a derived Cross-BC-Call Service, V1–V13 prohibitions, decision tree, generated-file modes, DomainResponse construction incl. `RuleViolation` 422. ClassVersion → `generated-code-versioning`. Workflow API → `generated-code-workflow-api`. Recipes → `generated-code-recipes`.

### Mandatory rules

### 3. Prohibitions (V1–V13)

| # | Rule |
|---|---|
| V1 | Never edit anything under `{BC}/Model/{Agg}/` — the whole aggregate tree is hermetic (ForceOverwrite). Hand code stands only at the places of §10 (node bodies, Closure/Rule bodies, `{BC}/Service/`, `App/**`). |
| V2 | No `new` for Services / VOs / Entities — only `Exception`, `RuntimeException`, `DateTimeImmutable`, `DateTime` |
| V3 | Never bypass `handle()` |
| V4 | No logic in Command / Query Handler Orchestrators — logic belongs in Actions |
| V5 | Never access Aggregate internals from outside — only via AggregateHandler |
| V6 | No foreign-BC internals — a foreign BC is reached only through its doors (`{Agg}Read` reads; writes go through `process()` or a command door at `domain` or wider, `{BC}::{command}()`), from a node body or a Domain Service, e.g. through a modelled **Cross-BC-Call** node (generator emits the Service scaffolding, §1); a foreign aggregate, repository or SQL is prohibited |
| V7 | No traits, no `abstract` (except Exception hierarchies) |
| V8 | Never skip validation in Command → Entity → Aggregate chain |
| V9 | No persistence in Domain Services — a Domain Service writes nothing in its own BC (own BC read-only, M9); a foreign BC is written only through its doors at `domain` or wider (`process()` or a command door) |
| V10 | No state in `{Domain}Context` subclasses (transient per call) — the generated base class of the Context family (§1) |
| V11 | No business methods on Entities |
| V12 | The `data` payload is an array (flat at the root), the handler returns `DomainResponseInterface` — not a typed DTO; read answers carry their children as `{Child}Response` objects (`->`, see the read-base table in §1). ⚠️ **Precision:** the generator also emits one `{Agg}/Command/Response/{Command}Response.php` per command — a plain data class carrying the reference values (root identifier + affected child reference) the handler writes into `$this->result()->setData([...])`. **Inside vs. outside:** inside the BC (handler, write facade, a process node through `handle()`/`context()`) the command answer additionally carries the root `id` when the root has a public key, so a node can pass it on without re-reading; outside (`{BC}::{command}()`, `routes.php`, OpenAPI, cross-BC) `ProjectAnswer` projects the answer onto `{Command}Response` — there no `id` as long as a key exists; without a key `id` is the identifier, inside and outside. That class is a **contract type**, published so a client can be typed against the write door; it is **not** a return type. The handler signature stays `DomainResponseInterface`, its payload stays the array, and nothing about the dispatch changes — **do not rewrite a handler to return one of these classes.** They are hermetic like the rest of the tree (never edit), and derived from the same source the handler body is rendered from, so they cannot drift from what the handler actually writes. A process door is projected differently: `{BC}Process::{name}()` cuts its answer with `ProjectOutcome`, one `OutcomeForm` per outcome. |
| V13 | A Rule (`{BC}/Closure/{Name}.php`) reads existing data only from its **own BC** — via that BC's read facade (`$this->handle({BC}::class)->{agg}()`) for a query at `domain` or wider, or directly via the kernel seam (`$this->context({Handler}::class, $payload)()`) for a BC-internal read such as a list read with `limit: 1` — no foreign-BC import, no cross-BC read from inside a Rule (M9). Enforced by convention + a stub docblock nudge, not yet by static analysis. A cross-BC existing-data check belongs in a Process, not a Rule. |

### Navigation

Navigation: one leaf per topic under `reference/`. Pick the row whose question fits the task.

| Question the leaf answers | Leaf |
|---|---|
| I need the DDD stance, the hermetic output layout (directory tree), the route convention, exposing/creating commands, the notation, the vocabulary glossary or the Context-family seam (§0, §1) | reference/ddd-positioning-and-layout.md |
| I need the facade mechanics (aggregate/process/BC facade), the file modes (ForceOverwrite, CreateIfNotExists, DeveloperOwned), the read base, the data block, tenant/user markers or the bulk-read recipe (§1, continued) | reference/facades-and-file-modes.md |
| I want to customize generated code: where a change goes, the decision tree "I need to…", the new-code locations, or the implementation levels (§2, §4, §5, §6) | reference/customization-and-places.md |
| I construct or read a `DomainResponse`: status codes, the Rules-Layer status staircase, `addError`, the result helpers (§7) | reference/domain-response.md |
| I look for the Jardis package skill that covers a runtime tool (cache, mail, queue, HTTP, DB) or the sibling skills (§8, §9) | reference/toolbox-and-anchors.md |
| I ask where hand code may stand: the one place-table, the Stub-Manifest, the PHPDoc context chain of a stub (§10) | reference/ablage.md |
| I look for an entry that no longer applies (archive, currently empty) | reference/archiv.md |

### Zitate §N (citation targets)

- §0 → „0. DDD positioning“ (ddd-positioning-and-layout)
- §1 → „1. Generated baseline — Platform-free hermetic layout“ (ddd-positioning-and-layout, facades-and-file-modes)
- §2 → „2. Where customization goes — the aggregate has no edit slot“ (customization-and-places)
- §3 → „3. Prohibitions (V1–V13)“ (this index)
- §4 → „4. Decision tree — "I need to…"“ (customization-and-places)
- §5 → „5. New-code locations — the places of the one table (§10)“ (customization-and-places)
- §6 → „6. Implementation levels“ (customization-and-places)
- §7 → „7. Constructing `DomainResponse`“ (domain-response)
- §8 → „8. Toolbox — which skill covers which Jardis package“ (toolbox-and-anchors)
- §9 → „9. Anchors“ (toolbox-and-anchors)
- §10 → „10. Where hand code may stand — the one table“ (ablage; Builder gate 27e reads its generated zone)
- §11 → „11. The way“ (this index)

### 11. The way

The order of work for a change to a Jardis domain. Each step has one skill; do not skip ahead.

1. **Model.** Express the change in the model first (schema, aggregate, process, queries, closures), through the authoring doors. → `design-headless-mcp`, `design-draft-schema`.
2. **Check.** Validate the model before building; fix findings in the model, never in the output. → `design-headless-mcp` (the `validate_*` tools).
3. **Build.** Generate the code; the hermetic tree is overwritten on every build (§2). → `design-headless-mcp`.
4. **Hand code.** Fill the stubs the build produced — node bodies, Closure/Rule bodies, `{BC}/Service/` — at the places of §10, using only Jardis artefacts. What the model cannot express is a missing Jardis capability: build it in the package or generator (when working on Jardis itself) or report it as an issue (when working on an app), then wait — no substitute in the project. → this skill, §5 and §6; recipes in `generated-code-recipes`.
5. **Tests.** Test behaviour through the doors, with the generated scaffold. → `foundation-testing` §6.
6. **QA.** Run the gates of the project profile, model checks first, then the gates. → `process-run-stage` §7.
