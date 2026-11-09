# Station 4 — Execution: process node, query, command capsule

The request has passed the route, the DTO and the process door; now a node runs, a query reads or a command capsule writes. Three kinds of work happen here, each with its own fixed behaviour. What follows is what the generated code does for every request, so that your hand code is only the remainder.

## Guarantees

**Process node**

- A node is `__invoke({P}Context): NodeResult` and returns `ok`, `route` or `reject(4xx)`. `reject` demands a 4xx status and ends the run (rollback, failure form of that status). Edges branch only through the route (`onTrue` is the yes route of a question, `onFalse` the no route, never an error) and carry no status. A 403 is never a node rejection: the door answers it before any node runs.
- A node declares its rejections as `rejects: [{status, messageKey}]`. The status must be one of 400, 401, 404, 405, 409, 422 (else `V-NODE-REJECT-STATUS`); a literal status in a body without a declaration is `V-NODE-REJECT-UNDECLARED`. An edge that carries a `messageKey` or `responses[]` does not load.
- A Rule node is generated completely (hermetic), the body is never yours. As a guard it rejects with 422 `RuleViolation`; in decision mode the package turns that 422 into the route `onFalse`. A subject that cannot be loaded is 404 in both modes and never `onFalse`.
- A Call node translates the answer of its target: 2xx becomes `ok`, 4xx becomes a rejection with the same status, 5xx becomes an exception (rollback, 500). A body may choose `route(onFalse)` for a 4xx on purpose; a blanket "success or onFalse" mapping cannot tell a 4xx from a 5xx.
- The failure form is built by the package from status, `messageKey` and context. Events travel on the result of the node; a rejection discards them. Around every node the engine applies `GuardLoop` (step limit, `LoopLimitExceededException`), `CollectEvents`, `HydrateResponse` and `ApplyRuleMode`.
- `read(Handler, Filter)` reads only the own BC; a handler of a foreign BC is refused with `LogicException`. `context()` and `handle()` stay unrestricted. A foreign BC is used only through its doors at stance `domain` or wider, from a Domain Service under `{BC}/Service/`: it reads through `{Agg}Read`, writes through `process()` or a command door, never through a foreign aggregate, repository or SQL.
- The stub brings its context: the declared `Reads`, `Gives` and `rejects`, plus the chain Domain → BC → Process → Node as PHPDoc.

**Query**

- Every declared query is a paginated list. Its filter is a DTO with `limit = 100` and `offset = 0`; the SQL is built from the model on `dbquery`: parameters, tenant marker, count, ordering with stable keys, and the declared upper bound of `limit` (an internal caller is clamped with `min()`). It reads over the reader connection. The outer column names come from the FieldMap as SQL alias; the spelling is always the FieldMap's.
- Every query that reads a tenant-marked entity (root, child derived with `adopt`, join partner) filters on the tenant automatically through the DTO field `tenant`; a left or full join partner is matched as `= {tenant} OR IS NULL`. In a world with login every root carries a `tenant` marker (`V-TENANT-1`, exception: the BC with `loginIdentity`). A condition with a parameter on a marker column is a blocker (`V-TENANT-5`).

**Command capsule**

- The capsule validates by itself, whoever calls it. It has its own `ValidateInput` with its own registry; the rules come from the schema: `NotEmpty::trimmed` for NOT-NULL strings, `Length::max`, a pattern for `decimal(p,s)`. No validator takes a marker column.
- The status staircase is fixed: 400 (fields) → load → 404 (root missing; never under Create) → 422 (bound Rule chain) → 404 (child or apply miss) → 409 (persist) → 500. A form error comes before a miss. An Update loads the root and, with `$expected`, checks it by compare-and-set against the values the caller read.
- The FieldMap translates with `toColumns`, then `persist` runs. A unique violation becomes 409 `duplicate`, a stale write basis 409 `stale_write` (or `stale_delete`). The lifecycle event is collected with `EventScope::Internal` and not sent.
- Rules act in two places: in the guard chain of the command and as a Rule node in a process. The same rule never runs twice in one flow. A command door at `domain` or wider that has no bound rule gets the warning `V-RULE-5`, because process rules apply only on the process path.

## Never

You write only what is left after the description, the model and these guarantees. So you never:

- build a failure form, an error payload or a status by hand. A node ends with `NodeResult::reject(status, messageKey, context)` and the package forms 400, 404, 409 or 422; no status goes into `data`, none onto an edge.
- put a check of a command field into a node: empty, length, format, decimal pattern, existence of the root, stale write or duplicate. The capsule does it; a field-content 400 from a node does not exist in any form.
- write a rule twice: no node body that re-judges what a bound Rule chain or a Rule node already judges.
- filter, sort, page or count by hand, post-filter a query result, copy a query's SQL into a body, or write own SQL. A need for another selection is a query in the model (parameter, `where`, `orderBy`, `in`, join), read through `read(Handler, Filter)` in the own BC.
- add the tenant condition yourself, pass tenant as an ordinary query parameter on the marker column, or let a validator or an output carry a marker column.
- map the column names or call `toColumns`, `persist` or the repository yourself; a write is an aggregate command or a `process()` call.
- translate the answer of a Call node by hand into a route or a status.
- read a foreign BC directly: no foreign handler through `read()`, no foreign aggregate, repository or SQL. Use its doors from a Service under `{BC}/Service/`.
- build what an earlier means already carries. The order of means is process, query, aggregate (schema columns, value list, relations), closure, and only last a node body.

## Variants

All paths are relative to the generated tree of the BC; `{P}` is a process, `{Agg}` an aggregate, `{Node}` a node, `{Cmd}` a command, `{Name}` a declared name.

**Process nodes** (`{BC}/Process/{P}/Command/Handler/Action/{Node}.php`)

| Form | Where | Differs |
|---|---|---|
| Action node, hand body | `Action/{Node}.php` | frame generated, body yours |
| Rule node, guard | `Action/{Node}.php`, hermetic | 422 ends the run |
| Rule node, decision | `Action/{Node}.php` plus the route in `{P}Handler::definition()` | 422 becomes `onFalse` |
| Call node on a command of the own BC | `Action/{Node}.php` | answer mapped with `MapResponse` |
| Call node `call:read` | `Action/{Node}.php` | a reading call, writes nothing |
| Read through the kernel seam | `Action/{Node}.php` calling `read(Handler, Filter)` | own BC only |
| Cross-BC call | `Action/{Node}.php` → `{BC}/Service/{Name}.php` → foreign `process()` | foreign BC only through its door |
| Service reading a foreign BC | `{BC}/Service/{Name}.php` | reads through `{agg}()` of the foreign door |
| Sub-process node | `Action/{Node}.php` | opens the other process through the kernel seam, depth plus one |
| Event node | `Action/{Node}.php`, hermetic | events travel on the result (`withEvents`) |
| External call | `Action/{Node}.php` | body calls the external target |

**Queries** (`{BC}/Model/{Agg}/Repository/Query{Name}.php`)

| Form | Differs |
|---|---|
| required parameter plus tenant | tenant condition added automatically |
| optional parameter | parameter declared optional in the model |
| `in` list | list parameter in the model |
| join | tenant filter also on a joined tenant-marked entity |

**Commands** (`{BC}/Model/{Agg}/Command/Handler/{Cmd}.php`, validator in `{BC}/Model/{Agg}/Command/Validation/`)

| Form | Differs |
|---|---|
| Create | no 404 for the root |
| Update with `{Cmd}Patch` | loads the root, 404 if absent, compare-and-set with `$expected` |
| Set | writes the root completely |
| Remove | no validator, 404 if absent |
| Child command | acts on a child of the root |
| Command with a bound Rule chain | 422 after the 400, before persist |
