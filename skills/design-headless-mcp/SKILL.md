---
name: design-headless-mcp
description: Driving a Jardis workspace headless through `jardis mcp` - tools vs resources, Schema to Aggregate to Process to Build workflow, strategic design, doors. Use when an AI must design, build, or inspect a Jardis domain without a browser (MCP, save_doors, import_blueprint).
zone: post-active
persona: C
profile: jardis
prerequisites: [generated-code-request-path]
next: [generated-code-extend]
---

This skill is an index; the detail lives in `reference/`, one leaf per topic. Read exactly the leaf whose question matches your task, not all of them.

## Triggers

Driving a Jardis workspace headless through `jardis mcp` — Tools as actions vs Resources as read-only, the Workspace to Schema to Aggregate to Process to Build to Code-read workflow, the strategic-design surface (glossary, Steckbrief, planned BCs, Context-Map edges with the eight canonical DDD patterns, and the read-only drift check declared-vs-real coupling), the Type-A GUI-replacement pattern (output directory via update_project_settings, code via code-file/code-tree resources, a new workspace means a new process), documented workspace-registry limits, computed columns (`computed` on `save_aggregate`), process input types from the backend (`inputTypes`, `valueList`), the transaction default with its opt-out warning (`runInTransaction`, `V-PROC-TX-OPTOUT`), declared class `versions` (`update_domain_manifest`, resource `jardis://domain/{domain}/manifest`), blueprint import (`import_blueprint`, resource `jardis://blueprints`, its 404/412/409 refusals), the door stances of a bounded context (`save_doors`, resources `jardis://doors/{domain}/{subdomain}/{bc}` and `jardis://doors/{domain}`, `V-DOOR-0`), and structured error envelopes (confirm flags, BUILD_RUNNING/DRAFT_EXISTS). Use when an AI must design, build, or inspect a Jardis domain without a browser.

## Scope

This skill is the headless twin of driving the Jardis Designer by hand. There is deliberately no
"Designer-Companion" persona in this bundle (`docs/SKILL-FORMAT.md` §3a) — an AI that drives
`jardis mcp` end to end is doing that companion's job through a different transport. It keeps the `post-active`/`C` label deliberately: the moment `build`
succeeds, this same AI is already the Persona-C implementer that `generated-code-extend`
addresses next. The stretch is the transport (MCP calls instead of UI clicks), not the phase.

### Mandatory rules

- **Human gates:** you never merge, push or answer a card. The human decides on the `merge`
  card and Jardis merges; on the `delivery` card Jardis pushes or opens the pull request.
  Creating a studio (`start` card), the answers, discarding and deleting are the human's
  handles with no MCP tool. Read card state in the `cards` list of `jardis://studios/{name}`.

### 6. Error ergonomics

Every Tool/Resource failure comes back as one structured envelope — `code`, an optional
`ruleId` + `location` for validator findings, a human `message`, and an actionable `hint` —
never a bare error string. Recurring codes worth recognising by name: `CONFLICT` ("already
exists" — safe to retry with a different name or treat as confirmation; **not** for `import_blueprint`, where it means nothing was written), `CONFIRM_REQUIRED`
(a destructive call — delete/rename with real impact — needs an explicit confirm flag; inspect
the matching preview Resource first), `BUILD_RUNNING` / `DRAFT_EXISTS` (build-time guards, §2
step 6), `NOT_FOUND`, `VALIDATION` (inspect `details` for field-level findings). Never retry a
`VALIDATION` or `CONFIRM_REQUIRED` failure unchanged — read the envelope, fix the cause or
supply the confirmation, then retry.

### Navigation

Navigation: one leaf per topic under `reference/`. Pick the row whose question fits the task.

| Question the leaf answers | Leaf |
|---|---|
| I want the surface in one view: Tools act vs Resources read, the build-control tools (`run_qa`, `run_make_target`, `post_studio_card`), the studio resources, why not to memorise the catalogue (§1) | reference/surface-tools-resources.md |
| I start the walk (§2, steps 1-2): point the workspace at a project root, declare class versions, import a ready-made BC with `import_blueprint`, write the schema with `import_schema` | reference/workflow-workspace-and-schema.md |
| I persist an aggregate graph with `save_aggregate` (§2, step 3): `graph.adopts`, `identifier`, `computed`, the mtime `CONFLICT`, `suggest_aggregate_edges` | reference/workflow-aggregate-save.md |
| I set the format markers of an aggregate (§2, step 3): `tenant`/`user`, `access` (per-record protection), `writeOnly`, the retired `visibility`, request attributes | reference/workflow-aggregate-format-markers.md |
| I model a process (§2, steps 4-5): `save_naming`, `create_process`/`save_process`, input types, `gives`, `@outcome`, transaction, the access statement, sharing as a node call | reference/workflow-process-modelling.md |
| I build and read back (§2, steps 6-7): `build`/`build_domain`, findings, `BUILD_RUNNING`, `code-tree`/`code-file` | reference/workflow-build-and-read-code.md |
| I report to the human while a studio builds (§2, studio build control): cards, fixed answer ids, `run_qa` and `run_make_target` preconditions | reference/workflow-studio-build-control.md |
| I capture strategy (§2 continued): glossary, Steckbrief, planned BCs, subdomain classification, value lists from rights, BC-declared ENV keys (`save_bc_env`), the Context Map and its drift Resource | reference/workflow-strategic-and-context-map.md |
| I work on the Rules-Layer (§2 continued): `save_closures`, catalog merge, lifecycle tools, the Closure `…/work` package, aggregate delete, a rule node in a process | reference/workflow-rules-layer-closures.md |
| I work on the Queries-Layer (§2 continued): `save_queries`, `chain` and join pairs, `probe_query`, usage and consequences | reference/workflow-queries-layer.md |
| I decide who reaches a door (§2 continued): `save_doors`, door stances, access rows, the `middleware` setting, `sessionProcesses`, `groupLookup`, `groupDoors`, `backfillPrelude`, the rights list | reference/workflow-doors-and-project-settings.md |
| I meet a confirm gate (`rename_value_list`, `set_stack_selection`) or want the Schema to SQL export (§2 continued) | reference/workflow-confirm-gates-and-sql-export.md |
| I look for a GUI affordance with no MCP button (output directory, open in editor, switching projects), the documented limit of the workspace registry, or the startup freshness banner (§3, §4, §5) | reference/type-a-limits-freshness.md |
| I look for an entry that no longer applies (archive, currently without entry) | reference/archiv.md |

### Zitate §N (citation targets)

- §1 → „1. The surface: Tools act, Resources read“ (surface-tools-resources)
- §2 → „2. End-to-end workflow“ (workflow-workspace-and-schema, workflow-aggregate-save, workflow-aggregate-format-markers, workflow-process-modelling, workflow-build-and-read-code, workflow-studio-build-control; continuation: workflow-strategic-and-context-map, workflow-rules-layer-closures, workflow-queries-layer, workflow-doors-and-project-settings, workflow-confirm-gates-and-sql-export; the human gates of the studio build control: this index)
- §3 → „3. Type-A pattern — GUI affordance replaced by a data path“ (type-a-limits-freshness)
- §4 → „4. Documented limits“ (type-a-limits-freshness)
- §5 → „5. Freshness/Drift at startup“ (type-a-limits-freshness)
- §6 → „6. Error ergonomics“ (this index, `### Mandatory rules`)
- §7 → „7. Reference“ (this index)

### 7. Reference

- Full Tool/Resource catalogue: `tools/list`, `resources/list`, `resources/templates/list` on the
  running server — the live surface is the only catalogue, there is no inventory document.
- Build control of the board: Tools `run_qa`, `run_make_target`, `stop_make_target`,
  `post_studio_card`; Resources `jardis://project/make-targets`, `jardis://studios`,
  `jardis://studios/{name}`, `jardis://closed-studios`, `jardis://mcp-log/{name}` (full log
  of a prior `build`/`build_process`/`build_domain`/`run_qa` call, and the findings file of a
  `build_domain` run) — see §2 "Studio build control" (workflow-studio-build-control).
- Once generated code exists and you are implementing behaviour inside it: `generated-code-extend`.
- Designing a schema's content from a domain idea instead of introspecting a live database, then
  feeding it through `import_schema`: `design-draft-schema`.
