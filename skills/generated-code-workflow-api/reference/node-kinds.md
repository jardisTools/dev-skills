### 5. Node kinds

**Call node, aggregate command.** `$this->handle(Loan::class)->createLoan(new CommandLoan(...))` returns a `DomainResponseInterface`; `(new MapResponseInto())($response, CreateLoanResponse::class)` maps it: 2xx becomes `ok(<typed object>)` (the `{Command}Response` the getter of this node returns), 4xx a `reject` with the callee's plain context, every other status throws. `MapResponse` is the same mapping without the typed object (`ok(array)`).

**Read node, one aggregate by its key.** A node whose task is to fetch an aggregate of the own BC, with the key at hand (process input or an earlier answer), reads the read base and not a declared query: the point read answers the whole aggregate with its children, and `MapResponseInto` hands it on as the `{Agg}Response` the getter of this node returns. A key without a record is a success with empty data; the 404 is the node's own (declared in `rejects`). Model: `reads: [{query: loanByLoanNumber}]`, `gives: {kind: aggregate, aggregate: Loan}`. A tenant-marked aggregate takes `tenant: $ctx->input()->tenant` as the first argument of the query class.

```php
$loanNumber = $ctx->input()->loanNumber;
$response = $this->context(
    GetLoanByLoanNumberHandler::class,
    $this->handle(LoanByLoanNumber::class, loanNumber: $loanNumber)
)();
if ($response->isSuccess() && $response->getData() === []) {
    return NodeResult::reject(ResponseStatus::NotFound, 'lending.loan.notFound', ['loanNumber' => $loanNumber]);
}

return (new MapResponseInto())($response, LoanResponse::class);
```

A declared query is the wrong means here. It is a paginated list of flat rows of its declared columns and never fills an aggregate, so `->first()` of it is a row: no children, not the type the getter returns. A query leads to an aggregate only when the key is not known: query, key of the row, read base.

**Sub-process node.** The stub carries the whole call; only the input mapping is yours (the node body). The node runs the inner process through the inner door (`$this->context(<Inner>Handler::class, <input>, depth: $ctx->depth() + 1)()`; the sub-input carries `tenant`/`user` from the outer input, filled by the generator) and hands the `DomainResponseInterface` it answers to `MapResponseInto` (the class is the object value the node `gives`, derived from the `gives` of the end nodes of the inner process) or, for an inner process without own fields, to `MapResponse`:

```php
$response = $this->context(
    NotifyMemberHandler::class,
    new NotifyMember(...),
    depth: $ctx->depth() + 1
)();

return (new MapResponseInto())($response, NotifyMemberResult::class);
```

`MapResponse` / `MapResponseInto` (`JardisSupport\Workflow\Handler\Node\`) is the one mapping, never map a response by hand: success is `ok(...)` (`MapResponse`: the data array; `MapResponseInto`: an object of the class you name) with the Domain events of the inner run (its Internal events stay behind); a 4xx rejects again with the SAME status and the callee's plain context (so the outer wire form equals the inner one) or, when you pass a `Route` as second argument, routes there (a Dev decision); a 5xx throws a `CalleeFailureException` (the inner door has already reported the cause, the outer door does not log it again).

**External-Call node (another system or another Jardis domain).** The Service body reads the reply with `DecodeAnswer` (package `response`) and maps it with `MapExternalResponse` (package `workflow`), never with `MapResponse`: 2xx, 400, 404, 409 and 422 are the answer of the node as for `MapResponse`; 401, 403, 405, every 5xx and every status outside the response form throw `ExternalFailureException`, which carries no `ReportedFailureExceptionInterface` marker, so the door logs it and answers 500. Recipe with the code: `generated-code-recipes` §2 Recipe 2.

**Rule node.** Hermetic: the generator writes the whole body (it is rebuilt on every build, do not edit). It calls each rule of the chain through `$this->handle(Rule::class)(...)`, short-circuits, and on a violation returns `reject(ResponseStatus::RuleViolation, $messageKey, ['rule' => ..., ...context])` (422), else `route(OnTrue)`. `ruleMode($node, RuleMode::Guard)` (default) ends the run on a violation; `RuleMode::Decision` turns a 422 into `Route::OnFalse` and the process goes on there. Which Closure backs the node and how its inputs are bound is Designer territory (`generated-code-extend`, the Closure catalog).

**Event node.** A node that only announces something ends `NodeResult::route(Route::OnTrue)->withEvents(new <Event>(...))` as the generated event nodes do. The node routes without data of its own (`gives: nothing`) and nothing is handed on from earlier nodes, so what a process answers is never read off an event node: the end node names it in its own `gives` (`identifier`, or `value` with `fields` — `gives: nothing` is the answer without fields of its own, on the 2xx wire `data` then carries only `@type` and `@outcome`, `{"@type":"<processName>","@outcome":"<gives.name>"}`, wire-contract case `200-empty`); the identity the event carries is read explicitly from the earlier node (`$ctx-><node>()` / `$ctx->all(Node::class)`, throwing when it is missing). Events of all steps are reported in execution order; a rejected result and a failed run report none. **The package never publishes:** the door collects `$outcome->events()` (`addEvent($event, EventScope::Domain)`) and the caller publishes them after the commit (event transport recipes: `generated-code-recipes` §1).

