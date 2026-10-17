---
name: design-headless-mcp
description: Driving a Jardis workspace headless through `jardis mcp` — Tools as actions vs Resources as read-only, the Workspace to Schema to Aggregate to Process to Build to Code-read workflow, the strategic-design surface (glossary, Steckbrief, planned BCs, Context-Map edges with the eight canonical DDD patterns, and the read-only drift check declared-vs-real coupling), the Type-A GUI-replacement pattern (output directory via update_project_settings, code via code-file/code-tree resources, a new workspace means a new process), documented workspace-registry limits, computed columns (`computed` on `save_aggregate`), process input types from the backend (`inputTypes`, `valueList`), the transaction default with its opt-out warning (`runInTransaction`, `V-PROC-TX-OPTOUT`), declared class `versions` (`update_domain_manifest`, resource `jardis://domain/{domain}/manifest`), blueprint import (`import_blueprint`, resource `jardis://blueprints`, its 404/412/409 refusals), and structured error envelopes (confirm flags, BUILD_RUNNING/DRAFT_EXISTS). Use when an AI must design, build, or inspect a Jardis domain without a browser.
zone: post-active
persona: C
profile: jardis
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

Build-control surface of the board (studios = the project's undertakings under
`docs/vorhaben`), see §2 "Studio build control":

- `run_qa` (Tool) — runs one allow-listed QA command (`make qa`, `make phpunit`, `make phpstan`,
  `make phpcs`, `make integration-test`) in the project root; returns exit code, a bounded tail
  and a `logResourceUri` for the full output.
- `run_make_target` (Tool) — runs any target the project Makefile currently offers; read the
  `project-make-targets` Resource (`jardis://project/make-targets`) first for the live list and
  each target's kind.
- `post_studio_card` (Tool) — puts ONE decision card (question, recommendation, answers) into
  the current room of a studio; answering it is the human's gate, no tool.
- `jardis://studios/{name}` (Resource template) — one studio: its record, rooms, documents
  and the cards with their state; `jardis://studios` lists all open studios.
- `jardis://closed-studios` (Resource) — the closed and discarded studios, newest first;
  read-only.

The full catalogue (on the order of 80 tools plus 70+ resources/templates — do not hardcode them
from memory, they grow with every strategic-design increment) is a lived artefact, not something to memorise here — consult it before
guessing a name (see Reference).

### 2. End-to-end workflow

One walk from an empty workspace to readable generated code. Each step is a Tool unless noted:

1. **Workspace** — point the running `jardis mcp` process at a project root (passed at process
   start). If the domain structure does not exist yet, build it up with the structural tools
   (create a domain, add a bounded context, add an aggregate) before importing schema.
   The domain's **class versions** the HTTP layer may serve (`X-Jardis-Version: v2`) are declared
   with `update_domain_manifest` field `versions` (`["v2", …]`, each `^v[1-9][0-9]*$`, whole-list
   overwrite, `[]` clears) and read back through the resource `jardis://domain/{domain}/manifest`;
   a build writes them to `src/Api/{Domain}/versions.php` and into the OpenAPI contract
   (`generated-code-versioning` §3). The build warns `V-VERSION-DRIFT` when a declared version has
   no `v{N}/` class or a `v{N}/` directory is not declared.
   **Blueprint import — a ready-made bounded context instead of `add_bc` plus hand-modelling.**
   Read the static resource `jardis://blueprints` first (readable without a license): a list of
   `{name, description, category, variants[]}`. `import_blueprint {domain, subdomain, bc, blueprint,
   variant}` (all five required; a mutating tool, blocked without a license) writes one variant as a
   NEW bounded context into an existing domain and subdomain: schema, aggregates, queries, closures
   and processes, plus the hand-written bodies, which go into the BC's folder under the configured
   `outputDir`. Every reference to the blueprint's own domain and BC name is rewritten to yours;
   `bc` is PascalCase-normalized. The call is atomic - any failure leaves workspace and `outputDir`
   untouched. Answer: `{bcPath, outputPath, artifacts[], bodies[], rewritten[], mentions[]}`;
   `mentions[]` lists free-text occurrences of a source name that were NOT rewritten (reported,
   never changed silently). Refusals: **404** (domain, subdomain, blueprint or variant unknown) ·
   **412** `DOMAIN_NOT_CONFIGURED` (no `outputDir`: set it with `update_project_settings` first) ·
   **409** `CONFLICT` (the BC name exists in this or another subdomain of the domain, or its output
   folder exists - **here `CONFLICT` does NOT mean "already imported, treat as done"**: nothing was
   written; choose another `bc`, never retry unchanged) · **409** `CONFLICT` with
   `details.collisions[]` `{kind: table|aggregate, name, subdomain, bc}` (a table or aggregate name
   of the blueprint already exists in another BC of the domain; the list names each). Afterwards the
   imported BC is a normal BC with no marker and no protection: change it with the usual tools
   (`save_aggregate`, `save_process`, `save_closures`, ...) and `build` as for any BC. The import is
   a first import only (no re-import) and declares no Context-Map edge.
2. **`import_schema`** — write the bounded context's schema, either from a database
   introspection or from a schema drafted for a domain idea (see `design-draft-schema`), whose
   JSON you pass in the `yaml` argument (wire-key kept for contract stability — it carries JSON
   content). This Tool **is** the authoring door: a `Schema.json` is never placed into the
   workspace as a hand-written file. It normalises `tables` the same way `analyze_schema` and the
   UI do (columns, indexes, foreign keys per table; a column-level `unique` becomes an index)
   and, when `reportMd` is omitted, writes `SchemaReport.md` from that analysis. `analyze_schema`
   answers lean: the enriched `schema` once plus `reportMd`; the hint-free persistable form for this
   `yaml` argument comes back in `yaml` only with `persistable: true` (the enriched `schema` still
   carries frontend hints and is not an import input). `apply_schema_recommendations` answers with
   `schema` only — already hint-free, so its JSON-serialised form is directly the `yaml` argument.
3. **`save_aggregate`** — persist the aggregate's designer graph (entities, relations, keys).
   `graph.adopts` (ADOPT columns per node): omit or `null` = the stored adopts of every node stay, `[]` = delete them all, a sent list REPLACES the stored adopts of ALL nodes (a node missing from the list loses its adopts). The result carries the blockers of the aggregate as stored: `valid` (false = at least one blocking finding), `errors`, `warnings`, `suggestions` — the aggregate is written even with blockers, so the caller must check `valid`; a parked draft (`draft=true`) carries no `valid`/`errors`, and `notValidated` (with the reason) replaces them when the stored artefacts cannot be loaded. `set_aggregate_tables` answers with `valid`/`errors` (or `notValidated`) the same way, written either way; `write_definitions` answers with `valid`/`errors`/`warnings` or `notValidated`, an aggregate with a blocker is still written (a schema with a blocker is refused 422 and nothing is written).
   Returns a mtime `CONFLICT` if the on-disk graph moved under you — reload and retry, or pass
   the force flag once you have confirmed the overwrite is intended. A node's optional `identifier {stage, column}` declares its public Identifier (omit = keep the stored declaration, `{}` = delete it after the merge, invalid = 422 `VALIDATION_FAILED` before anything is written). Node positions and the
   viewport are optional: when absent, the value in `Layout.json` is kept. A node's optional
   `path` (as returned by the aggregate load) is accepted, so a table placed at more than one
   position survives load then save. `suggest_aggregate_edges` (read-only) proposes edges, node
   positions and `rootCandidates` for the chosen tables; a root recognisable only through declared
   FKs (no name-prefix hit, e.g. an irregular plural) is still returned, with reason `declared_fk`,
   when it is the unique FK sink of the tables (chain A←B←C yields A) — two sinks or no FK signal
   leave `rootCandidates` empty, so decide the root yourself then. A node's optional `computed`
   (`["net_total", …]`, omit = keep the stored marker, `[]` = delete it) marks the columns the
   **process** derives (sums, counters): they leave every Create/Set/Add/Update command and the
   OpenAPI request (a body naming one is `400 unknown`), Read/list/Akte keep them, and the process
   writes them through the family-internal `Set{Entity}Computed` command. A computed column must be
   nullable or carry a DB default (`V-CALC-1`) and cannot be the PK, the public identifier, an FK or
   an `adopt` column (`V-CALC-4`); the aggregate load echoes `computedBlocked` (column → reason) for
   the columns that may not carry the marker — send it back or omit it.
   **Format markers on a node** (neutral example: table `orders`, key `order_no` unique per region):
   ```json
   {"id": "n1", "table": "orders", "entityKey": "order",
    "identifier": {"stage": "uniqueColumn", "column": "order_no", "scope": ["region_code"]},
    "requestAttribute": {"region_code": "tenant", "created_by": "subject"},
    "writeOnly": ["api_token_hash"]}
   ```
   (An aggregate without any outer door is set on the graph: `save_aggregate` `graph.visibility` = `"internal"` (omit = keep, `"public"` = default) or `rename_aggregate` `visibility`; see below.)
   - `requestAttribute` (ROOT node only; omit = keep, `{}` = delete): the column's value comes from the authenticated request (`tenant` / `subject`), never from the client. It leaves every input contract and is set at every write door; only a `tenant` column also filters the read doors, a `subject` column never filters a read. Columns must be varchar/char data columns (`REQATTR-1`) and the aggregate needs a public unique key (`REQATTR-3`).
   - `identifier.scope` (lists `tenant` columns, `REQATTR-2`): the key is unique per scope value; the schema needs ONE unique index over exactly scope + key column, scope columns NOT NULL (`ID-1`, blocker). A PUBLIC query on a scoped root binds each `tenant` column by a top-level `where.all` equality (`REQATTR-4`, `validate_queries`).
   - `writeOnly` (omit = keep, `[]` = delete): written, never read from outside — absent from Akte, public list, bulk read, events and the OpenAPI response, present in the write contract. Only a declared query with effective visibility `internal` projects it (family-internal read, e.g. verifying a stored digest). A public query may not filter or order on it (`WO-2`); not the PK, key, FK, adopt, computed or request-attribute column (`WO-1`).
   - `graph.visibility` (`public` default / `internal`): `internal` removes EVERY outer form of the aggregate — no `{agg}()`/`{Agg}Read` on the BC facade, no exposed command, no route, no OpenAPI path; reads and writes stay possible family-internally through the kernel seam and through a process of the BC. Blockers: another value (`V-AGG-VIS-1`), a `public` query on it (`-2`, switching the aggregate never rewrites `Queries.json`: set those queries `internal` or the aggregate public), `expose: true` on its command (`-3`), a foreign BC's `consumedCalls` into it (`-4`); warning `V-AGG-VIS-5` when a process answers with its reference (nobody outside can reload it). Cross-BC access goes through a public aggregate's `{Agg}Read` or the target BC's `process()`.
   - Blockers come back in `valid` / `errors` of the save answer; check them (the aggregate is written regardless).
   - The request attributes are `RequestAttribute::SUBJECT` (`jardisSubject`) and `::TENANT` (`jardisTenant`) of `jardissupport/contracts` >= 2.8.0; the authenticating middleware of the app sets them. A door whose required attribute is absent answers 403 `request.attributeMissing` (`context.attribute` = name) from the per-route middleware `RequireRequestAttribute` (`jardiscore/app` >= 1.8) before the handler — never 500; the OpenAPI contract names it (`oneOf` next to a declared 403 of the process).
   - A process input field takes the same marker: `{"name": "regionCode", "type": "string", "requestAttribute": "tenant"}` in `input.fields[]` of `save_process` (scalar string, no `valueList`/`default`, at most one field per attribute: `V-INPUT-REQATTR`); a Rule node on a tenant-scoped root needs a `tenant` field as scope source (`V-PROC-SCOPE-SOURCE`).
4. **`save_naming`** — apply/confirm the field-mapping naming conventions for the bounded
   context before building.
5. **`create_process`** / **`save_process`** / **`validate_process`** — model a BC-level
   process graph (if the change is process-level behaviour rather than aggregate structure),
   iterate, and check it for structural findings before building. On `save_process`, omitting
   `layoutJson` keeps the stored layout; passing `null` deletes it. The process **input types**
   come from the backend, not from a list you remember: the process load answer / resource
   `jardis://process/…` carries `inputTypes` (the scalar vocabulary of the aggregate door —
   `date`, `datetime`, `time`, `decimal`, `uuid`, … plus `string|array|mixed`), and an input field
   may reference a value list of the BC as `valueList: <Name>` instead of `type`
   (`input.fields[].type` / `valueList`; an unknown list is the blocker `V-INPUT-VALUELIST`, a field typed
   `array`/`mixed` warns `V-INPUT-UNTYPED`). (FIX3) A field can also be a list (`list: true` with the element `type`; no minimum length, `[]`
   is valid) or an object (`fields: [...]`, members scalar or object, at most two object levels;
   `fields` + `list: true` = object list) — `save_process` refuses a third object level, an empty
   object, `default` on a list/object, `accepts` inside an object and any key that contradicts the
   kind, e.g. `fields` on a scalar (V-INPUT-OBJECT-DEPTH, V-INPUT-OBJECT-EMPTY, V-INPUT-SHAPE); a
   wrong element answers 400 with its index path, never 500. **Transaction:** a process runs in one transaction by
   default — the key `runInTransaction` is absent in a normal process, the load answer / resource
   reports the effective value as `runsInTransaction`, and a 4xx end status rolls the run back
   (events only after the commit). `update_process_metadata` writes `runInTransaction` only when
   you pass it; `false` is the explicit opt-out and draws the warning `V-PROC-TX-OPTOUT`
   (`validate_process`, finding source `process`). A node whose `consumedCalls` resolve into a
   foreign BC needs no `crossBcCall`: it is a Cross-BC-Call node by itself (Service `{Process}{Node}`
   derived).
   **Success answer (`successOutput`).** A process answers by default what it derives from the node it
   ends on. To name the 200 `data` yourself, put the key `successOutput` on the process wire of
   `save_process` (top level of `process`, next to `input` / `nodes`); no Designer editor, a save is a
   full replace, so carry the value you loaded — omitting the key means "derived" again. Optional
   `{fields: [...]}` (blocker `V-PROC-SUCCESSOUT` for an empty block, a duplicate field name, the name `@type`, an unknown type; `validate_process` reports it):
   - `"successOutput": {"fields": [{"name": "total", "type": "int"}, {"name": "region", "type": "varchar"}, {"name": "note", "type": "varchar", "nullable": true}]}` —
     `type` from the column-type vocabulary of `Schema.json`; the process answers `@type` (the
     lcfirst process name) plus exactly these fields from the `data` of its end node, a missing or
     mistyped non-nullable field is a 500 with rollback.
   Needs a success exit that carries a payload: an event or Rule node at the end with a non-nullable
   field is the blocker. Details and OpenAPI effect: `generated-code-recipes` Recipe 6.
6. **`build`** — a long-running Tool: generates the aggregate (and/or process) code tree onto
   disk. Reports progress notifications; a concurrent build on the same scope refuses with
   `BUILD_RUNNING`, an unsaved designer draft with `DRAFT_EXISTS` (§6). `build_domain` builds a
   whole domain in one call, in build order: aggregates before the processes of the same BC and
   producer BCs before their consumers (edges from `consumedCalls`; ties keep the alphabetical scan
   order; on a mutual-call cycle the order breaks at its first member without an error and the
   second side stays `not-checkable`). A consumer built before its producer would report
   `not-checkable` for the cross-BC call, so do not work around it with a hand-ordered BC loop.
7. **Read the generated code** — via the `code-tree` and `code-file` Resource templates
   (chunked reads with an offset/limit window; large files stay well inside the response cap).
   This replaces "open it in the editor" from the browser flow.

**Studio build control.** When a studio (Vorhaben) of the board drives the build,
the AI reports to the human through cards, not chat:

- **Cards you post** (`post_studio_card` with `studio`): kinds `question`, `acceptance`,
  `release`, `addendum`, `sight` (a surface to look at), `red` (a check stays red after the one
  fix run), `merge` (a stage is green and committed) and `delivery`. A card needs a
  recommendation `{lead, reason}` and two to three answers `{id, label}` with `recommended`;
  without them the call is refused and the question goes as ordinary chat. Keep the `STOPP:`
  line of the progress file as the trace and remove it once the answer is recorded. At most one
  card is open per studio, more queue behind it. Without `studio` only the kinds `start`
  and `notice` exist (they belong to the board); `conflict` is Jardis's own and refused.
- **Fixed answer ids** (Jardis sets answers and labels; answers you pass are discarded, you only
  give `recommended` as one of the fixed ids): acceptance card in the room Acceptance
  `approve` / `change`; `delivery` `pullRequest` / `push`; `red` `tryAgain` / `defer` /
  `discard`. The acceptance card is refused until every criterion is met or deferred, every open
  point is triaged and the knowledge lines are complete.
- **Human gates:** you never merge, push or answer a card. The human decides on the `merge`
  card and Jardis merges; on the `delivery` card Jardis pushes or opens the pull request.
  Creating a studio (`start` card), the answers, discarding and deleting are the human's
  handles with no MCP tool. Read card state in the `cards` list of `jardis://studios/{name}`.
- **`run_qa` preconditions:** Docker available, the target present in the project's `make help`,
  and for `make integration-test` the stack running. A missing one is a 409 with
  `details.code` `dockerMissing`, `stackDown` or `targetMissing`; while a studio builds,
  Jardis then puts the card "QA nicht ausführbar" in front of the human. `run_qa` is
  long-running and cancellable; read the full output through its `logResourceUri`.
- **`run_make_target`:** the live allow-list is `jardis://project/make-targets`; kinds `plain`,
  `copyOnly` (refused, copy the command), `stoppable` (until `stop_make_target`), `param`
  (`params` with exactly the declared names), `confirm` (needs `confirm=true`); a target's
  `stackRequirement` can refuse with `STACK_STATE_MISMATCH`; one target per project root at a
  time (`CONFLICT` otherwise).

Any step can be re-run idempotently against its own Resource first (e.g. read `jardis://tree`
or the aggregate Resource before `save_aggregate`) to confirm the current state before writing.

**Strategic-design tools (optional, additive to the workflow above):** `save_glossary` (a BC's
Ubiquitous-Language glossary), `save_steckbrief` (a BC's canvas — purpose, classification
read-through, collaborators), `create_planned_bc` / `update_planned_bc` / `delete_planned_bc`
(a not-yet-built BC's canvas, at Domain/Subdomain level) and `promote_bc` (turn a planned BC
into a real one). None of these are required to generate code — they capture organisational
metadata (subdomain classification, terminology, context boundaries) a human or AI can set
independently of the Schema→Aggregate→Process→Build chain. Full field shapes: the tools' JSON input schemas (`tools/list`).
All save tools answer lean: `save_closures`, `save_queries`, `save_glossary`, `save_steckbrief`,
`save_value_lists` and `create_planned_bc` / `update_planned_bc` return findings/status and the new
`newMtime` — the saved content (terms, Canvas fields, catalog entry, planned-BC fields) comes back
only with `echo: true`; otherwise read it through the matching Resource (`glossary`, `steckbrief` —
also for a planned BC —, `valuelists`, `queries`, `closures`). The HTTP/UI answer stays full.

**BC-declared ENV keys (`save_bc_env`).** A BC whose body reads its own setting declares the key in `BC.json` `env[]` with `save_bc_env` (`env` = the complete list, full replace, `[]` clears): `key` (UPPER_SNAKE), optional closed `values`, optional `default` (one of `values`), `description`. The value lives in the ONE project `.env`, dynamic block `domain`: write it with `save_runtime_env` (block `domain`, key-addressed: line `-1` + `key`); read the block with `jardis://runtime/env` (a declared key without a value is `present: false`). The body reads `$this->resource()->env('<KEY>')`. Blockers, nothing written: `V-RT-10` key form, declared once, no platform key (runtime catalogue, `DB_READER{n}_*`, `APP_SECRET_KEY`, any `*_FILE`) and no key another BC declares; `V-RT-11` no `values`/`default` entry that dotenv would load as non-string (number, bool word incl. empty, `[...]`, `{...}`), interpolate (`${X}`), expand (leading `~`) or trim — use words like `net`/`gross`; `V-RT-12` the same for the written value, plus membership in a closed `values` set; `V-RT-13` a value is ONE line (no CR/LF/VT/FF/NUL), for every block and tool. An undeclared key of the block stays `V-RT-7`. A blueprint import bringing an already-declared key is reported afterwards (`V-RT-10` on both BCs), not refused at the import.

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
capability here. Child commands included: every `erm: many` child's `Add{Singular}` / `Update{Singular}` /
`Remove{Singular}` appears in the `catalog` Resource (with `httpMethod` and `target`) and is exposed through
`bindings.<Command>.expose` exactly like a root command — no browser-only step. `save_closures` answers lean: findings and `newMtime`, not the saved catalog/bindings — pass `echo: true` to get
them echoed back, or read the stored content through the closures Resource. `save_closures` persists the catalog+bindings document (LockedSave — a
`CONFLICT` means the on-disk file moved under you, same mtime/force pattern as `save_aggregate`).
The `catalog` is merged **by entry name**: a sent entry is added or replaced as a whole (no field
merge inside the entry), entries you did not send stay, `catalog: {}` without `replace` is a no-op.
To make the sent catalog the whole catalog send `replace: true` — the dropped names come back sorted
in `removed`; `replace: true` without a `catalog` is a 400. `delete_closure` removes a single entry.
`bindings` and `validationOverrides` are **not** merged by entry: a sent map replaces the stored one
(unbinding by omission), an omitted one stays. `validate_closures` composes the same way (incl.
`replace`) and predicts the following Save;
Catalog entries: `reads: []` / `examples: []` clear the stored list, an omitted key keeps it;
a declared `messageKey` is binding (the 422 carries exactly this catalog key; the `reject()` argument in the body is not taken over) and is never cleared — omitted or `""` keeps the stored key, and an empty one on a
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
input's PHP type, the output's type plus its pass/reject or return shape, `output.rejectNote` when the entry declares a `messageKey` (the 422 carries that catalog key, not the one passed to `reject()`), `policyRef`, `examples`),
`files` (the stub path plus `stubExists`, the path of the test you write from `examples[]` plus `testExists` — both
Go-derived facts, true only when that file was actually found on disk —, and how to run the
test), `uses`/`reads` (every composed Closure / readable query, each with its own signature and
call recipe), `types` (the PHP field shape of every `command`/`aggregate`/`valuelist` input),
`context` (helper text and guardrails for the `handle()`/`context()` corridor), `usedAt` (guard
chains — a guard entry carries `viaSet` when a Rule-Set is the only path binding the Closure to
that chain — process Rule-nodes, and other Closures' `uses`), `body` (`offen`/`geschrieben` —
whether the generated stub still throws its Not-implemented marker; absent for a Rule-Set, which
has no body of its own), and `missing` (concrete next steps). Flow: read the resource → write the
`__invoke` body at `files.stub` → `build` (reconciles the stub's leftover `@throws` tags and markers — run it before PHPStan, otherwise `throws.unusedType` shows up) → write the test at `files.test` from `examples[]` (one case per example, against the generated `ClosureTestCase`), run it (`files.runTest`) until it is
green. Also reachable over HTTP — `GET /api/closures/{domain}/{subdomain}/{bc}/{name}/work` calls
the SAME service method and returns the SAME JSON shape; the contract itself is written via
`save_closures` — Resource and HTTP route only compose a read-friendly, AI-facing view of the same
data.

**Aggregate delete takes its queries along:** `delete_aggregate` removes the queries the aggregate owns from `Queries.json` (the delete preview lists them as `affectedQueries`; duplicated-root queries are `unassignableQueries` and stay); declared readers (`reads:`) of such a query block with 409 `details.queryReaders`, one `force` overrides every block, the answer carries `removedQueries`. `validate_definitions_all` also checks each BC's Queries (rows with `aggregate ""`), and the process validator rejects colliding PHP class names derived from one process (Blocker `V-PROC-CLASS-UNIQUE`).

**Rule node in a process (`save_process`).** A node with `rule: <Closure>` binds the closure's inputs in one of two forms, never both (`V-PROC-RULE-ARGS-XOR`): the short form `ruleSubject.payloadField` (closure with exactly one `command`/`aggregate` input; a process input field) or the long form `ruleArgs: [{"param": "<input name>", "source": …}]` that binds EVERY input of the closure exactly once. `source` is a bare string = a process input field, or `{"kind": "latest", "node": "<earlier node id>", "field": "<f>"}` = a field of the latest result of an earlier station (a command node adds `facade` + `method` and offers the fields of its identity chain; a hand-written node offers the fields of its `outputShape`; no `all`). A `command` input only comes from a command field of the process input; an `aggregate` input from a scalar key value (loaded, 404 as before). Any closure with a `verdict` output and at least one input may back a Rule node; `V-PROC-RULE-ARGS` blocks missing/duplicate/unknown params, unfitting types and a source node that is not a mandatory station before the rule.

**Queries-Layer — full MCP parity:** a BC's
`Queries.json` (declarative read queries — `root:` entity, visibility `internal`/`public`,
condition tree, parameters, joins; every query is a paginated list answering
`{items,total,limit,offset}`, so there is no output-form key — a document still carrying `form:`
is rejected by the Blocker V-QDEF-24) is fully MCP-reachable, same
pattern as Rules above. `save_queries` persists the whole artefact (LockedSave — mtime `CONFLICT`
like `save_aggregate`/`save_closures`; a draft with findings still writes, `valid`/`errors`/
`warnings`/`suggestions` travel in the response; the saved query set is echoed only with `echo: true`, otherwise read it through the queries Resource); `validate_queries` checks a not-yet-saved query
set against V-QDEF-1..16, 18..26 (25 = `limit` above 500, warning; 26 = the query's class stem equals a derived read way `{Agg}ById`/`ByIds`/`By<Key>` of its aggregate, blocker) plus RB1 (name collision with the read base, Blocker) —
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

**Doors and the middleware pipeline (app-wide HTTP pipeline, `jardiscore/app` >= 1.6).** Two things steer who may call a generated route:
- **Project setting `middleware`** — `update_project_settings` takes `middleware`: an ordered list of names; an entry without a backslash is a standard name (today only `session`), an entry with a backslash is a project class (FQCN, constructor `__construct(DomainKernelInterface $kernel)` or none). Never set = `["session"]` (the default, session guard on; it needs the env key `AUTH_TOKEN_KEY` - base64 of 32 random bytes - in the project `.env`, the access token is signed by `jardissupport/auth` >= 1.5); `[]` = deliberately no pipeline; omit the field = stored value untouched. Names are not checked at save time, a wrong one fails at app start. Read back the EFFECTIVE list: Resource `jardis://root` (field `middleware`) and `jardis://project` (field `middlewareEffective`; `manifest.middleware` is the stored value, absent when never set). The next `build` writes `src/App/middleware.php`; an older `src/App/bootstrap.php` without the `'middleware'` entry is never overwritten (the build warns).
  `update_project_settings` `{"middleware": ["session", "App\\Middleware\\AuditTrail"]}` (session first: it must run before any middleware that reads session attributes) · `{"middleware": []}` · `{"middleware": ["session"]}` (explicit default).
- **`door` on a process or a public query** — the only value is `anonymous` (the generated route registers as `$routes->anonymous()->{verb}(…)`, no session needed); an absent key is the session door (`"session"` is no value, Blocker). Commands, the Rules-Layer and the generated read ways carry no `door`. It is a top-level key of the `process` wire of `save_process` (`{"name":"RequestCard","process":{…,"door":"anonymous"}}`) and a key of a query inside `save_queries.queries` (`{"requestCard": {"root": "Card", "visibility": "public", "door": "anonymous", …}}`). A save is a **full replace**: carry the loaded `door` on every later `save_process`/`save_queries`, omitting it silently turns the door back into a session door. **`V-DOOR-1`** (Blocker, `validate_process`/`validate_queries`): an anonymous process declares no input field with `requestAttribute`, an anonymous query is not rooted in an aggregate whose root carries a request-attribute scope column (`tenant`). Resource `jardis://doors/{domain}` lists every outer door per BC with `door: session|anonymous` — read it to verify what is public before building.

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
exists" — safe to retry with a different name or treat as confirmation; **not** for `import_blueprint`, where it means nothing was written), `CONFIRM_REQUIRED`
(a destructive call — delete/rename with real impact — needs an explicit confirm flag; inspect
the matching preview Resource first), `BUILD_RUNNING` / `DRAFT_EXISTS` (build-time guards, §2
step 6), `NOT_FOUND`, `VALIDATION` (inspect `details` for field-level findings). Never retry a
`VALIDATION` or `CONFIRM_REQUIRED` failure unchanged — read the envelope, fix the cause or
supply the confirmation, then retry.

### 7. Reference

- Full Tool/Resource catalogue: `tools/list`, `resources/list`, `resources/templates/list` on the
  running server — the live surface is the only catalogue, there is no inventory document.
- Build control of the board: Tools `run_qa`, `run_make_target`, `stop_make_target`,
  `post_studio_card`; Resources `jardis://project/make-targets`, `jardis://studios`,
  `jardis://studios/{name}`, `jardis://closed-studios`, `jardis://mcp-log/{name}` (full log
  of a prior `build`/`build_process`/`run_qa` call) — see §2 "Studio build control".
- Once generated code exists and you are implementing behaviour inside it: `generated-code-extend`.
- Designing a schema's content from a domain idea instead of introspecting a live database, then
  feeding it through `import_schema`: `design-draft-schema`.
