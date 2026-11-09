### Station 3 — Handler (orchestrator)

Navigation: back to the index [`SKILL.md`](../SKILL.md) · the other stations are listed there.

The process door `{Name}Handler`: second check up front, resources, transaction, error mapping, answer. It calls and routes; it never computes.

## Guarantees

- **Fixed order of the process door** (`{Name}Handler::__invoke`, hermetic):
  1. `ValidateInput` answers 400 before any node runs and before the transaction opens.
  2. `RequireResource` / `CheckRequires` for every entry of `requires` (pre-conditions on the resources of the run).
  3. `RunOwnedTransaction` wraps `ProcessRunner::run`.
  4. On failure `AnswerThrowable` plus `LogFailure`, otherwise `AnswerOutcome`.
- **Step 1 runs at every door** of the process: the HTTP route, the BC facade `process()` and a sub-process node all call the same `{Name}Handler`. The input gate `{Name}Validator` exists only when the input carries a rule; without one, step 1 is absent. A read process (`kind: query`) has the gate for its paging range.
- **What step 1 checks.** Type and value list of each scalar input field, and the paging range of a read process. An `accepts:` command field is checked with the validator of that command (the gate registers it). A flat input field has only the obligation of presence (400 `missing`); a minimum length on it is a Rule (422). The column limits of a command (empty, length, format) are checked for flat fields only later, by the capsule in station 4. The 400 form is the one of station 2 (`fields[]`, closed `reason` vocabulary, path with the input property as prefix); no node `messageKey` appears in it.
- **The graph is data.** `definition()` in the handler builds the `ProcessDefinition` (nodes, routes, `answerFrom` with every end node as a named candidate) from the model; it is not hand-written.
- **The transaction is the default.** The owner is whoever finds no open transaction at entry. An end status of 400 or above rolls back and the answer stays that 4xx; a success commits. A nested run (sub-process, call from a node inside the caller's transaction) works in a savepoint. Every BC of a domain writes over the same connection, so a cross-BC write node joins the bracket. A process on a kernel without database answers 500 `process.connectionMissing` unless it opted out with `transactional(false)` (build warning).
- **The door has no domain logic and checks no session.** It wires package closures in a fixed order; failure mapping, logging and the answer shape belong to the packages `response` and `workflow`, not to a node.
- **A query door** `Get{Query}Handler` has no step 1 and no transaction: it loads through the repository and builds the list or single read answer.

## Never

- Open, commit, roll back or nest a transaction, set a savepoint, or call `begin` / `commit` in a body. The bracket belongs to the door; the run decides commit or rollback by its end status.
- Catch a throwable to build an answer, log a failure, or map an exception to a status. A 4xx is `NodeResult::reject(...)`, a technical error is thrown.
- Check a field of the input in a body: type, value list, presence, range, length or format. A form or type error is 400 before the first node; a command field is checked by that command's validator (up front with `accepts:`, otherwise at the capsule).
- Express a field-content check as a node 400 (own `reason`, own `messageKey`); outside the closed vocabulary it has no form.
- Create a validator, an entity, a DTO or a handler of the project namespace with `new` in a body; `new` bypasses class versioning and stand-ins. Obtain it through the kernel seam (`$this->handle(...)`); only PHP built-ins and package data classes are created with `new`.
- Write graph logic (loops, branching, ordering of nodes) in a handler. Order and branching are modelled in the process graph; a body only returns its own result (`ok`, `route`, `reject`).
- Add a rights or session check; station 1 is done.

## Variants

| Variant | Where it shows |
|---|---|
| Door with input gate | `{BC}/Process/{Name}/Command/Handler/{Name}Handler.php` with `{Name}Validator.php` beside it |
| Door without input gate (the input carries no rule): step 1 absent | `{BC}/Process/{Name}/Command/Handler/{Name}Handler.php` only |
| Gate with command registration (`accepts:`) | `{Name}Validator.php` registers the validator of the command in `{BC}/Model/{Agg}/Command/Validation/` |
| Door with `requires` | `RequireResource` step in `{Name}Handler.php`, before the transaction |
| Door without transaction (`transactional(false)`, warning `V-PROC-TX-OPTOUT`) | `{Name}Handler.php`: `->transactional(false)` in `definition()` |
| Query door: no step 1, no transaction | `{BC}/Model/{Agg}/Query/Handler/Get{Query}Handler.php` |
| Typed context of the run | `{BC}/Process/{Name}/Command/Handler/{Name}Context.php`, one typed getter per node |
