---
name: design-model-capabilities
description: Before any interview, concept, PRD or brief for a Jardis project — what each Jardis tool (schema, aggregate, value list, query, process, closure, field map, strategic layer) delivers without hand code, what Jardis does not have, and the mandatory capability table.
zone: pre
persona: A
profile: jardis
prerequisites: [foundation-working-principles]
next: [design-headless-mcp]
---

## Scope

Capability map of the Jardis modelling tools for the AI that decides WHAT is modelled, not only for the AI that builds. It answers one question per model element: which setting of which tool already produces the behaviour? Read it BEFORE the first interview question, concept or PRD line — an interview held without it offers uninformed options ("remove an assignment via PUT" instead of the generated `Remove{Child}` command). Field-level detail of one artefact lives in the tool doors (`design-headless-mcp`); this skill is the decision layer above them.

### 1. The law

1. Whatever is built on Jardis must know and use the capabilities of EVERY Jardis tool completely, for every model element. Programming what a setting already generates is a defect, not a shortcut.
2. Order of means: declarative tools first (Process, Query, Aggregate including its schema-column settings and value lists, field map, strategic layer) → a Closure only where a rule crosses an aggregate boundary → hand code only on the dev surfaces (§2.9).
3. This knowledge is required when the interview is held and the PRD is written, not only at build time. Every option, concept line and PRD decision names the carrying capability.

Doors: every write goes through a door (MCP tool or the matching UI surface). Hand-edited definition files are not a supported entry. "MCP" columns below name the tool or resource; "UI" names the surface.

### 2. Per tool — what you get without writing code

#### 2.1 Schema (column and index settings)

Door: MCP `import_schema` (whole document; refine with `analyze_schema`, `apply_schema_recommendations`, `introspect_db`, `export_schema_sql(_files)`); UI schema import on the BC page. Single columns are not editable one by one — change the document and re-import.

| Setting | Allowed values | Effect in the generated code |
|---|---|---|
| `type` | closed vocabulary (numeric, string, bool, date/time/datetime/timestamp, json, binary, uuid, enum, …); `date`, `time`, `datetime`, `timestamp` are four distinct types | DTO and entity type; `uuid` → `Uuid` validator; `json` → `Json`; `date` → `DateTime` format `Y-m-d`, `time` → `H:i:s`; `decimal` → string field with numeric `Format` check |
| `nullable: false` | bool | create input required: `NotEmpty` (strict) for strings, `NotBlank` otherwise |
| `length` (varchar) | int | `LengthMax` validator |
| `unsigned` / `precision` (int) | bool / int | `Positive` / `RangeBetween` validator |
| `unique` (column) or unique index, single column | bool / index `type: unique` | public business key (identifier stage `uniqueColumn`): outer-door key, lookups `get{Agg}By{Key}` and `By{Key}s`, bulk read, HTTP 409 `duplicate` on a second create. Unique on a nullable column is rejected (S17) |
| `auto: created` / `auto: modified` | datetime/timestamp only, once per table, never PK/unique/FK | stamped server-side in UTC, absent from every command DTO |
| `enum` + `enumValues` | list | seeds a value list (§2.3); closed `enum` in the API contract |
| `default` | value | lands as `DefaultValue` on the entity property and snapshot; a column with a default counts as database-generated, so a NULL value is omitted from the `isNew` INSERT and the database default applies; no validator is derived; for a bound enum column it must be one of the list values (V-VL-10) |
| Column-name convention | `email`/`*_email` → `Email`; `url`/`*_url`/`website` → `Url`; `phone`/`phone_*`/`*_phone` → `Phone`; `uuid`/`*_uuid` → `Uuid`; `iban`/`*_iban` → `Iban` | validator on entity and command DTO, no code; 400 `invalid` |
| Primary key | exactly one column per table (composite PK rejected) | root PK is the internal technical handle; with no public unique key it appears as `id` |
| Foreign keys / indexes | `foreignKeys[]`, `indexes[]`; FK `onDelete`/`onUpdate` | DDL only; an FK without an index is a suggestion (S8); `{x}_id` without FK is suspected (S10) |
| Validation override | `add_schema_validation_override` | silences one warning with a reason; blockers are never overridable. Goal is zero overrides |

#### 2.2 Aggregate (designer graph)

Door: MCP `save_aggregate` (`graph.nodes`, `graph.edges`, `graph.adopts`), `add_aggregate`, `suggest_aggregate_edges`, `set_aggregate_tables`, `rename/delete/duplicate_aggregate`, `discard_aggregate_draft`; UI aggregate designer (node panel, edges, API tab for rule chain). Exactly one root entity with `erm: one`.

| Setting | Allowed values | Effect |
|---|---|---|
| Child `erm` | `one` / `many` | `many`: `Add{Singular}` and `Remove{Singular}` child commands, a child list in the create command. `one`: `Set{Child}` always, `Remove{Child}` only when the child is optional |
| `source` | table name (alias allowed) | one aggregate entity per table; the table must exist in the schema (R1) |
| `relates` (edge `sourceCol`/`targetCol`, `extraConditions[]`) | join condition between parent and child | defines the FK link; `extraConditions` carry polymorphic discriminators |
| `required` | default `true`; `false` explicit; forbidden on root | `many` + required: create requires a non-empty list (400 via `CountMin`) and removing the last entry is refused; `one` + required: create requires the child (`NotBlank`) and no `Remove{Child}` is generated for a plain required child; `false`: optional child, `Remove` available |
| `adopt.onCreate` | `'"const"'`, `'"__UUID7__"'`, `'"__UUID4__"'`, `<entity>.id`, `<entity>.field` | column filled once at create, removed from the command DTO. `<entity>.id` = FK from an ancestor; UUID varchar needs length ≥ 36 |
| `adopt.sync` | `<entity>.field` | column re-filled on every persist (field inheritance) |
| `depend` | `<column>: <entity>.id` (target = child or sibling, not `many`; no cycle, no both directions) | persist cascade: the target is persisted first and delivers the generated id; FK removed from the DTO. Not combinable with `adopt` on the same column |
| `identifier` | `{stage: column\|uniqueColumn\|pk, column}` | declares the public identifier explicitly (otherwise guessed by a 3-stage cascade; ID-2 suggestion). Child `many` needs a dedicated unique identifier column, else PK fallback (R7) |
| `eventIdentifier` | `{kind: pureJoin\|businessKey\|dependTarget\|syntheticPk}` | identifier in the event payload; undeclared → EVT-ID-3 suggestion. MCP only via `write_definitions` (no UI) |
| `orderBy` (node) | column + `ASC`/`DESC` | copied into the derived follow-up query of that child and orders it; an effect on the aggregate load path is not verified |
| `field` (node) | column list | column restriction; MCP only via `write_definitions` (no UI) |
| `validationOverrides` (aggregate) | `{rule, target, reason}` | silences warnings of this file; MCP only via `write_definitions` |
| Root remove | implicit | cascades through all children, FK-safe, in one transaction |
| Nesting | `many` under `many` at most 2 deep; multi-parent allowed when the same table backs the repeated entity | deeper nesting is rejected (A11) |
| Aggregate size | more than 6 distinct tables | hint only (A18) — a signal to split |

Command forms the tree yields (all derived, no JSON of their own):

| Command | Fields | Notes |
|---|---|---|
| `Create{Root}` | scalars (not system-managed, not adopted, not FK to owned child), grouped VOs, `one` children, `many` lists (required lists before optional) | never exposable at the BC facade (name clash with `{agg}()`) |
| `Update{Root}` | root identifier + root scalars only | no child lists, no `one` children — children change through their own commands |
| `Set{Child}` / `Add{Singular}` | parent selector + child scalars | no nested `many` collections; clashing names are prefixed with the entity name, never dropped |
| `Remove{Singular}` (`many`) | parent selector + child identifier | cascade property is the child identifier; `Remove{Child}` of an `one` child needs no fields |
| `Remove{Root}` | root identifier | cascade, see above |

Selector rule: when the path crosses a `many` ancestor, the command carries ONE selector `{entity}Identifier` (string) or `{entity}Id` (int) for the last crossing.

Response (every command): flat `data` with `@type` = aggregate key plus the root business identifier and the references of the touched children — never objects. Errors: 400 `{"@type":"validation","fields":[{field,reason,message}]}` (`field` = outer name, list index omitted), 409 `{"@type":"concurrencyConflict",…}` or `duplicate`, 422 `{"@type":"ruleViolation","rule","messageKey","context"}`. A process calling a command answers 200.

Outer door (BC facade): `{agg}(): {Agg}Read` (lookups by id/ids/unique key, lists) and `process()`; the write facade is family-internal. Cross-BC writes go through the foreign `process()`, cross-BC reads through the foreign `{Agg}Read`.

#### 2.3 Value lists

Door: MCP `save_value_lists`, `validate_value_lists`, `preview_value_lists`, `adopt_value_list_values`, `rename/delete/duplicate_value_list`; UI value-list editor (create modal, Werte tab). One entry per `enum` column, seeded by the schema import (`origin: schema`).

| Setting | Allowed values | Effect |
|---|---|---|
| `name` | PascalCase, unique case-insensitively | PHP enum `{BC}/Data/{Name}.php` |
| `values[]` | non-empty, no duplicates, no two values with the same case identifier | enum cases; order is the list order |
| `boundTo {table, column}` | an existing `enum` column, at most one list per column | `ContainOneOf` validator at entity and command (400 `invalid` on the field path, also for child fields); closed `enum` in the OpenAPI request and response |
| values vs. schema `enumValues` | should match in set and order | otherwise warning V-VL-8; `adopt_value_list_values` takes over the schema values |

A list can be a Closure input of kind `valuelist` (own BC only).

#### 2.4 Queries

Door: MCP `save_queries`, `validate_queries`, `preview_queries`, `probe_query`, `save_query_layout`, `rename/delete/duplicate_query`; resources `queries`, `queries/catalog`; UI queries surface. BC-wide `Queries.json`. Every query is a paginated list; the answer is always `{items, total, limit, offset}`.

| Setting | Allowed values | Effect |
|---|---|---|
| Base list `{agg}List` | seeded once per aggregate on first save, editable afterwards | the list every aggregate has; rules and process nodes read counts and keys through it |
| `root` | an entity of the own BC; a `public` query must root on the aggregate root | owning aggregate |
| `visibility` | `internal` (default) / `public` | `public` becomes a read method on `{Agg}Read` and an API route; needs `limit` |
| `limit` | int, mandatory | cap on the page size (above 500 warns); paging continues |
| `joins[]` | `entity` (own BC, also across aggregates), `as`, `type` (`inner` `left` `right` `full` `cross`, no default), `on` (optional inside the root aggregate, mandatory beyond) | joined fields usable in `where` as `alias.field`; list order matters; `full` fails on MySQL and SQLite |
| `parameters[]` | `name`, `type` (`string` `int` `float` `bool` `date` `list`), `optional`, `default` | bound filter arguments; optional → nullable argument; names `limit`/`offset` are reserved |
| `where` | tree of `all:` (AND) / `any:` (OR); leaf `field` + `op` + `value` | fields, never columns (the field map translates) |
| `op` | `equals` `notEquals` `greater` `greaterEquals` `lower` `lowerEquals` `like` `notLike` `between` `notBetween` `in` `notIn` `isNull` `isNotNull` | `contains`, `length`, `exists`, `notExists` are rejected (V-QDEF-7); text search is `like` |
| `value` | `{param}`, `{const}`, `{from,to}`; none for `isNull`/`isNotNull` | a leaf bound to an optional parameter is skipped when the argument is absent |
| `orderBy[]` | `field`, `direction` | the root PK is appended as tie-breaker automatically |
| `total` | count of matching rows ignoring paging | counts JOIN rows: a join to a `many` child can count one root several times — check with `probe_query` before relying on it |

#### 2.5 Process

Door: MCP `create_process`, `save_process` (whole process wire), `validate_process`, `update_process_metadata`, `build_process`, `regenerate_process_node`, `rename/delete/duplicate_process`; resources `processes/…`, `process-library`, `process-edge-status-catalog`; UI process designer. A process is the outer door of a write: one process = one endpoint.

| Setting | Allowed values | Effect |
|---|---|---|
| `kind` / `visibility` / `runInTransaction` / `subprocessOnly` | `command`\|`query` / `public`\|`internal` / bool / bool | endpoint path; OpenAPI presence; transaction bracket; `subprocessOnly` = no facade method, only a sub-process target |
| `input.fields[]` | scalar (`string` `int` `float` `bool` `array` `mixed`, `default`, `nullable`) or command field `accepts: ["Agg.Command", …]` (+ `list`) | request contract as a discriminated union over the accepted commands; accepted commands can be root commands AND child commands (`Contact.AddContactOrganization`) |
| Node = action | `consumedCalls[{facade, method}]` on the aggregate facade (command or read) | calls the generated command, no body to write; `dtoShape`, `outputShape` declare shapes |
| Node = rule | `rule: <Closure name>` + `ruleSubject.payloadField` | calls a catalog closure inside the flow; must not also be bound at the endpoint chain (double-bind blocker) |
| Node = sub-process | `subProcess: <process>` in the same BC, not itself | synchronous delegation |
| Node = cross-BC call | `crossBcCall: <service name>`, target `consumedCalls[0]` in ANOTHER BC; writes only via the foreign `process()` | service stub + DTO translation |
| Node = external call | `externalCall {service, level: process\|bc\|domain}` | call outside the workspace via a service stub |
| Node = event | `mode: async` + `eventFields[]` (`source` = input field, or collector `{kind: latest\|all, node, facade, method}`; `label`) | event record built from named fields; transport recipes in `generated-code-recipes` |
| `mode` | `sync`\|`async`, mandatory per node | |
| `edges[]` | `from`, `on`, `to` or `terminal: true`; `on` ∈ `onSuccess` `onFail` `onTimeout` `onSkip` `onCancel` `onExit` | routing; a node with `consumedCalls`/`rule`/`subProcess` needs an `onFail` edge. Success → `onSuccess`, 404 and 422 → `onFail`, 5xx → exception path |
| `responses[]` | per dead-end edge `{when:{from,on}, status, messageKey}`; status ∈ 400 401 403 404 405 409 422 500; `messageKey` = `{bc}.{cause}`, unique in the process | declared error mapping in OpenAPI and interface board; mandatory on dead-end edges except after rule nodes (fixed 422) — missing is warning RESP-1 |
| `reads[]` (action node) | `{query, needs: statusFilter\|dateInterval\|identifierChain, binds}` | declared read of an own-aggregate query |
| `description`, node `purpose`, `acceptanceCriteria[]` | text | docblocks of the facade method and node classes |

Cycles are allowed only with an exit; unreachable nodes, dangling edges and a missing or unknown `start` are rejected by validation.

#### 2.6 Closures (rules, values, sets)

Door: MCP `save_closures`, `validate_closures`, `rename/delete/duplicate_closure`; resources `closures/…`, `closures/{d}/{s}/{bc}/{name}/work`; UI closure editor (created from the model list "New" menu, chain on the aggregate API tab). Use only when the table in §4 says "not available declaratively".

| Setting | Allowed values | Effect |
|---|---|---|
| `catalog.<Name>` | PascalCase | class `{BC}/Closure/{Name}.php` — body is dev code, stub is generated |
| `input[]` | 0..n of `{name, type:{kind: command\|aggregate\|scalar\|valuelist, name, optional?, list?}}`; names refer to the OWN BC | typed arguments of the stub |
| `output` | `verdict` or a scalar type | a verdict is accept/reject; any other output is a computed value |
| `messageKey`, `policyRef` | `{bc}.{cause}`; id of a steckbrief business policy | verdict only (`messageKey`); `policyRef` on any kind; shown by the governance coverage |
| `reads[]` | `{query, needs, binds}` on queries of the own BC | declares what the body reads (M9: own BC only) |
| `compose: "all"` + `uses[]` | names of catalog entries of the same BC, no set inside a set, no cycle | a rule set; `uses` without `compose` is a free sub-closure reference |
| `examples[]` | `{name, given, reads?, uses?, expect}` | generated test; missing on a non-set closure is a warning, a set carries none |
| `bindings.<Command>.chain[]` | ordered closure names, no duplicates after flattening; output `verdict`; at most one `command` input (that command), at most one `aggregate` input (not on Create); no scalar/valuelist input | AND chain with short circuit, enforced structurally at the endpoint |
| `bindings.<Command>.expose` | bool; any command except Create, including child commands | direct method `{command}({Cmd}DTO)` on the BC facade |

Rejection is `422 {"@type":"ruleViolation", rule, messageKey, context}`, never an exception. Read pattern inside a body: `$this->context(Get{Agg}ListHandler::class, new {Agg}ListFilter(limit: 1, …))()['total']`. Known limit: no locking between check and apply — back hard invariants with a database constraint.

#### 2.7 Field map

Door: MCP `save_naming`, resource `naming/{d}/{s}/{bc}`; UI mapping tab. Sparse overrides `table → column → businessName` (valid PHP identifier, camelCase, unique per table); everything else is derived. The only way to rename a property; plural exceptions are not declarable.

#### 2.8 Strategic layer

| Element | Door (MCP / UI) | What it gives |
|---|---|---|
| Domain, subdomain, BC manifests | `create_domain`, `update_domain_manifest`, `create_subdomain`, `update_subdomain_manifest`, `add_bc`, `rename_*`, `delete_*` / domain map, master data | names (PascalCase, BC names unique per domain), classification core/supporting/generic, owner, output directory (`update_project_settings`), client block |
| Planned BC | `create/update/delete_planned_bc`, `promote_bc` / BC page | a BC drafted before it exists (steckbrief, glossary terms); promote turns it into a real BC |
| Steckbrief | `save_steckbrief` / BC page card | description, business model, Wardley evolution, business policies (ids referenced by closures), assumptions, owner |
| Glossary | `save_glossary` / BC page card | ubiquitous language: term, description, code identifier |
| Naming | `save_naming` | §2.7 |
| Context map | `create/update/delete/rewire_context_map_edge`, `create/update/delete_context_map_external_node`, `save_context_map_*`, resource `context-map/{d}/drift` / domain map | edges with the 8 patterns (partnership, shared kernel, customer-supplier, conformist, anticorruption layer, open host service, published language, separate ways); direction required for directed patterns; drift check declared vs. real coupling (5 alarm kinds); external systems with service names |

#### 2.9 Tooling surfaces and hand-code surfaces

| Surface | Door | Use |
|---|---|---|
| Project and stack | `provision_project`, `set_stack_selection`, `save_stack_settings`, `save_runtime_env`, `repair_stack_drift`, `run_stack_command`, `update_project_settings`, `configure_logging`, connections (`create/update/delete_connection`, `introspect_db`), sources (`create/rename/delete_source`) | provision from the app template and run the stack by tool, not by hand-made compose files or env edits |
| Build and checks | `validate_definitions_all`, `validate_*`, `build`, `build_domain`, `build_process`, `run_qa`, `run_make_target`, `stop_make_target`; resources `build-result`, `findings/{domain}`, `project/make-targets` | validate before building; count warnings and suggestions after; goal 0 |
| Reading | resources `catalog/{d}/{s}/{bc}`, `api/{domain}/routes`, `api/{domain}/openapi`, `code-tree`, `code-file`, `code-graph`, `interface-board`, `doors/{domain}` | contract and routes for tests and frontend; `catalog` lists every command name including child commands |
| Studios | resource `studios/{name}`, tool `post_studio_card` | the AI posts a decision card; answering is the human's handle |
| Human-only handles (no MCP door) | licence actions, studio create/remove/pause/answer, project create/restart, new domain, chat | ask the human |
| Dev surfaces (hand code) | closure bodies `{BC}/Closure/`; process node bodies; `Query/`, `Repository/`, `Service/` under `{BC}/Process/{Name}/`; versioned variants `v{N}/` | the only places for written code; never inside `{BC}/Model/{Agg}/` (rebuilt on every build). Reusable building blocks: `packages-find-existing` |

### 3. What Jardis does NOT have — do not plan for it

| Missing | Do this instead |
|---|---|
| Minimum/maximum size of a child list (only "at least 1" via `required`) | `required: true` for ≥1; any other bound is a closure or a database constraint |
| Uniqueness within a parent (e.g. one role per contact and organization) | a database unique index (409 `duplicate`) or a closure |
| A field that references another aggregate | store the foreign business key in a plain column; check existence with a closure that reads the other aggregate's list |
| Composite unique key as public key | one single-column unique key; a composite index stays DDL |
| Per-field regex or read-only flag in `Aggregate.json` | column convention, value list, length/type; otherwise a closure |
| Child ordering as data (position, move up/down) | `orderBy` on the derived follow-up query only |
| Configurable cascade (restrict/null on root remove) | the root remove always cascades; FK `onDelete` is DDL only; a guard is a closure on `Remove{Root}` |
| Role pairs / inverse roles in value lists | one list; the inverse label is a frontend label |
| Locking between rule check and command apply | database constraint for hard invariants |
| A second target language | PHP only |

### 4. Mandatory artefact form

Before an interview option is offered, a concept line written or a brief sent, the artefact carries this table for every model element. A closure or hand code may appear only after the declarative row says "not available".

| Model element | Jardis capability (tool · setting) | Used / deliberately not used (reason) |
|---|---|---|

Example (Contact / Organization):

| Model element | Jardis capability | Used / not used |
|---|---|---|
| E-mail and phone of an organization | schema column convention `email`, `phone` → `Email`, `Phone` validators | used — no code |
| Role of a contact at an organization | value list `ContactOrganizationRole` bound to the child column → `ContainOneOf`, 400 `invalid`, closed OpenAPI enum | used — no code |
| Assign / remove a contact ↔ organization | aggregate: child `erm: many` `contactOrganization` → `AddContactOrganization` / `RemoveContactOrganization`, exposed as two processes (`accepts: ["Contact.AddContactOrganization"]`) | used — not "remove by PUT" |
| Contact list filtered by organization | query `contactList`: left join on the child + optional parameter `organizationNumber` | used; check `total` with `probe_query` |
| Organization must exist when assigned | closure `OrganizationExists` (reads `organizationList`, `limit: 1`, `total > 0`), wrapper bound to `Contact`/`AddContactOrganization` chain | closure — not available declaratively (no foreign-aggregate reference field) |
| Organization with contacts must not be deleted | closure `OrganizationHasNoContacts` bound to `RemoveOrganization` | closure — not available declaratively (no configurable cascade/restrict) |

Rows without a Jardis capability name are not allowed. "Deliberately not used" needs a reason that survives a reviewer.

### 5. Reading order before the first interview

1. This skill — the capability decision layer.
2. Resource `jardis://catalog/{domain}/{subdomain}/{bc}` once the aggregates exist — exact command names including child commands, read BEFORE `save_process` and `save_closures` (no guessing binding keys).
3. `design-headless-mcp` — tool and resource calls, ordering, error envelopes.
4. `generated-code-extend` and `generated-code-recipes` once code exists.

### 6. Reference

- Origin of the law: "Es kann nicht sein, dass alles mit Closure erschlagen wird." and "alle Werkzeuge, die jardis hat, nicht nur die von mir genannten" (Rolf, 2026-10-08, Grundgesetz der Entwicklung mit Jardis); Reihenfolge der Mittel "Prozess, Query, Aggregate, Closure".
- Canonical sources in the Builder repository: `.claude/spec/FAEHIGKEITSKATALOG.md`, `.claude/spec/COMMAND-API-FORMEN.md`, `.claude/spec/QUERIES-JSON.md`, `.claude/spec/CLOSURES-JSON.md`, `.claude/spec/VALUELISTS-JSON.md`.
- Absence of per-field regex/read-only flag and configurable cascade in `Aggregate.json`: not in the capability catalog and not among the reserved keys of the aggregate definition (`internal/definition/data.go`, reserved-keys list).
- Tool doors: `design-headless-mcp`. Process skills that require the table: `process-concept`, `process-write-prd`.
