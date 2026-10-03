---
name: design-headless-mcp
description: Driving a Jardis workspace headless through `jardis mcp` — Tools as actions vs Resources as read-only, the Workspace to Schema to Aggregate to Process to Build to Code-read workflow, the strategic-design surface (glossary, Steckbrief, planned BCs, Context-Map edges with the eight canonical DDD patterns, and the read-only drift check declared-vs-real coupling), the Type-A GUI-replacement pattern (output directory via update_project_settings, code via code-file/code-tree resources, a new workspace means a new process), documented workspace-registry limits, and structured error envelopes (confirm flags, BUILD_RUNNING/DRAFT_EXISTS). Use when an AI must design, build, or inspect a Jardis domain without a browser.
zone: post-active
persona: C
prerequisites: []
next: [generated-code-extend]
---

## Scope

This skill is the headless twin of driving the Jardis Designer by hand. There is deliberately no
"Designer-Companion" persona in this bundle (`docs/SKILL-FORMAT.md` §3a) — an AI that drives
`jardis mcp` end to end is doing that companion's job through a different transport. It keeps the `post-active`/`C` label deliberately: the moment `build`
succeeds, this same AI is already the Persona-C implementer that `generated-code-extend`
addresses next. The stretch is the transport (MCP calls instead of UI clicks), not the phase.

### 1. The surface: Tools act, Resources read

`jardis mcp` exposes two primitive kinds, both stateless per call (no server-side session):

- **Tools** perform an action (create, save, validate, build) and return a result or a
  structured error envelope (§6).
- **Resources** (plain or templated, e.g. `jardis://tree`, `jardis://schema/{domain}/{bc}`)
  are read-only projections of the current on-disk state — call them again after a write to
  see the effect, there is no push/subscribe.

The full catalogue (on the order of 80 tools plus 70+ resources/templates — do not hardcode them
from memory, they grow with every strategic-design increment) is a lived artefact, not something to memorise here — consult it before
guessing a name (see Reference).

### 2. End-to-end workflow

One walk from an empty workspace to readable generated code. Each step is a Tool unless noted:

1. **Workspace** — point the running `jardis mcp` process at a project root (passed at process
   start). If the domain structure does not exist yet, build it up with the structural tools
   (create a domain, add a bounded context, add an aggregate) before importing schema.
2. **`import_schema`** — write the bounded context's schema, either from a database
   introspection or from a schema drafted for a domain idea (see `design-draft-schema`), whose
   JSON you pass in the `yaml` argument (wire-key kept for contract stability — it carries JSON
   content). This Tool **is** the authoring door: a `Schema.json` is never placed into the
   workspace as a hand-written file. It normalises `tables` the same way `analyze_schema` and the
   UI do (columns, indexes, foreign keys per table; a column-level `unique` becomes an index)
   and, when `reportMd` is omitted, writes `SchemaReport.md` from that analysis.
3. **`save_aggregate`** — persist the aggregate's designer graph (entities, relations, keys).
   Returns a mtime `CONFLICT` if the on-disk graph moved under you — reload and retry, or pass
   the force flag once you have confirmed the overwrite is intended. Node positions and the
   viewport are optional: when absent, the value in `Layout.json` is kept. A node's optional
   `path` (as returned by the aggregate load) is accepted, so a table placed at more than one
   position survives load then save.
4. **`save_naming`** — apply/confirm the field-mapping naming conventions for the bounded
   context before building.
5. **`create_process`** / **`save_process`** / **`validate_process`** — model a BC-level
   process graph (if the change is process-level behaviour rather than aggregate structure),
   iterate, and check it for structural findings before building. On `save_process`, omitting
   `layoutJson` keeps the stored layout; passing `null` deletes it.
6. **`build`** — a long-running Tool: generates the aggregate (and/or process) code tree onto
   disk. Reports progress notifications; a concurrent build on the same scope refuses with
   `BUILD_RUNNING`, an unsaved designer draft with `DRAFT_EXISTS` (§6).
7. **Read the generated code** — via the `code-tree` and `code-file` Resource templates
   (chunked reads with an offset/limit window; large files stay well inside the response cap).
   This replaces "open it in the editor" from the browser flow.

Any step can be re-run idempotently against its own Resource first (e.g. read `jardis://tree`
or the aggregate Resource before `save_aggregate`) to confirm the current state before writing.

**Strategic-design tools (optional, additive to the workflow above):** `save_glossary` (a BC's
Ubiquitous-Language glossary), `save_steckbrief` (a BC's canvas — purpose, classification
read-through, collaborators), `create_planned_bc` / `update_planned_bc` / `delete_planned_bc`
(a not-yet-built BC's canvas, at Domain/Subdomain level) and `promote_bc` (turn a planned BC
into a real one). None of these are required to generate code — they capture organisational
metadata (subdomain classification, terminology, context boundaries) a human or AI can set
independently of the Schema→Aggregate→Process→Build chain. Full field shapes: the tools' JSON input schemas (`tools/list`).

**Context Map (per Domain, full MCP parity with the UI board):** declared BC-to-BC
relationships are edges carrying one of the eight canonical DDD patterns (Partnership, Shared
Kernel, Customer/Supplier, Conformist, ACL, Open Host Service, Published Language, Separate
Ways) — authored via `create_context_map_edge` / `update_context_map_edge` /
`delete_context_map_edge` and `rewire_context_map_edge`; freely named external systems (the
only deletable node kind — real and planned BC nodes are derived from the workspace, never
created here) via `create_context_map_external_node` / `update_context_map_external_node` /
`delete_context_map_external_node`; node positions via `save_context_map_node_position`
(presentation-only, an MCP client may skip positions entirely). Read side: the
`context-map-patterns` Resource is the closed pattern catalogue (fetch it instead of hardcoding
the eight names), plus the `context-map` and `context-map-declared-targets` Resource templates.

**Drift check is a read-only Resource, not a tool:** `jardis://context-map/{domain}/drift`
compares the declared edges against the real, synchronous `consumedCalls` the domain's
processes actually make (Ist) — six finding categories (undeclared, unused, direction
contradiction, pattern contradiction, both-ways-vs-directed, and the quiet in-agreement state);
each real edge additionally carries its call evidence (`Calls` — `processName`/`nodeID`/`facade`/`processFile`),
computed on demand, never persisted. Resolving a finding goes through the ordinary edge CRUD
tools above — there is deliberately no bulk "align everything" tool; each link/delete is a
human- or agent-confirmed single step.

**Rules-Layer — full MCP parity:** a BC's `Closures.json`
(the Rule catalog + per-Command bindings that guard writes between the aggregate and its process,
`generated-code-extend`) is fully MCP-reachable, same as everything above — no browser-only
capability here. `save_closures` persists the whole catalog+bindings document (LockedSave — a
`CONFLICT` means the on-disk file moved under you, same mtime/force pattern as `save_aggregate`);
Catalog entries: `reads: []` / `examples: []` clear the stored list, an omitted key keeps it;
`messageKey` is never cleared — omitted or `""` keeps the stored key, and an empty one on a
verdict closure without `compose` is derived as `{lcfirst(bc)}.{lcfirst(name)}`. Bindings: omit a
command's entry to leave it unbound; `chain: []` is a deliberately empty guard (the guard class is
still generated, the drift report lists it as an empty chain).
`validate_closures` checks a not-yet-saved catalog/bindings set against the V-RULE-* rules
(read-only, no write). Read side: the `closures` Resource template returns the catalog+bindings
as-is, plus `dockable` (per closure: `guard` — commands whose chain it may still join; `ruleNode` —
whether it may back a Process Rule-node; `sets` — Rule-Sets it may join as a member), `usedAt`
(per closure: guard chains, Rule-nodes, other Closures' `uses`), `usedAtUnknown` (true when the
process-usage scan itself failed, so an empty `usedAt` must NOT be read as "genuinely unused"),
`scalarTypes` (the closed column-type vocabulary a scalar input/output may declare) and
`policyCoverage` (Steckbrief (BC canvas) policy id → `{level, closures, wirkorte}`, `level` one of `covered` /
`no-effect` / `no-anchor` / `unknown-usage` — `no-anchor` also stands for a policy with no
anchoring closure at all when the usage scan failed, since that fact is Usage-independent);
`closures-drift` is a read-only finding set — `policy_without_rule` / `rule_without_policy` /
`empty_chain` — mirroring the Context-Map drift-check pattern (computed on demand, never
persisted, no bulk-align tool here either). Lifecycle tools mirror `rename_query`/`delete_query`/
`duplicate_query`'s pattern: `rename_closure` (`confirm=true` required, or `dryRun=true` for a
no-write preview that needs no `confirm`; cascades the rename into every binding chain naming it, every Closure's `uses`
list — Set member or free sub-closure reference alike — and every process Rule-node's `rule`
field — a materialised stub or `Closure/v{N}/` override is never moved, only reported in
`warnings`; the closure's `messageKey` follows the rename when it was the derived key of the old
name, a hand-set key stays), `delete_closure` (blocked with a `409 IN_USE` by >=1 bound chain, >=1 Closure's `uses`
(Set membership or a free sub-closure reference), or >=1 process Rule-node — `force=true` overrides it, stripping the name
from every binding chain and `uses` list and dropping a chain row it empties; deleting a Rule-Set
itself leaves its members untouched in the catalog), `duplicate_closure` (`newName` optional — a
blank value auto-suggests the first free `{name}Copy`/`{name}CopyN`; the copy starts unbound and
carries no dev-body, and duplicating a Rule-Set's member never enters the copy into that Set).
Inspect `jardis://closures/{domain}/{subdomain}/{bc}/usage` before a real rename/delete — the same
bindings/processes lists travel back in the response's `consequences` field either way.

**Writing a Closure body — start from the `…/work` work package.** A Closure catalog entry
declares **0..n** typed `input[]` parameters (`command`/`aggregate`/`scalar`/`valuelist`) and one
`output` that is either `verdict` or a plain scalar — not just a single guard-bound
`command`/`aggregate` input with a `verdict` output. Before writing (or asking an AI to write) a
Closure's `__invoke` body, read the per-Closure Resource template
`jardis://closures/{domain}/{subdomain}/{bc}/{name}/work` — it composes everything needed for that
one Closure on a single call, assembled purely from already-saved catalogs (Closures.json,
Queries.json, the aggregate/command catalog, ValueLists.json, usage), no second source of truth:
`task` (a plain-language brief), `contract` (description, the exact `__invoke` signature, each
input's PHP type, the output's type plus its pass/reject or return shape, `policyRef`, `examples`),
`files` (the stub path plus `stubExists`, the generated test's path plus `testExists` — both
Go-derived facts, true only when that file was actually found on disk —, and how to run the
test), `uses`/`reads` (every composed Closure / readable query, each with its own signature and
call recipe), `types` (the PHP field shape of every `command`/`aggregate`/`valuelist` input),
`context` (helper text and guardrails for the `handle()`/`context()` corridor), `usedAt` (guard
chains — a guard entry carries `viaSet` when a Rule-Set is the only path binding the Closure to
that chain — process Rule-nodes, and other Closures' `uses`), `body` (`offen`/`geschrieben` —
whether the generated stub still throws its Not-implemented marker; absent for a Rule-Set, which
has no body of its own), and `missing` (concrete next steps). Flow: read the resource → write the
`__invoke` body at `files.stub` → `build` → run the generated test at `files.test` until it is
green. Also reachable over HTTP — `GET /api/closures/{domain}/{subdomain}/{bc}/{name}/work` calls
the SAME service method and returns the SAME JSON shape; the contract itself is written via
`save_closures` — Resource and HTTP route only compose a read-friendly, AI-facing view of the same
data.

**Queries-Layer — full MCP parity:** a BC's
`Queries.json` (declarative read queries — `root:` entity, visibility `internal`/`public`,
condition tree, parameters, joins; every query is a paginated list answering
`{items,total,limit,offset}`, so there is no output-form key — a document still carrying `form:`
is rejected by the Blocker V-QDEF-24) is fully MCP-reachable, same
pattern as Rules above. `save_queries` persists the whole artefact (LockedSave — mtime `CONFLICT`
like `save_aggregate`/`save_closures`; a draft with findings still writes, `valid`/`errors`/
`warnings`/`suggestions` travel in the response); `validate_queries` checks a not-yet-saved query
set against V-QDEF-1..16, 18..24 plus RB1 (name collision with the read base, Blocker) —
read-only, no write. `preview_queries` is read-only and
returns the **generated PHP code** a build would write for the query set (never SQL — the Builder
never emits SQL, only PHP) plus artefact-wide findings; pass `compareWithStored: true` for a
consequences preview (`consequences`) of confirming this draft — a query appearing or disappearing, a
visibility switch `internal`↔`public`, the BC facade's public surface base path `GET …/{agg}` moving with the query
named `{agg}List` (fileAdded/fileRemoved/fileChanged, facadeMethodAdded/facadeMethodRemoved,
basePathAdded/basePathRemoved) — before committing to `save_queries`. The comparison state is
always what lies on disk, never a set the caller supplies, and `consequences` stays empty for a
draft the rules reject (the rule id then travels alone, in `errors`). Lifecycle tools mirror `rename_process`/
`delete_process`'s pattern: `rename_query` (cascades into every BC-local reference — a Closures.json
catalog entry's `reads:[]` and process nodes — `confirm=true` required), `delete_query`
(`force=true` overrides an `IN_USE` 409 from >=1 declared reader), `duplicate_query` (auto-suggests
a free `{name}Copy`/`{name}CopyN` name, visibility always falls back to `internal`). Read side: the
`queries` Resource template returns the artefact as-is (a BC without one reads as an empty set,
never an error); `queries-usage` lists which Rules/process nodes reference a given query — the
same computation `rename_query`/`delete_query` use for their `consequences`, not a second one.

**Other confirm gates:** `rename_value_list` (like `rename_closure`) needs `confirm=true` for a real
rename, while `dryRun=true` runs without `confirm`. `set_stack_selection` needs `confirm=true` when
the selection switches the project's database (read the current one from the `runtime-stack`
resource); every other change, and re-sending the same database, needs none.

**Schema→SQL export:** `export_schema_sql` returns one dialect's DDL as
text (read-only preview, four dialects available — the same `appsvc.SchemaExportService.ExportSQL`
the UI's preview-sql route calls); `export_schema_sql_files` writes all four `Schema.{dialect}.sql`
files into the BC directory (mutating). Neither tool exists to author a `Schema.json` from —
that direction is `design-draft-schema` / `import_schema`; these are the reverse, DB-migration-facing
export.

### 3. Type-A pattern — GUI affordance replaced by a data path

Some browser-UI affordances have no MCP button; they become a plain data operation instead:

- **Choosing an output directory** — the UI opens a native file dialog; an MCP client instead
  calls `update_project_settings` with `outputDir`. It is a workspace-level setting (not per
  domain) and, for a jardis-app-template clone, is `<root>/src`. Changing an already-set value
  needs `confirm=true` (nothing is moved; the old tree stays on disk).
- **"Open in editor"** — the UI opens the generated file in an IDE; an MCP client reads it via
  the `code-file` Resource template instead (chunked, offset/limit).
- **Switching projects** — the UI has a workspace switcher; an MCP client instead starts a
  second `jardis mcp` process pointed at the other workspace root. There is no in-process
  workspace switch.

### 4. Documented limits

The **host-wide workspace registry** (the list of known projects a human uses to jump between
workspaces from the UI's start screen) has no Tool surface — adding, forgetting, or
re-labelling an entry in that registry is not exposed via MCP. This is a deliberate, bounded
gap, not an oversight: an MCP client that wants a different workspace starts a new `jardis mcp`
process against that workspace's root, for which registry membership is irrelevant. Do not
invent a tool call for this — there isn't one.

### 5. Freshness/Drift at startup

Every MCP session start (`New`) carries a live self-check into the server's `initialize`
Instructions, comparing the running binary's embedded revision against the repository it sits
in. A client should read this banner before
trusting a reported finding: a stale binary can silently still be missing capabilities or fixes
(including ones documented in this very skill set) that only exist in the newer source it has
fallen behind.

### 6. Error ergonomics

Every Tool/Resource failure comes back as one structured envelope — `code`, an optional
`ruleId` + `location` for validator findings, a human `message`, and an actionable `hint` —
never a bare error string. Recurring codes worth recognising by name: `CONFLICT` ("already
exists" — safe to retry with a different name or treat as confirmation), `CONFIRM_REQUIRED`
(a destructive call — delete/rename with real impact — needs an explicit confirm flag; inspect
the matching preview Resource first), `BUILD_RUNNING` / `DRAFT_EXISTS` (build-time guards, §2
step 6), `NOT_FOUND`, `VALIDATION` (inspect `details` for field-level findings). Never retry a
`VALIDATION` or `CONFIRM_REQUIRED` failure unchanged — read the envelope, fix the cause or
supply the confirmation, then retry.

### 7. Reference

- Full Tool/Resource catalogue: `tools/list`, `resources/list`, `resources/templates/list` on the
  running server — the live surface is the only catalogue, there is no inventory document.
- Once generated code exists and you are implementing behaviour inside it: `generated-code-extend`.
- Designing a schema's content from a domain idea instead of introspecting a live database, then
  feeding it through `import_schema`: `design-draft-schema`.
