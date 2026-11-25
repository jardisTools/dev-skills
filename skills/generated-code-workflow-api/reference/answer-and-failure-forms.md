### 7. Answer and failure forms

| Outcome | Status | Response |
|---|---|---|
| run ends on a routed node | 200 | `@type` plus `@outcome` (the name of the end node that ran, from the candidates of the one `answerFrom` entry, always, also with a single end node) plus the fields of the `gives` of that end node (identifier: the `{Command}Response` of the end node; value: the members of its object value, a field with `ref` is a reference to a written aggregate, `list` a list; every exit has its own form); `gives: nothing` = `@type` and `@outcome` only. Outside the owner (`{BC}Process::{name}()`, cross-BC, HTTP) a `ref` field whose aggregate has no read door in the reader's world is left out (neighbour BC: door at `domain` or wider, HTTP: `outside` or wider) |
| input invalid (door, before the first node) | 400 | the validation form with `fields[]` (`field`, `reason`, `message`, `messageKey` `validation.missing` / `validation.invalid`), form key `validation` |
| no session (app with a login; `RequireSession` of `core/app` answers it before the domain code) | 401 | empty data |
| right missing / required request attribute missing (route middleware `RequireRight` / `RequireRequestAttribute`, fail closed, before the door) | 403 | middleware envelope with empty data; `errors.messageKey` `access.rightMissing` (`context.right`) or `request.attributeMissing` (`context.attribute`) |
| the process carries the statement `access` and the access question of the door refuses (once before the first node, before the transaction; only with groups, `null` asks nothing) | 404 / 403 | no sight: the same 404 `notFound` as an unknown key (`context` = `{<keyFrom>: value}`); level too low: the 403 `forbidden` of the domain (`data` = `{"@type":"forbidden","context"}`, `errors` empty). No node ran, nothing is written. A node itself never answers 403 |
| a generated `Share{Agg}` node is refused | 404 / 422 | target not resolvable: 404 with the key of the record (no body-field hint); the last entry with `all` would go: 422 `RuleViolation` from `acl` (the call from inside asks no sight question, so no 403). The bracket rolls back |
| `reject(404/409/422)` | same | `NotFound` / `Conflict` / `RuleViolation` |
| other `reject(4xx)` | same | `{messageKey, context}` |
| thrown exception, unrouted route, loop limit (door: `MapThrowable`) | 500 | empty object; the cause is logged once by the owner |
| `TransientConflictException` in the owning run | 409 | key `process.transientConflict`, reason `stale_write` |

**Example: a process with two outcomes.** `LendBook` ends on two nodes, `CreateLoan` (`gives: {kind: value, name: loanCreated, fields: [loanNumber]}`) and `AddToWaitlist` (`gives: {kind: value, name: waitlisted, fields: [position]}`). The door declares `->answerFrom([CreateLoan::class => 'loanCreated', AddToWaitlist::class => 'waitlisted'])`. The 200 `data` on the wire, one per outcome (`@type` stays the process name, `@outcome` is the `gives.name` of the end node that ran):

```json
{"status":200,"data":{"@type":"lendBook","@outcome":"loanCreated","loanNumber":"L-1001"},"errors":{},"meta":{ … }}
{"status":200,"data":{"@type":"lendBook","@outcome":"waitlisted","position":3},"errors":{},"meta":{ … }}
```

The OpenAPI states the two as `oneOf` with a `discriminator` on `@outcome`; a client switches on `@outcome`, never on which fields are present. The facade `{BC}Process::lendBook()` cuts the answer per outcome with `ProjectOutcome` and one `OutcomeForm` per outcome; an answer without `@outcome`, or an outcome the facade has no form for, is a technical failure (500, `ConfigurationException`).

A reject is never routed by the engine; only a Dev decision inside a node (for example a sub-process node with a `Route`) may map a 4xx to a route.

**Envelope data.** `BuildResponseData` (package `response`) is the one place an answer becomes the envelope's `data`: 2xx `@type` (process name, first letter lower case) plus the public fields of the response (a process answer carries `@outcome` as its first property, set by `MarkOutcome` of `workflow` 5.2); 400 `{@type: validation, fields}`; 404 `{@type: notFound, context}`; 409 `{@type: concurrencyConflict, reason, aggregate, context, violated}`; 422 `{@type: ruleViolation, rule, messageKey, context}`; other 4xx the data as given without `@type`; 5xx empty. Every `DateTimeInterface` in `data` goes out as an ATOM string.

**Message keys.** The key a node declares with `rejects(status, key)` (else the one it names in `reject(...)`; with several keys declared for one status the reject's own key stands when it is among them, otherwise the first declared one) is `ProcessOutcome::messageKey()`, the door hands it to `BuildResponseData`, and it joins `data['messageKey']` of every status >= 400. The door's own keys (`process.inputInvalid`, `process.transientConflict`) stay out of `data`. `->input('customer')` on a node prefixes the `fields[].field` of that node's 400 reject with `customer.`; the start validation already answers paths below the input root (`items[1].qty`).

**5xx cause.** The door logs it as its own step: `LogFailure` (owner and 5xx decide) calls `LogCause`, which writes the cause once to the kernel logger (`error`, context `exception` and `process`, owning run and 5xx only; no logger = nothing; a cause marked `ReportedFailureExceptionInterface`, e.g. from `MapResponse`, is skipped). A node does not log a failure and does not catch an exception just to log it — it lets it fly.

