#### Continuation 2.2 — command forms, answers and outer door

Command forms the tree yields (all derived, no JSON of their own):

| Command | Fields | Notes |
|---|---|---|
| `Create{Root}` | scalars (not system-managed, not adopted, not FK to owned child), grouped VOs, `one` children, `many` lists (required lists before optional) | a door like the others: row `Create{Agg}` in `Doors.json` (`closed` by default); at `domain` the facade method `create{Agg}()`, at `outside` also `POST /{bc}/{aggs}` and a right |
| `Update{Root}` | root identifier + root scalars only, as the patch `Update{Root}Patch` | no child lists, no `one` children — children change through `Update{Child}`/`Add{Child}`/`Remove{Child}`; patch form: only the address (identifier, parents) is required, a field not delivered stays unchanged, an explicit `null` clears a nullable field and is 400 on a non-nullable one; the root PUT route exists only when the stance of the row `Update{Agg}` in `Doors.json` is `outside` |
| `Set{Child}` / `Add{Singular}` | parent selector + child scalars | no nested `many` collections; clashing names are prefixed with the entity name, never dropped |
| `Update{Singular}` (`many`) | parent selector + child identifier (path only) + child scalars | patch form like `Update{Root}` (class `Update{Singular}Patch`): only the address is required, a field not delivered stays unchanged, an explicit `null` clears a nullable field (400 on a non-nullable one) — no full replacement, a missing key is never `null`; the child identifier is immutable and never part of the body (an identifier in the body is 400 `unknown`); embedded `one` sub-objects stay out (change them via `Set{Sub}`); emitted only when the child has at least one changeable field; one event `{Agg}{Child}Updated` per call, also for a no-op; `PUT …/{roots}/{rootId}/{children}/{childId}` once exposed |
| `Remove{Singular}` (`many`) | parent selector + child identifier | cascade property is the child identifier; `Remove{Child}` of an `one` child needs no fields |
| `Remove{Root}` | root identifier | cascade, see above |

Selector rule: when the path crosses a `many` ancestor, the command carries ONE selector `{entity}Identifier` (string) or `{entity}Id` (int) for the last crossing.

Response (every command): flat `data` with `@type` = aggregate key plus the root business identifier and the references of the touched children — never objects. Errors: 400 `{"@type":"validation","fields":[{field,reason,message,messageKey}]}` (`field` = outer name as a path with the list index, e.g. `orderItem[0].qty`), 404 `{"@type":"notFound","context"}` (a write that loaded its aggregate or child and found none — never a Create), 409 `{"@type":"concurrencyConflict",…}` or `duplicate`, 422 `{"@type":"ruleViolation","rule","messageKey","context"}`. A process calling a command answers 200.

Outer door (BC facade): `{agg}(): {Agg}Read` (lookups by unique key — `id`/`ids` only for an aggregate without a public unique key — and lists) and `process()` — `{agg}()` only for an aggregate with a read door at `domain` or wider (`Doors.json`), none otherwise; the write facade is family-internal, a command is a facade door `{BC}::{command}()` only at `domain` or wider. Cross-BC writes go through the foreign `process()` or a command door at `domain` or wider, cross-BC reads through the foreign `{Agg}Read`.

