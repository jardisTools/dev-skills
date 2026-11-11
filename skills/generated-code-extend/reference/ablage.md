### 10. Where hand code may stand — the one table

Hand code is written only into the stubs a build produces and into `App/**` delivery code; every other file is generated and hermetic. This is the single place-table of the skill set — every other skill points here and carries no list of its own. A PRD or plan that names a piece of work sorts it by this table; a place that is not in it does not exist. Hand code uses only Jardis artefacts (kernel seam, packages, Queries, Commands and Processes, value lists); no own SQL, no helper or base classes, no foreign-BC access except through its doors.

<!-- generat:begin ablage-tabelle (internal/docgen/ablage.go from genpath + stubmanifest; regenerate: make ablage-tabelle; do not edit by hand) -->
| # | Ort | schreibt | Modus | erlaubte Abhängigkeiten | Manifest-Art |
|---|---|---|---|---|---|
| 1 | `<Domain>.php`, `<Domain>Context.php` (domain root) | Generator | hermetic (ForceOverwrite) | `contracts`, support packages; never `JardisCore` (Kernel-Entkopplung stage 2) | — |
| 2 | `{BC}/{BC}.php`, `{BC}/FieldMap.php`, `{BC}/Entity/**`, `{BC}/Data/{Name}.php` (one enum per value list), `{BC}/Process/{BC}Process.php` | Generator | hermetic (ForceOverwrite) | own BC, packages | — |
| 3 | `{BC}/Model/{Agg}/**` — includes the classes of every query declared in the BC-wide `Queries.json` (`Query/`, `Query/Handler/`, `Repository/` of the aggregate that owns the query root) and the classes of the Update doors: `Command/{Cmd}Patch.php`, `Command/Handler/Action/Resolve{Cmd}.php` and, for `Update{Kind}`, `Command/Handler/Action/Find{Cmd}Target.php` (generated, no dev body, although they sit under `Action/`) | Generator | hermetic (ForceOverwrite), no `v{N}/` | own BC, packages | — |
| 4 | `{BC}/Process/{P}/Command/{P}.php` (+ item classes), `Command/Handler/{P}Handler.php` (the one door class, the graph is its `definition()`), `{P}Context.php` (one typed getter per node), `{P}Validator.php` (dropped when it would be empty), `{P}{Name}.php` (object value of a `gives` with fields), `Event/**`, generated Rule nodes in `Action/` | Generator | hermetic (ForceOverwrite) | kernel seam, packages | — |
| 5 | `{BC}/Process/{P}/Command/Handler/Action/{Node}.php` | frame Generator, **body hand** | stub: `CreateIfNotExists` + `@node-id` body-preserve; a `v{N}/` variant is allowed | kernel seam (`handle` / `context` / `read`), Queries and Commands of the own BC, value lists, packages, sub-process and Cross-BC-Call nodes, Domain Services of `{BC}/Service/` via `handle()` | `node` |
| 6 | `{BC}/Closure/{Name}.php` | frame Generator, **body hand** | stub: `RuleClass` header-splice; a `v{N}/` variant is allowed | the own BC read-only (M9, V13), value lists, packages | `closure` |
| 7 | `{BC}/Closure/Guard/*` | Generator | hermetic (ForceOverwrite) | `response` (`RuleResult`) | — |
| 8 | `{BC}/Service/{Name}.php` | frame Generator from a model artefact — a Closures.json catalog entry of kind `service` (the fourth closure kind, MCP `save_closures`) **or** a stub derived from a Cross-BC-Call/External-Call node — **body hand** | stub: header-aware merge (`RuleClass` for a catalog Service, `ServiceClass` for a derived stub); a name used by both is the blocker `V-SVC-NAME`; a `v{N}/` variant is allowed | the own BC read-only (M9) — a write in the own BC is a node's business, not a Service's; a foreign BC only through its doors: `process()` or a command door at `domain` or wider writes, `{Agg}Read` reads — a foreign aggregate, repository or SQL is prohibited; called from node bodies via `handle()` | `service` |
| 9 | `Api/{Domain}/openapi.yaml`, `routes.php`, `versions.php`, `rights.json` (the rights catalog, MCP resource, only for an app with a login), `system.php` (the system run over `SignedInRun`, only for an app with a login); `App/middleware.php` | Generator | hermetic (delivery) | `jardiscore/app` (`RequireRight`, `SignedInRun`), `response` | — |
| 10 | `App/**` — `bootstrap.php`, project middleware per FQCN, event listeners, transport (all except the generated `middleware.php`) | Generator (first creation) and **hand** (the AI, on the human's request) | the place of the AI's own implementation; no business logic here, the rules of hand code apply | the doors `{agg}()` / `process()`, `jardiscore/app`, packages; never `Model/**`, a repository or `DbQuery` | `delivery` (reserved: the Builder writes no stub here today) |
| 11 | `v{N}/` subdir next to a stub class (rows 5, 6, 8) | frame: versioned build (fresh `final` stub frame, no `extends`), **body hand** | ClassVersion-resolved variant of a stub; body-preserve on every rebuild; never beside a hermetic class | those of the stub it varies | the kind of the stub it varies, `v{N}` set |
| 12 | `tests/**` | **hand** (the tests); Generator (scaffold: `tests/Support/{Domain}/`, `tests/Unit/Closure/{Domain}/ClosureTestCase.php`) | no production-code place — Jardis generates the scaffold, never the test | — | — |

**The Stub-Manifest.** Every build writes `.jardis/stubs.json` at the project root (committed, one file per project): one entry per stub of the stub rows — `path`, `kind` (`node`, `closure`, `service`, `delivery`), `marker` (the open-body text of the fresh frame) and `v{N}` (empty = baseline; set for the `v{N}/` frames). It is the list of the places the AI writes into: a partial build merges, an entry never outlives its file, a filled stub stays listed. A hand file of the AI that is neither in the manifest nor at a place of this table does not exist.
<!-- generat:end ablage-tabelle -->

**The context head of a stub (PHPDoc chain).** Business meaning is description (Domain → BC → Process → Node) and lands as PHPDoc, the context the AI reads where it implements. The Domain description stands at `<Domain>.php`, the BC description at `{BC}/{BC}.php`, the process description at `{P}Handler` (rows 1, 2, 4); every stub of rows 5, 6 and 8 carries the chain as the last block of its class docblock — generated frame, so every build refreshes it and the hand-written body is never touched:

```
 * Kontext-Kette (von oben nach unten):
 * Domain: <Name>
 *     <description of the Domain, indented, may span lines>
 * BC: <Name>
 *     <description of the BC>
 * Prozess: <Name>            (node stubs only)
 *     <description of the process>
 * Knoten: <Class>            (Closure: / Service: for a stub without a process)
```

A level line is `<Level>: <Name>` at the start of a docblock line and carries the name only; description lines are indented, an empty description leaves the level line without text (the registry warns `V-DESC-EMPTY` for an empty Domain, BC or process description). The node, Closure or Service's own description is the prose above the chain. The Gesetz-Gate (invariant D, `StubChainRule`) checks the level lines — a node stub needs Domain, BC, Prozess, Knoten; a Closure stub Domain, BC, Closure; a Domain Service stub Domain, BC, Service — never the text.

**If the model cannot express it,** no new hand-code place opens up: a missing capability is a gap in Jardis. When you work on Jardis itself, build it in the package or the generator first and use it afterwards; when you work on an app, report it as an issue. A substitute in the project — an interim, a workaround, a class in a place that is not in the table — is a violation, not progress. Hand code at a place of the table that covers such a gap once is counted by its kind; the same kind a second time is no single case: park the affected place, report the missing capability and keep building everything that does not depend on it.

**Removed hand-code places** (a file there is a violation; the generator emits no new ones):

1. `{Domain}/Service/*` (the Cross-BC-Call stub always, External-Call `level: domain`) → `{BC}/Service/` of the calling BC.
2. `{BC}/Process/{P}/Service/` (External-Call `level: process`, formerly the default) → `{BC}/Service/`; the External-Call field `level` is retired.
3. `{BC}/Process/{P}/Query/` and `Repository/` → none: reading is a declared Query, writing is an aggregate Command or a `process()` call.
4. `{BC}/Process/{P}/ValueObject/` and free projector or helper classes → none.
5. `v{N}/` variants of hermetic classes → none; `v{N}/` exists only for rows 5, 6 and 8. `App/**` is the delivery place of row 10; a process trigger by event or time is a project of its own, not a hand-code place.

The generator emits none of these places any more; a developer body left there moves to `{BC}/Service/` with the next build (Hinweis per move).

