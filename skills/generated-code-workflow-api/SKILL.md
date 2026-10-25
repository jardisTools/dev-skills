---
name: generated-code-workflow-api
description: Process door and engine API behind Process-Designer-generated use-case orchestrators (`workflow` 5.0 engine, `response` 3.0 door steps, `repository` transaction bracket) — generated handler as the door (`ValidateInput`, `RunProcess`, `RunOwnedTransaction`, `ProcessRunner`, `BuildResponseData`), node classes `extends {Domain}Context` with `__invoke({Name}Context): NodeResult`, six routes plus reject, `read()` / `handle()` / `tenant` and `user` of the process input inside a node, call, sub-process (`MapResponse`), rule (hermetic) and event nodes, typed responses, rule modes, strict routing, transaction bracket with savepoints, failure forms with envelope data and message keys, 5xx cause logged by the door.
zone: post-active
persona: C
profile: jardis
prerequisites: [generated-code-extend]
next: []
---

> Applies to `workflow` 5.0 (engine only, no session knowledge in its API) and `response` 3.0, and the generator of that release, whose handler is the door built from `response` and `repository` steps. Truth for the APIs: skills `support-workflow` (engine), `support-response` (door steps, failure forms, envelope data), `support-repository` (transaction bracket).

The Process-Designer handler (`<Name>Handler.php`, generated under `{BC}/Process/{Name}/`) is the **door** of the process: it checks the input (`ValidateInput`), calls the generated run step `RunProcess` (next to it; the required kernel resources, then the transaction bracket `RunOwnedTransaction` around `ProcessRunner::run($definition, $input, $nodeFactory, $depth)`) and answers through the `response` closures (`AnswerFindings`, `AnswerOutcome`, `AnswerThrowable`, `LogFailure`). The door itself computes nothing: it chains these calls and branches on what they hand back. The runner is the engine only: it walks the generated `ProcessDefinition`, builds each node through the factory, invokes it and answers with one `ProcessOutcome` (status, response object, errors, events, message key, context). It validates nothing, opens no transaction, answers no 5xx and knows no session: who calls is decided in front of the door by the route middleware (`RequireSession`, `RequireRight` of `core/app` 3.2, set in the generated `routes.php`); the door fills the fields `tenant` and `user` of the process input from the request attributes, a node reads them as `$ctx->input()->tenant` / `->user`. Handler and facades check no right themselves. Knowing this API is mandatory for node bodies and for `App/**` delivery code around the handler.

### 1. Where it lives

| File under `{BC}/Process/{Name}/` | Content | Owner |
|---|---|---|
| `Command/{Name}.php` (+ parts) | process input (readonly DTO) | generated |
| `Response/{Node}Response.php` | typed answer of a node; the answer of the end node IS the process answer | generated, hermetic |
| `Command/Handler/{Name}Handler.php` | the door: input check, requires, bracket, runner, events, answer | generated, hermetic |
| `Command/Handler/{Name}Definition.php`, `{Name}Validator.php`, `{Name}Context.php` | graph, input gate, typed run view | generated, hermetic |
| `Command/Handler/Action/` | one class per node | action / call / sub-process / event: generated stub with an `@node-id` marker, edits to the body are preserved on rebuild; rule: generated whole, hermetic |
| `Event/` | event classes | generated |

```
{BC}/
├── Closure/                          ← Closure/Rule bodies (hand code, §10 of generated-code-extend)
├── Service/                          ← Domain Services (hand code, the one location, §10 of generated-code-extend)
└── Process/{Name}/
    ├── Command/{Name}.php            ← input DTO (+ parts)
    ├── Command/Handler/              ← {Name}Handler, {Name}Definition, {Name}Validator, {Name}Context (hermetic)
    │   └── Action/{Node}.php         ← one class per node — the body of a node stub is hand code
    ├── Response/{Node}Response.php   ← typed node answers (hermetic)
    └── Event/                        ← event classes (hermetic)
```

Hand code stands only at the places of `generated-code-extend` §10 — the node bodies above, Closure/Rule bodies, `{BC}/Service/` and `App/**`; there is no other place under a process.

### 2. The six routes and the reject

A node returns a `NodeResult`; the route it takes picks the next node. The edge carries no data and no declaration.

| Route | Meaning |
|---|---|
| `Route::OnTrue` | Yes / success — the default path (`NodeResult::ok($data)` takes it) |
| `Route::OnFalse` | No — a decision answered no, or a rule in decision mode violated |
| `Route::OnSkip` | Node not applicable — flow skips to the re-convergence point |
| `Route::OnTimeout` | Planned recovery path: a service-side timeout translated into business routing |
| `Route::OnCancel` | Business abort (cancellation, consent withdrawn) — cleanup path |
| `Route::OnExit` | Loop/block termination — actively ends a loop or a block |

**There is no event route:** events hang on the result (`withEvents(...)`), they are not an edge.

**`reject` is not a route.** `NodeResult::reject(ResponseStatus::Conflict, 'lending.bookOnLoan', ['isbn' => $isbn])` ends the run at once: rollback, answer = the failure form of the status. Only 4xx is allowed (anything else throws `ConstructionException`). A thrown exception is a technical failure and answers 500.

### 3. The generated `ProcessDefinition`

The generator emits the graph as a fluent definition (hermetic, rebuilt on every build, never edited by hand):

```php
return ProcessDefinition::for(LendBook::class, 'LendBook')
    ->context(LendBookContext::class)
    ->transactional(true)
    ->validate(LendBookValidator::class)
    ->node(CheckAvailability::class)
    ->onTrue(CreateLoan::class)
    ->rejects(ResponseStatus::Conflict, 'lending.bookOnLoan')
    ->node(CreateLoan::class)->onTrue(NotifyMember::class)
    ->node(NotifyMember::class)->onTrue(LoanCreatedNode::class)
    ->node(LoanCreatedNode::class)->terminal(Route::OnTrue)
    ->build();
```

`transactional`, `validate` and `requires` are declarations for the door: the engine ignores them, the generated handler reads them (`getValidatorClass()`, `getRequires()`, `isTransactional()`). The first `node()` is the entry node. Every route a node can take must be declared — an undeclared route throws `UnroutedStatusException` (500) — and an end is declared with `terminal(...)` / `onX(null)`. `rejects(status, key)` declares the message key of a node's reject for that status. `response(Class, fields...)` types the answer of a node. `build()` rejects an unknown target and freezes the definition. `loopLimit(n)` caps the node steps of one run (default 100).

### 4. Node classes

Every node is a class `final class <Node> extends {Domain}Context` with NO constructor of its own and one entry point:

```php
public function __invoke(LendBookContext $ctx): NodeResult
```

`{Domain}Context` is the generated kernel seam; a node reaches the rest of the world through it:

| In a node body | Use |
|---|---|
| `$ctx->input()` | the typed process input |
| `$ctx->input()->tenant` / `->user` | tenant and bare user id of the signed-in user, filled by the door from the request attributes (fields of the process input, only in a world with login; `tenant` required (403) only when the process reaches a tenant-marked aggregate, `user` never); sub-process and call nodes hand them on; read them, never take them from a client field |
| `$ctx-><node>()` | typed getter per node, `?NodeResult` over `latest()` (also `latest($node)`, `all($node)`, `previous()`, `iteration($node)`, `chain()`, `depth()`) |
| `$this->read(Handler::class, $filter): ReadResult` | read through an own-BC query handler: `->first()`, `->items()`, `->total()`, `->isEmpty()`; a handler of another BC throws `LogicException` (M9) |
| `$this->handle(Class::class)` | any class through the kernel seam (aggregate write facade, closure, `Identity`, `SystemClock`); this is also how the factory builds nodes |

```php
$onLoan = $this->read(GetLoanListHandler::class, new LoanListFilter(isbn: $isbn, limit: 1))->first();

return $onLoan === null
    ? NodeResult::route(Route::OnTrue)
    : NodeResult::reject(ResponseStatus::Conflict, 'lending.bookOnLoan', ['isbn' => $isbn]);
```

The body returns exactly one `NodeResult`:

| Factory | Use |
|---|---|
| `NodeResult::ok($data)` | success on `OnTrue`, `$data` is an array or object |
| `NodeResult::route(Route $route, $data = null)` | take a named route; `null` data means a result without data — nothing is handed on from the previous step |
| `NodeResult::reject(ResponseStatus $status, $messageKey, $context = [], $errors = [])` | end the run with a 4xx |
| `->withEvents(object ...$events)` | events travel with the result (replaces earlier events of the same result) |

There are no free slots; results sit in the chain and nothing is handed on from node to node: a node that needs an earlier answer reads it explicitly (`latest()` / `all()` / the typed getter), and the process answer is the data of the node the run ends on only. `{Name}Context` extends `ProcessContext` (the documented exception to composition over inheritance); the chain stays immutable.

**Typed answers.** `response(Class, fields...)` on a node turns the data array of the node into an object of `Class` through its constructor (named arguments; none named = all fields). A required parameter without a value is a `ConstructionException` (500).

### 5. Node kinds

**Call node, aggregate command.** `$this->handle(Loan::class)->createLoan(new CommandLoan(...))` returns a `DomainResponseInterface`; `(new MapResponse())($response)` maps it: 2xx becomes `ok(data)`, 4xx a `reject` with the callee's plain context, every other status throws.

**Sub-process node.** The stub carries the whole call; only the input mapping is yours (the node body). The node runs the inner process through the inner door (`$this->context(<Inner>Handler::class, <input>, depth: $ctx->depth() + 1)()`; the sub-input carries `tenant`/`user` from the outer input, filled by the generator) and hands the `DomainResponseInterface` it answers to `MapResponse`:

```php
$response = $this->context(
    NotifyMemberHandler::class,
    new NotifyMember(...),
    depth: $ctx->depth() + 1
)();

return (new MapResponse())($response);
```

`MapResponse` (`JardisSupport\Workflow\Handler\Node\MapResponse`) is the one mapping, never map a response by hand: success is `ok((array) data)` with the Domain events of the inner run (its Internal events stay behind); a 4xx rejects again with the SAME status and the callee's plain context (so the outer wire form equals the inner one) or, when you pass a `Route` as second argument, routes there (a Dev decision); a 5xx throws a `CalleeFailureException` (the inner door has already reported the cause, the outer door does not log it again).

**Rule node.** Hermetic: the generator writes the whole body (it is rebuilt on every build, do not edit). It calls each rule of the chain through `$this->handle(Rule::class)(...)`, short-circuits, and on a violation returns `reject(ResponseStatus::RuleViolation, $messageKey, ['rule' => ..., ...context])` (422), else `route(OnTrue)`. `ruleMode($node, RuleMode::Guard)` (default) ends the run on a violation; `RuleMode::Decision` turns a 422 into `Route::OnFalse` and the process goes on there. Which Closure backs the node and how its inputs are bound is Designer territory (`generated-code-extend`, the Closure catalog).

**Event node.** A node that only announces something ends `NodeResult::route(Route::OnTrue)->withEvents(new <Event>(...))` as the generated event nodes do. The node routes without data of its own and nothing is handed on from earlier nodes (workflow 5.0), so a process that ends on an Event node answers with no fields of its own — on the 2xx wire `data` carries only the process `@type`, `{"@type":"<processName>"}` (wire-contract case `200-empty`); the identity the event carries is read explicitly from the earlier node (`$ctx-><node>()` / `$ctx->all(Node::class)`, throwing when it is missing). Events of all steps are reported in execution order; a rejected result and a failed run report none. **The package never publishes:** the door collects `$outcome->events()` (`addEvent($event, EventScope::Domain)`) and the caller publishes them after the commit (event transport recipes: `generated-code-recipes` §1).

### 6. The handler (door) and the `nodeFactory`

The generated handler is the only place that starts a run. It composes the steps of `response` and `repository` around the engine and orchestrates only (Gesetz P6): every step is a call onto a closure, the door branches on what a step hands back and has no loop, no status comparison, no `new`. No hand code is written into the door:

```php
$definition = ($this->handle(LendBookDefinition::class))();
$input = $this->payload();
$findings = ($this->handle(ValidateInput::class, validator: $validator))($input);   // only where the input carries a rule
if ($findings->failed()) { return ($this->handle(AnswerFindings::class))($this->result(), $this->transformer(), $findings); }   // 400
$run = ($this->handle(RunProcess::class))($definition, $input, $this->depth());   // OwnedResult
if ($run->failure !== null) {
    $answer = ($this->handle(AnswerThrowable::class))($this->result(), $this->transformer(), $run->failure, $run->owner, $definition->getName());
    ($this->handle(LogFailure::class, logger: $this->resource()->logger()))($run->failure, $definition->getName(), $run->owner, $answer);
    return $answer;
}
return ($this->handle(AnswerOutcome::class))($this->result(), $this->transformer(), $run->result, $definition->getName());
```

`RunProcess` (`Command/Handler/RunProcess.php`, generated, hermetic) is the step that runs: one line per `requires` name (`$this->resource()->auth() ?? throw new ConfigurationException(...)`, before the bracket), `ProcessRunner::run($definition, $input, fn(string $class): object => $this->handle($class), $depth)` wrapped in `RunOwnedTransaction` (package `repository`: savepoint `sp_{depth}`, nested above depth 0, failure read from the outcome), and the `OwnedResult` it hands back (outcome, who owned the transaction, the failure instead of a throw). One path for every process: the definition's switch goes into the bracket as an argument (`transactional: $definition->isTransactional()`); a process with `transactional(false)` runs without begin/commit, and the package answers owner and failure in the same `OwnedResult`. Where the answer has a temporal field, `RunProcess` projects the typed success onto its wire form (`ProjectWire`) inside the run and swaps it into the outcome with `ProcessOutcome::withResponse()` (a reject passes unchanged). The door gets the depth from itself and hands it to the step; tenant and user are no argument of the step or of the runner (they ride in the process input). `handle()` starts a closure with the payload and the version of the run only.

The complete door with every line is the Quick Start of the `response` README. `run()` takes a `\Closure(class-string): object`. The runner asks it for each node when the node is due, by the class name the definition declares — also once per pass of a loop — and checks the object is callable. The generated factory is `$this->handle($class)`, so the node is built through the kernel seam and class-version resolution of nodes is a property of that seam (`generated-code-versioning`). `$depth` is `$this->depth()`: 0 for a top-level run, the depth of the calling run plus one for a sub-process.

### 7. Answer and failure forms

| Outcome | Status | Response |
|---|---|---|
| run ends on a routed node | 200 | the data of the last node (typed when `response()` is declared) |
| input invalid (door, before the first node) | 400 | the validation form with `fields[]` (`field`, `reason`, `message`, `messageKey` `validation.missing` / `validation.invalid`), form key `validation` |
| no session (app with a login; `RequireSession` of `core/app` answers it before the domain code) | 401 | empty data |
| right missing / required request attribute missing (route middleware `RequireRight` / `RequireRequestAttribute`, fail closed, before the door) | 403 | middleware envelope with empty data; `errors.messageKey` `access.rightMissing` (`context.right`) or `request.attributeMissing` (`context.attribute`) |
| `reject(404/409/422)` | same | `NotFound` / `Conflict` / `RuleViolation` |
| other `reject(4xx)` | same | `{messageKey, context}` |
| thrown exception, unrouted route, loop limit (door: `MapThrowable`) | 500 | empty object; the cause is logged once by the owner |
| `TransientConflictException` in the owning run | 409 | key `process.transientConflict`, reason `stale_write` |

A reject is never routed by the engine; only a Dev decision inside a node (for example a sub-process node with a `Route`) may map a 4xx to a route.

**Envelope data.** `BuildResponseData` (package `response`) is the one place an answer becomes the envelope's `data`: 2xx `@type` (process name, first letter lower case) plus the public fields of the response; 400 `{@type: validation, fields}`; 404 `{@type: notFound, context}`; 409 `{@type: concurrencyConflict, reason, aggregate, context, violated}`; 422 `{@type: ruleViolation, rule, messageKey, context}`; other 4xx the data as given without `@type`; 5xx empty. Every `DateTimeInterface` in `data` goes out as an ATOM string.

**Message keys.** The key a node declares with `rejects(status, key)` (else the one it names in `reject(...)`) is `ProcessOutcome::messageKey()`, the door hands it to `BuildResponseData`, and it joins `data['messageKey']` of every status >= 400. The door's own keys (`process.inputInvalid`, `process.transientConflict`) stay out of `data`. `->input('customer')` on a node prefixes the `fields[].field` of that node's 400 reject with `customer.`; the start validation already answers paths below the input root (`items[1].qty`).

**5xx cause.** The door logs it as its own step: `LogFailure` (owner and 5xx decide) calls `LogCause`, which writes the cause once to the kernel logger (`error`, context `exception` and `process`, owning run and 5xx only; no logger = nothing; a cause marked `ReportedFailureExceptionInterface`, e.g. from `MapResponse`, is skipped). A node does not log a failure and does not catch an exception just to log it — it lets it fly.

### 8. Transaction and sub-process

`RunProcess` opens ONE transaction around the run through `RunOwnedTransaction` (package `repository`, built on `RunInTransaction`; `transactional(false)` opts out: the same call, the bracket runs the work bare); an answer >= 400 or an exception rolls it back, events are reported only for a successful run. Inside an open transaction a run works in a savepoint `sp_{depth}` — the connection must implement `SavepointInterface`; the owner of the transaction is decided at the door's entry (no transaction open) and only the owner answers a 5xx and logs it. A sub-process node passes `depth + 1`; a rejected inner run rolls back to its savepoint and the outer node decides — reject again (the default of `MapResponse`) or route. Use case + measured values for the invariant-as-state pattern: `generated-code-recipes` Recipe 11.

### Anchors

- `generated-code-extend` (generated baseline, override targets, decision tree, Closure catalog).
- `support-workflow` (the engine implementation itself), `support-response` (door steps, failure forms, envelope data), `support-repository` (transaction bracket).
- `generated-code-recipes` (event transport, Recipe 11).
