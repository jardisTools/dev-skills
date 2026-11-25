#### 2.6 Closures (rules, values, sets)

Door: MCP `save_closures`, `validate_closures`, `rename/delete/duplicate_closure`; resources `closures/…`, `closures/{d}/{s}/{bc}/{name}/work`; UI closure editor (created from the model list "New" menu, chain on the aggregate API tab). Use only when the table in §4 says "not available declaratively".

| Setting | Allowed values | Effect |
|---|---|---|
| `catalog.<Name>` | PascalCase | class `{BC}/Closure/{Name}.php` — body is dev code, stub is generated |
| `input[]` | 0..n of `{name, type:{kind: command\|aggregate\|scalar\|valuelist, name, optional?, list?}}`; names refer to the OWN BC | typed arguments of the stub |
| `output` | `verdict` or a scalar type | a verdict is accept/reject; any other output is a computed value |
| `messageKey`, `policyRef` | `{bc}.{cause}`; id of a business policy of the BC canvas ("Steckbrief") | verdict only (`messageKey`, binding: the 422 of every generated rejection carries it); `policyRef` on any kind; shown by the governance coverage |
| `reads[]` | `{query, needs, binds}` on queries of the own BC (a declared query or the read base `{agg}ById`, `{agg}By{Key}`, …) | declares what the body reads (M9: own BC only); V-PROC-READ-UNDECLARED holds the Closure and Service bodies against it |
| `compose: "all"` + `uses[]` | names of catalog entries of the same BC, no set inside a set, no cycle | a rule set; `uses` without `compose` is a free sub-closure reference |
| `examples[]` | `{name, given, reads?, uses?, expect}` | contract for the test the AI writes (Jardis generates no test); missing on a non-set closure is a warning, a set carries none |
| `bindings.<Command>.chain[]` | ordered closure names, no duplicates after flattening; output `verdict`; at most one `command` input (that command), at most one `aggregate` input (not on Create); no scalar/valuelist input | AND chain with short circuit, enforced structurally at the endpoint |
| stance of the command row (`Create{Agg}`, `Update{Agg}`, child commands …; no key in `Closures.json`: `bindings.<Command>.expose` is retired, V-DOOR-0) | `closed` (default) / `domain` / `outside` in `Doors.json` | at `domain` or wider: direct method `{command}({Cmd}DTO)` on the BC facade (`create{Agg}()` for the Create row); at `outside` also route and right. Prose rule V-RULE-5: a command at `domain` or wider without a bound rule warns, and says that process rules do not apply on this path |

Rejection is `422 {"@type":"ruleViolation", rule, messageKey, context}`, never an exception. With a catalog `messageKey` the 422 carries exactly that key — the `reject()` argument in the body is not taken over; declare the key in the catalog and use the same one in the body. Command and Process doors declare this 422 in OpenAPI with `messageKey` as a closed enum of the catalog keys of all Rules that can reject (Rule-Sets resolved); it stays open (no enum) as soon as a reachable Rule has no catalog key or a dev body of the door's own BC is reachable, or a cross-BC call, external call or custom node with a foreign-BC call is reachable next to the Rules (its service body can answer 422 with its own key); a cross-BC/external call alone declares none. Read pattern inside a body: `$this->context(Get{Agg}ListHandler::class, $this->handle({Agg}ListFilter::class, limit: 1, …))()->total()` (the answer is a `ReadResult`: `items()`, `total()`, `first()`, `isEmpty()`). Known limit: no locking between check and apply — back hard invariants with a database constraint.

