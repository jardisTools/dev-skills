---
name: generated-code-workflow-api
description: Process-engine API behind Process-Designer-generated use-case orchestrators (`workflow` 3.0) — generated handler wiring (`ProcessRunner::forKernel`, `BuildResponseData`), node classes `extends {Domain}Context` with `__invoke({Name}Context): NodeResult`, six routes plus reject, `read()` / `handle()` / actor inside a node, call, sub-process (`MapOutcome`), rule (hermetic) and event nodes, typed responses, rule modes, strict routing, transaction bracket with savepoints, failure forms with envelope data and message keys, 5xx cause logged by the package.
zone: post-active
persona: C
profile: jardis
prerequisites: [generated-code-extend]
next: []
---

> Applies to `workflow` 3.0 and the generator of that release. Engine package itself: skill `support-workflow` (the truth for the API).

The Process-Designer handler (`<Name>Handler.php`, generated under `{BC}/Process/{Name}/`) calls `ProcessRunner::run($definition, $input, $actor, $nodeFactory)`. The runner validates the input, checks the actor, opens the transaction, walks the generated `ProcessDefinition`, builds each node through the factory and invokes it, and answers with one `ProcessOutcome` (status, response object, errors, events, cause). Knowing this API is mandatory for node bodies (Dev code) and for hand-written code around the handler.

### 1. Where it lives

| File under `{BC}/Process/{Name}/` | Content | Owner |
|---|---|---|
| `Command/{Name}.php` (+ parts) | process input (readonly DTO) | generated |
| `Response/{Node}Response.php` | typed answer of a node; the answer of the end node IS the process answer | generated, hermetic |
| `Command/Handler/{Name}Handler.php` | wiring: requires, runner, events, answer | generated, hermetic |
| `Command/Handler/{Name}Definition.php`, `{Name}Validator.php`, `{Name}Context.php`, `{Name}Actor.php` | graph, input gate, typed run view, request attributes (`{Name}Actor` only if the process reads some) | generated, hermetic |
| `Command/Handler/Action/` | one class per node | action / call / sub-process / event: generated stub with an `@node-id` marker, edits to the body are preserved on rebuild; rule: generated whole, hermetic |
| `Event/` | event classes | generated |
| `Query/`, `Repository/`, `Service/` | Dev territory | Dev code |

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

The generator emits the graph as a fluent definition (hermetic, rebuilt on every build); hand edits follow the same pattern:

```php
return ProcessDefinition::for(LendBook::class, 'LendBook')
    ->context(LendBookContext::class)
    ->transactional(true)
    ->validate(LendBookValidator::class)
    ->actor(LendBookActor::class)
    ->node(CheckAvailability::class)
    ->onTrue(CreateLoan::class)
    ->rejects(ResponseStatus::Conflict, 'lending.bookOnLoan')
    ->node(CreateLoan::class)->onTrue(NotifyMember::class)
    ->node(NotifyMember::class)->onTrue(LoanCreatedNode::class)
    ->node(LoanCreatedNode::class)->terminal(Route::OnTrue)
    ->build();
```

The first `node()` is the entry node. Every route a node can take must be declared — an undeclared route throws `UnroutedStatusException` (500) — and an end is declared with `terminal(...)` / `onX(null)`. `rejects(status, key)` declares the message key of a node's reject for that status. `response(Class, fields...)` types the answer of a node. `build()` rejects an unknown target and freezes the definition. `loopLimit(n)` caps the node steps of one run (default 100).

### 4. Node classes

Every node is a class `final class <Node> extends {Domain}Context` with NO constructor of its own and one entry point:

```php
public function __invoke(LendBookContext $ctx): NodeResult
```

`{Domain}Context` is the generated kernel seam; a node reaches the rest of the world through it:

| In a node body | Use |
|---|---|
| `$ctx->input()` | the typed process input |
| `$ctx->actor()` | the typed `{Name}Actor` (request attributes of the door); `$this->actor()` is the same object on the context seam, the handler hands it to the runner |
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

**Sub-process node.** The stub carries the whole call; only the input mapping is Dev territory:

```php
$runner = ProcessRunner::forKernel(
    $this->resource(),
    new ObjectValidator(registry: (new NotifyMemberValidator())(), fieldPaths: true)
);
$outcome = $runner->run((new NotifyMemberDefinition())(), new NotifyMemberInput(...), null,
    fn(string $class): object => $this->handle($class), $ctx);

return (new MapOutcome())($outcome, null, 'NotifyMember');
```

`MapOutcome` is the one mapping, never map an outcome by hand: success is `ok((array) response)` with all inner events; a 4xx rejects again with the SAME status and the inner failure form (so the outer wire form equals the inner one) or, when you pass a `Route` as second argument, routes there (a Dev decision); 5xx throws the inner `cause()`. The third argument names the inner process for the error message.

**Rule node.** Hermetic: the generator writes the whole body (it is rebuilt on every build, do not edit). It calls each rule of the chain through `$this->handle(Rule::class)(...)`, short-circuits, and on a violation returns `reject(ResponseStatus::RuleViolation, $messageKey, ['rule' => ..., ...context])` (422), else `route(OnTrue)`. `ruleMode($node, RuleMode::Guard)` (default) ends the run on a violation; `RuleMode::Decision` turns a 422 into `Route::OnFalse` and the process goes on there. Which Closure backs the node and how its inputs are bound is Designer territory (`generated-code-extend`, the Closure catalog).

**Event node.** A node that only announces something ends `NodeResult::route(Route::OnTrue)->withEvents(new <Event>(...))` as the generated event nodes do. The node routes without data of its own and nothing is handed on from earlier nodes (workflow 3.0), so a process that ends on an Event node answers with no fields of its own — on the 2xx wire `data` carries only the process `@type`, `{"@type":"<processName>"}` (wire-contract case `200-empty`); the identity the event carries is read explicitly from the earlier node (`$ctx-><node>()` / `$ctx->all(Node::class)`, throwing when it is missing). Events of all steps are reported in execution order; a rejected result and a failed run report none. **The package never publishes:** the handler collects `$outcome->events()` (`addEvent($event, EventScope::Domain)`) and the caller publishes them after the commit (event transport recipes: `generated-code-recipes` §1).

### 6. The handler and the `nodeFactory`

The generated handler is the only place that starts a run:

```php
$definition = (new LendBookDefinition())();
(new CheckRequires())($this->resource(), $definition->getRequires());
$runner = ProcessRunner::forKernel(
    $this->resource(),
    new ObjectValidator(registry: (new LendBookValidator())(), fieldPaths: true)
);
$outcome = $runner->run($definition, $this->payload(), $this->actor(), fn(string $class): object => $this->handle($class));
// events -> $this->result()->addEvent(...), errors -> addError(...)
$this->result()->setData((new BuildResponseData())($outcome, $definition->getName()));
return ($this->transformer())($this->result(), $outcome->status());
```

`run()` takes a `\Closure(class-string): object`. The runner asks it for each node when the node is due, by the class name the definition declares — also once per pass of a loop — and checks the object is callable. The generated factory is `$this->handle($class)`, so the node is built through the kernel seam and class-version resolution of nodes is a property of that seam (`generated-code-versioning`). `forKernel` resolves the transaction writer and the logger from the kernel.

### 7. Answer and failure forms

| Outcome | Status | Response |
|---|---|---|
| run ends on a routed node | 200 | the data of the last node (typed when `response()` is declared) |
| input invalid | 400 | the validation form with `fields[]` (`field`, `reason`, `message`, `messageKey` `validation.missing` / `validation.invalid`), form key `process.inputInvalid` — before the first node |
| actor missing (`actor()` declared) | 401 | key `process.actorMissing`, empty data |
| `reject(404/409/422)` | same | `NotFound` / `Conflict` / `RuleViolation` |
| other `reject(4xx)` | same | `{messageKey, context}` |
| thrown exception, unrouted route, loop limit | 500 | empty object, `$outcome->cause()` |
| `TransientConflictException` in the owning run | 409 | key `process.transientConflict` |

A reject is never routed by the engine; only a Dev decision inside a node (for example a sub-process node with a `Route`) may map a 4xx to a route.

**Envelope data.** `BuildResponseData` is the one place an answer becomes the envelope's `data`: 2xx `@type` (process name, first letter lower case) plus the public fields of the response; 400 `{@type: validation, fields}`; 404 `{@type: notFound, context}`; 409 `{@type: concurrencyConflict, reason, aggregate, context, violated}`; 422 `{@type: ruleViolation, rule, messageKey, context}`; other 4xx the data as given without `@type`; 5xx empty. Every `DateTimeInterface` in `data` goes out as an ATOM string.

**Message keys.** The key a node declares with `rejects(status, key)` (else the one it names in `reject(...)`) is `ProcessOutcome::declaredMessageKey()` and joins `data['messageKey']` of every status >= 400. The package's own keys (`process.inputInvalid`, `process.actorMissing`, `process.transientConflict`) stay out of `data`. `->input('customer')` on a node prefixes the `fields[].field` of that node's 400 reject with `customer.`; the start validation already answers paths below the input root (`items[1].qty`).

**5xx cause.** The package logs it: the outermost layer (`LogCause`) writes the cause once to the runner's logger (`error`, context `exception` and `process`, owning run and 5xx only; no logger = nothing). A node does not log a failure and does not catch an exception just to log it — it lets it fly.

### 8. Transaction and sub-process

The run opens ONE transaction (`transactional(false)` opts out); an answer >= 400 or an exception rolls it back, events are reported only for a successful run. Inside an open transaction a run works in a savepoint `sp_{depth}` — the connection must implement `SavepointInterface`. A sub-process node passes the surrounding context as parent; a rejected inner run rolls back to its savepoint and the outer node decides — reject again (the default of `MapOutcome`) or route. Use case + measured values for the invariant-as-state pattern: `generated-code-recipes` Recipe 11.

### Anchors

- `generated-code-extend` (generated baseline, override targets, decision tree, Closure catalog).
- `support-workflow` (the engine implementation itself).
- `generated-code-recipes` (event transport, Recipe 11).
