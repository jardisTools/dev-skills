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
    "identifier": {"stage": "uniqueColumn", "column": "order_no"},
    "tenant": "region_code", "user": {"created_by": "onCreate"},
    "writeOnly": ["api_token_hash"]}
   ```
   (An aggregate without any outer door has every read row of its block `closed` in `Doors.json`: write it with `save_doors` (gesture `closeReads`); `graph.visibility` is retired, see below.)
   - `tenant` / `user` (any entity, root AND child; omit = keep, `""` / `{}` = delete): `tenant` names the ONE column holding the tenant of the signed-in user, `user` maps columns to `onCreate` / `onWrite` (the bare id of the signed-in user; `onCreate` = Create only). The value comes from the request context, never from the client; the column leaves every input contract and is set at every write door; only `tenant` also filters the read doors, `user` is a write value. Columns must be varchar/char/uuid/integer data columns, not PK, key, adopt, computed (`V-TENANT-2`). A child entity that takes over the effective tenant column of an ancestor via `adopt` (transitively over the levels) is already tenant-filtered by derivation: every query rooted at it gets the automatic tenant filter, the value comes from `adopt`, not from the session, and a further marker on that `adopt` column is refused. The load echoes `tenantBlocked` / `userBlocked`, ignored on save. In a world with login (`middleware` carries `session`) every aggregate root needs a `tenant` marker (`V-TENANT-1`, Blocker; exempt: a BC with `loginIdentity: true`).
   - A query at the stance `outside` on a tenant-marked root (or on an entity derived tenant-filtered via `adopt`) needs no tenant filter of its own: the generated `{Query}Filter` takes `tenant` as first constructor argument and every lookup is scoped; the query value `{user: true}` binds the signed-in user (`V-TENANT-3` warns without login).
   - `writeOnly` (omit = keep, `[]` = delete): written, never read from outside — absent from Akte, public list, bulk read, events and the OpenAPI response, present in the write contract. Only a query that reads inward (stance `closed` or `domain`) projects it (family-internal read, e.g. verifying a stored digest). A query that reads outward (stance `outside` or wider) may not filter or order on it (`WO-2`); not the PK, key, FK, adopt, computed or a `tenant`/`user`-marked column (`WO-1`).
   - `graph.visibility` is RETIRED (also `rename_aggregate` `visibility`): the call is refused with `V-DOOR-0` and a hint naming `save_doors`. An aggregate without any outer door is the state in which every read row of its block in `Doors.json` is `closed` (gesture `closeReads`): no `{agg}()`/`{Agg}Read` on the BC facade (it stands only while a read way, the base list or a declared root query of the block is at least `domain`), no route, no OpenAPI path; reads and writes stay possible family-internally through the kernel seam and through a process of the BC. Cross-BC access goes through a read door of the owner at `domain` or wider (its `{Agg}Read`), and cross-BC writes through the target BC's `process()` or a command door at `domain` or wider (`{BC}::{command}()`); a `consumedCalls` entry or sub-process node onto a door that is missing or stands `closed` is `V-CONSUMED-EXISTS` (blocker); a `ref` field of a process answer whose aggregate has no read door in the reader's world (neighbour BC: stance `domain` or wider, HTTP: `outside` or wider) is left out of the outer form and stays inside; no blocker, no warning (nobody outside could reload it). The former `V-AGG-VIS-1`…`5` are gone.
   - Blockers come back in `valid` / `errors` of the save answer; check them (the aggregate is written regardless).
   - The request attributes are `RequestAttribute::SUBJECT_ID`, `::TENANT` (`jardisTenant`) and the rights claim `::RIGHTS` of `jardissupport/contracts` >= 2.17; the authenticating middleware of the app sets them. A route whose right is missing answers 403 `access.rightMissing` (`context.right`) from `RequireRight`, one whose required attribute is absent 403 `request.attributeMissing` (`context.attribute`) from `RequireRequestAttribute` (both `jardiscore/app` >= 3.2) before the handler — never 500; the OpenAPI contract names both.
   - A process input field takes no request attribute: `requestAttribute` is no format term any more, not even the former `session` marker (a leftover field of an older workspace is refused when the process loads, with the existing rule id Z-M2; nothing is migrated on open). Tenant and user are no declared input: in a world with login the door fills the fields `tenant`/`user` of the process input and a node reads `$ctx->input()->tenant` / `->user`; a Rule node on a tenant-marked root takes its scope from there, with no scope-source field.
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
   foreign BC needs no `crossBcCall`: it is a Cross-BC-Call node by itself (ONE shared Service per target,
   named from the target, derived).
   **Success answer (`gives` per node, the answer is the `gives` of the end node).** A process names its 200 `data`
   itself; nothing is derived from the node it ends on, and the process carries no `end`. Every node carries
   `gives: {kind: aggregate|identifier|value|nothing, name, …}` (what it hands out; a `value` is a scalar `type` or an
   object with `fields: [{name, type, nullable?, list?, ref?}]`, `type` from the column-type vocabulary of `Schema.json`).
   The answer of the process is the `gives` of the **end node that ran** — an end node is every node with a terminal
   edge `{from, on, terminal: true}` on a non-failure status, and a process may have one per exit, each with its own
   form. Every answer carries `@outcome` behind `@type` (`@type` stays the process name): the `gives.name` of the end
   node that ran, word for word, always, also with exactly one end node. `gives.name` is mandatory at every end node,
   also with `nothing` (`V-PROC-GIVES-1`), and is the free outcome name (`V-PROC-OUTCOME-2`: `^[a-z][A-Za-z0-9]*$`,
   at most 64 characters); the caller tells the exits apart by `@outcome`, never by which fields are present:
   - `gives: {kind: identifier, name, …}` — `@type`, `@outcome` plus the reference value of the command of that node.
   - `gives: {kind: value, name, fields: [{name, type, list?, ref?}]}` — `@type`, `@outcome` plus exactly these fields. `ref` marks a
     reference to an aggregate root the process wrote (own `"<Agg>"`, foreign `"<Subdomain>.<BC>.<Agg>"`), `list: true` a
     list of the type (with `ref` a list of references).
   - `gives: {kind: nothing, name}` — `@type` and `@outcome` only.
   Two end nodes may carry the same `gives.name` only with an identical inner form (`V-PROC-OUTCOME-1`, replaces
   `V-PROC-ANSWER-4`). The union of outcomes is open: a new outcome is additive, an unknown `@outcome` is the error
   branch of the client, and renaming or removing an outcome is a contract break like a field rename.
   Reference duty: for every aggregate a writer on a success path writes, the end node carries a field with `ref` on it
   (blockers `V-PROC-ANSWER-1…3, 5, 6`: reference duty, "writes" from the call target, valid `ref`, object
   form, at least one end node; `V-PROC-GIVES-1…4` for the node `gives`, `V-PROC-OUTCOME-1/2` for the outcome name;
   `validate_process` reports them).
   **Filter per world:** a `ref` field is left out of the outer answer when its aggregate has no read door in the
   reader's world — neighbour BC (`{BC}Process::{name}()`, cross-BC call, `answers` of the door list) needs stance
   `domain` or wider, HTTP (`routes.php`, OpenAPI) `outside` or wider; inside (handler, process nodes) every field stays.
   A field that must stay outside needs a read door on the aggregate (`save_doors`). The resource
   `jardis://doors/{domain}/{subdomain}/{bc}` shows the owner's side: `answers` at a process door (one form per outcome — `outcome` = `gives.name`,
   the same name is one form — the outside view) and `key` at a read door (public unique key, else `id`).
   The `save_process` schema has no `end` (a save is a full replace, so carry the values you loaded). The retired keys
   are refused by `save_process` with the structured error envelope (CUT1, hint names the replacement) and nothing is
   written: `end` → the `gives` of the end node; node `response` → `gives`; process `successOutput` and `answer` → the
   `gives` of the end node (`update_process_metadata` refuses `answer`). Details and OpenAPI effect (`x-jardis-response`:
   `reference` / `fields`, several outcomes as `oneOf` plus `discriminator` on `@outcome`): `generated-code-recipes` Recipe 6.
6. **`build`** — a long-running Tool: generates the aggregate (and/or process) code tree onto
   disk. Reports progress notifications; a concurrent build on the same scope refuses with
   `BUILD_RUNNING`, an unsaved designer draft with `DRAFT_EXISTS` (§6). `build_domain` builds a
   whole domain in one call, in build order: aggregates before the processes of the same BC and
   producer BCs before their consumers (edges from `consumedCalls`; ties keep the alphabetical scan
   order; on a mutual-call cycle the order breaks at its first member without an error and the
   second side stays `not-checkable`). A consumer built before its producer would report
   `not-checkable` for the cross-BC call, so do not work around it with a hand-ordered BC loop.
   **Findings of a run (one form at every door).** `build`, `build_process`, `build_domain`,
   `validate_process`, `save_process` and `validate_definitions_all` report a finding as
   `{ruleId, severity, process, nodeId, file, line?, subject, message, hint}`; `severity` is
   `blocker`, `warning` or `suggestion`, `subject` has exactly one of the forms `{fields: [...]}`,
   `{field}`, `{query}`. A refusal carries the COMPLETE list of the run in `details.findings[]` of
   the error envelope (a blocker of the model: 422 `VALIDATION`; a blocker of the generator's own
   self-check, `GATE-HANDLE-CONSISTENCY`: 500 `INTERNAL`); `ruleId`, `location` and `hint` of the
   envelope stay the first finding. The gates before the first write (model, handle consistency,
   answer reference) and the gates on the final bodies (undeclared reject, call, construct) each
   collect every blocker of all requests, and a blocker writes nothing. A run that goes through
   returns the warnings and suggestions whose rule is known as `findings` (always an array;
   `build_domain` adds the `scope` of the element); the text lists `warnings` / `suggestions` and
   the progress events stay as they were. `validate_process` and `save_process` return `findings`
   next to the old buckets `errors` / `warnings` / `suggestions`. Every hint that points at
   knowledge names the skill and its section (e.g. `generated-code-request-path`, "Signs of a
   superfluous node" for `V-PROC-DUPCHECK` and `V-PROC-READ-UNDECLARED`).
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

