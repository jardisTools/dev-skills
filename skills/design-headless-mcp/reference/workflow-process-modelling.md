#### Continuation §2 — steps 4 and 5: `save_naming`, process modelling

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
