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
   - A query at the stance `outside` on a tenant-marked root (or on an entity derived tenant-filtered via `adopt`) needs no tenant filter of its own: the generated `{Query}Filter` takes `tenant` as first constructor argument and every lookup is scoped; the query value `{user: true}` binds the signed-in user (without login the blocker `V-TENANT-3`).
   - `access` (the aggregate ROOT only, never a child; omit = keep, `""` = delete): **protection is the rule, `access` is only the exception.** In a world that protects - login AND groups (project setting `groupLookup` or `groupDoors`) AND a bounded context WITHOUT `loginIdentity` - every root table carries the per-record sight on its own, and a root WITHOUT `access` is protected; you do not switch it on. Without login, without groups, or in the login bounded context nothing is protected (no sight, no number), and a statement rests without a finding (only without login and in the login bounded context it is refused). The protection belongs to the ROOT TABLE, not to one aggregate: several aggregates on the same root table are allowed, they carry the SAME statement (none, `everyone` or the same owner/via pair), and every one of them reads (list, single, multiple, query) and creates (entries) under the sight and the number of that table. Exactly two forms. `"access": "everyone"`: the aggregate is released, its records are visible to everyone in the tenant, the root table carries no sight and no number. `"access": {"owner": "<Aggregate>", "via": "<column>"}`: the aggregate takes over the sight of its owner, an aggregate of the SAME bounded context whose root table is protected (the owner itself is not released), through a column of its root; no number of its own (it reads under the number of its owner's table). The old form `"access": "own"` is RETIRED: every load door and `save_aggregate` refuse it (rule `Z-M9`, hint "remove the statement, protection is the rule"), no migration on open. The root table of an existing aggregate never changes: a save with another table ends as a draft (`Draft.json`), the answer names the reason and "create a new aggregate"; the first save of an empty aggregate sets it. The numbers: a protected root table gets ONE number in the access-type list of the domain, read back (read only) in `jardis://domain/{domain}/manifest` under `access.types` as `[{id, aggregate: "<Subdomain>.<BC>.<Aggregate>", retired}]`; one reconciliation keeps it, and every door that changes the model or the world calls it (save, delete, duplicate, `write_definitions`, the CLI `definition:build`, blueprint import, `update_project_settings`, every build). A second aggregate on the same table shares the number, a copy shares the number of its source's table; releasing or deleting the aggregate that carries the row hands the row to the next holder of the table, the number is retired only with the last one and never comes back; switching groups off and on again retires nothing; renaming keeps the number. Every other form is refused: a form other than the two, or a marker below the root (`V-ACCESS-1`); a statement in a world without login or in the login bounded context (`V-ACCESS-2`); an owner of another bounded context, an owner that is released, a `via` that is no root column, or different statements on one root table, because the protection of a root table is unambiguous (`V-ACCESS-3`; the same statement on several aggregates stays allowed); a protected root table without exactly one living number in the list (`V-ACCESS-4`); a read door of an aggregate on a protected root table at the stance `open` (`V-ACCESS-6`, warning: without a person the door answers no records); a delivered query whose root is a protected root table and whose `limit` exceeds the count cap of `jardissupport/acl` (`V-ACCESS-7`). `V-ACCESS-3` and `-4` judge only where the world protects. There is no rule `V-ACCESS-5`: the sight is checked only at the ROOT (the root table of the aggregate and the root entity of a query, and only when that root table is a sight table). A joined entity, a child table, a `depend` target and the reload carry no sight and are not refused; what comes along behind the root is decided by the modeller of the query or aggregate (the door decides who executes, the sight decides at the root row which rows come). A query root on a child table carries no sight; an aggregate whose root lies on that table makes it a root table, protected by the rule. Writing asks as well: every write door of a protected root (update, delete, child operations, `Set`, `Remove`) asks the access check once before it writes, from the groups of the session and only when the caller is a session (internal callers, processes, the owner `*` and a `seeAll:` entry of the door ask nothing): no sight is the same 404 as an unknown key, a level too low (`edit`; `all` for deleting the root) is the 403 `forbidden` of the domain; a dependent aggregate (`{"owner", "via"}`) is asked at its owner with `edit`, also when deleting, and when a change hangs it on another owner that owner is asked too (`edit` at both); deleting a root removes exactly its access entries. There is no locking: between the question and the write a revocation can fall. The aggregate load returns the stored marker. Example (a released aggregate): `{"id": "n1", "table": "countries", "entityKey": "country", "access": "everyone"}`.
   - `writeOnly` (omit = keep, `[]` = delete): written, never read from outside — absent from Akte, public list, bulk read, events and the OpenAPI response, present in the write contract. Only a query that reads inward (stance `closed` or `domain`) projects it (family-internal read, e.g. verifying a stored digest). A query that reads outward (stance `outside` or wider) may not filter or order on it (`WO-2`); not the PK, key, FK, adopt, computed or a `tenant`/`user`-marked column (`WO-1`).
   - `graph.visibility` is RETIRED (also `rename_aggregate` `visibility`): the call is refused with `V-DOOR-0` and a hint naming `save_doors`. An aggregate without any outer door is the state in which every read row of its block in `Doors.json` is `closed` (gesture `closeReads`): no `{agg}()`/`{Agg}Read` on the BC facade (it stands only while a read way, the base list or a declared root query of the block is at least `domain`), no route, no OpenAPI path; reads and writes stay possible family-internally through the kernel seam and through a process of the BC. Cross-BC access goes through a read door of the owner at `domain` or wider (its `{Agg}Read`), and cross-BC writes through the target BC's `process()` or a command door at `domain` or wider (`{BC}::{command}()`); a `consumedCalls` entry or sub-process node onto a door that is missing or stands `closed` is `V-CONSUMED-EXISTS` (blocker); a `ref` field of a process answer whose aggregate has no read door in the reader's world (neighbour BC: stance `domain` or wider, HTTP: `outside` or wider) is left out of the outer form and stays inside; no blocker, no warning (nobody outside could reload it). The former `V-AGG-VIS-1`…`5` are gone.
   - Blockers come back in `valid` / `errors` of the save answer; check them (the aggregate is written regardless).
   - The request attributes are `RequestAttribute::SUBJECT_ID`, `::TENANT` (`jardisTenant`) and the rights claim `::RIGHTS` of `jardissupport/contracts` >= 2.17; the authenticating middleware of the app sets them. In a world with per-record protection (protection is the rule, see `access` above) the group middleware add `::GROUPS` (`list<int>`, `jardisGroups`), `::PERSONAL_GROUP` (`int`), `::FALLBACK_GROUPS` (`array<int, int>`, information kind => group) and `::SHARE_TARGETS` (group => level word, or `false` when a target cannot be resolved), see `jardis://root` setting `groupLookup` or `groupDoors`. Every read route of a protected root fills its `groups` filter from `GROUPS` (absent = no group = the empty list = no records; only the owner `*` or a `seeAll:` entry the rule of the door accepts - `seeAll:<right of the route>`, or `seeAll:<resource>:*` for every door of the resource - sees all); none of this appears as a parameter in the OpenAPI contract. The create door of a protected root takes ONE optional request header, `X-Jardis-Share-With: 12=view, 15=edit` (comma-separated `group=level`, level one of the words `view`, `edit`, `all`): the new record is created with an entry `all` for the personal group of the caller (without one: the fallback group of the information kind) and one entry per named group; a missing or unknown level answers 400 under the header name, a group that cannot be resolved answers 404; the header exists only at that operation, a process door has none. Sending nothing is valid. A route whose right is missing answers 403 `access.rightMissing` (`context.right`) from `RequireRight`, one whose required attribute is absent 403 `request.attributeMissing` (`context.attribute`) from `RequireRequestAttribute` (both `jardiscore/app` >= 3.2) before the handler — never 500; the OpenAPI contract names both.
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
   **Access statement of the process (`access`).** A process on a protected aggregate can ask the access check once before it runs: the optional root key `access: {"aggregate": "<Agg>", "keyFrom": "<input field>", "level": "view"|"edit"|"all"}` (next to `requires`, behind `loopLimit`), settable with `create_process` and `save_process` and checked by `validate_process`. The door loads the record of `aggregate` that the input field `keyFrom` names and asks the level for the groups of the session; no sight is 404 (the same as an unknown key), a level too low is the 403 `forbidden` of the domain, and then no node runs and no transaction opens. A process without the statement, a sub-process and a call from another BC ask nothing (inside a process everything is free). Rules: `V-PROC-ACCESS-1` (the aggregate belongs to the own BC and its root table carries the sight: protected by the rule, or inheriting through `{"owner", "via"}`; a released `everyone` table is refused), `-2` (`keyFrom` is a required plain scalar of the type of the write key), `-3` (the level is one of `view`, `edit`, `all`) are blockers; `-4` is a warning at the stance `open` (the door has no session, so it answers 404; in a world with login `V-ROUTE-OPEN-TENANT` also stops the build, the stance `outside` solves both). `save_process` is a full replace EXCEPT for this key: omitted keeps the stored statement, `"access": {}` removes it. The question is asked for exactly the row that the `keyFrom` field names. If the process should write only that row, take the key as a plain input field and build the command in the node from it, instead of accepting the whole command: a whole command carries its own key, and the node may write a row other than the one that was asked about.
   **Sharing as a node call.** Responsibility is a process, not a door: a node calls `Share{Agg}` of its OWN bounded context like any other call (`consumedCalls`, set with `save_process`, read from the process resource). The call is offered per protected root table from the stance `domain` of its `Share{Agg}` row in `Doors.json` (kind `access`; see the door reference). The build writes the node body: it hands `$ctx->input()->shareTargets` to the BC facade, inside the transaction of the process and without a sight question, so a refusal rolls the bracket back and the field value of an earlier update node does not stand. The targets (`groups` and/or `person`, `level`) arrive in the body of the process route next to the input. Blockers: `V-PROC-ACCESS-5` (a call into another BC, onto an aggregate without its own protection, or onto a `closed` row), `V-PROC-ACCESS-6` (an input field named `groups`, `person`, `level` or `shareTargets` on a process that reaches sharing). Unknown target: 404 with the key of the record. No separate tool.
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
   `build_domain` does not stop at a blocked element: it builds every element whose gates pass
   and lists each blocked one in `failed[]` with `status`, `code`, `message` and, for a rule
   refusal, `details`; `ok` is `false` when `failed[]` is not empty.
   **Findings of a run (one form at every door).** `build`, `build_process`, `build_domain`,
   `validate_process` and `save_process` report a finding as
   `{ruleId, severity, process, nodeId, file, line?, subject, message, hint}`;
   `validate_definitions_all` keeps its own rows (`severity` `error`·`warning`·…, `rule`,
   `message`) and adds `ruleId`, `v1Severity` and `subject` beside them. In that form `severity`
   is `blocker`, `warning` or `suggestion`, `subject` has exactly one of the forms
   `{fields: [...]}`, `{field}`, `{query}`. A refusal of the model check, of the handle
   consistency, of the answer references or of the final bodies (undeclared reject, call,
   construct) carries every blocker of the run in `details.findings[]`; a refusal of the door,
   rule, query, event-node or value-list gates carries one blocker in `details.findings[]` and
   names all blockers of the first failing gate in `message`. A blocker of the model answers 422
   `VALIDATION`, a blocker of the generator's own self-check (`GATE-HANDLE-CONSISTENCY`) 500
   `INTERNAL`. `ruleId` and `hint` of the envelope are those of the first finding; `location` is
   empty at a gate refusal, and a first finding without a hint leaves the general hint of the
   error code. The gates before the first write (model, handle consistency, answer reference) and
   the gates on the final bodies (undeclared reject, call, construct) each collect every blocker
   of all requests (a request with a model blocker is not checked for handle consistency and
   answer reference), and a blocker writes nothing. A run that goes through
   returns the warnings and suggestions whose rule is known as `findings` (always an array;
   `build_domain` adds the `scope` of the element); the text lists `warnings` / `suggestions` and
   the progress events stay as they were. `validate_process` and `save_process` return `findings`
   next to the old buckets `errors` / `warnings` / `suggestions`. The hints of `V-PROC-DUPCHECK`
   and `V-PROC-READ-UNDECLARED` name the skill `generated-code-request-path`.
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

