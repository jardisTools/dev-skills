---
name: generated-code-workflow-api
description: Process-engine API used by Process-Designer-generated use-case orchestrators (`workflow` 2.0) — `ProcessRunner::run()` with a generated `ProcessDefinition`, six named routes plus reject, the node contract `callable(ProcessContext): NodeResult`, `nodeFactory` conventions, `ProcessContext` reads, typed per-node responses, rule modes, events on the result, strict routing, transaction bracket with savepoints.
zone: post-active
persona: C
profile: jardis
prerequisites: [generated-code-extend]
next: []
---

> Applies from `workflow` 2.0 (release after E3). Engine package itself: skill `support-workflow`.

The Process-Designer-Orchestrator (`<Name>Handler.php`, generated under `{BC}/Process/{Name}/`) calls `ProcessRunner::run($definition, $input, $actor, $nodeFactory)`. The runner validates the input, opens the transaction, walks the generated `ProcessDefinition`, invokes each node stub `__invoke(ProcessContext): NodeResult` and answers with one `ProcessOutcome` (status, response object, errors, events). Knowing this API is mandatory both for node bodies (Dev code) and for hand-written orchestrator-shell code.

### 1. Where it lives (target layout, decided 2026-10-17)

| File under `{BC}/Process/{Name}/` | Content | Owner |
|---|---|---|
| `Command/{Name}.php` (+ parts) | process input | generated |
| `Response/{Node}Response.php` | typed answer of a node; the answer of the end node IS the process answer | generated, hermetic |
| `Command/Handler/{Name}Handler.php`, `{Name}Definition.php`, `{Name}Context.php` | wiring: handler calls the runner, definition, typed context | generated, hermetic |
| `Command/Handler/Action/` | node stubs with the node body (dev-owned body) | Dev code |
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

**`reject` is not a route.** `NodeResult::reject(ResponseStatus::NotFound, 'order.unknown', $context)` ends the run at once: rollback, answer = the failure form of the status. Only 4xx is allowed (anything else throws `ConstructionException`). A thrown exception is a technical failure and answers 500.

### 3. The generated `ProcessDefinition`

The generator emits the graph as a fluent definition; hand edits follow the same pattern:

```php
ProcessDefinition::for(PlaceOrder::class, 'PlaceOrder')
    ->validate(PlaceOrderRules::class)->actor(Buyer::class)->context(PlaceOrderContext::class)
    ->node(ValidateStock::class)->onTrue(ReserveStock::class)->onFalse(RejectOrder::class)
    ->node(ReserveStock::class)->onTrue(ConfirmOrder::class)->response(ReserveStockResponse::class)
        ->rejects(ResponseStatus::Conflict, 'stock.taken')
    ->node(ConfirmOrder::class)->terminal()->response(ConfirmOrderResponse::class)
    ->node(RejectOrder::class)->terminal()
    ->build();
```

The first `node()` is the entry node. Every route a node can take must be declared — an undeclared route throws `UnroutedStatusException` (500) — and an end is declared with `terminal()` / `onX(null)`. `build()` rejects an unknown target and freezes the definition. `loopLimit(n)` caps the node steps of one run (default 100).

### 4. Node bodies: `NodeResult` and `ProcessContext`

The editable node body returns exactly one `NodeResult`:

| Factory | Use |
|---|---|
| `NodeResult::ok($data)` | success on `OnTrue`, `$data` is an array or object |
| `NodeResult::route(Route $route, $data = null)` | take a named route; `null` data hands on the previous step's data |
| `NodeResult::reject(ResponseStatus $status, $messageKey, $context = [], $errors = [])` | end the run with a 4xx |
| `->withEvents(object ...$events)` | events travel with the result |

A node reads the run through the `ProcessContext` it gets: `input()`, `actor()`, `previous()`, `latest($node)`, `all($node)`, `iteration($node)`, `chain()`, `depth()`. There are no free slots; data between nodes flows through the results in the chain, and the process answer is the data of the node the run ends on. The generated `{Name}Context` extends `ProcessContext` with typed getters over `latest()`/`all()` — the documented exception to composition over inheritance; the chain stays immutable.

**Typed answers.** `response(Class, fields...)` on a node turns the data array of the node into an object of `Class` through its constructor (named arguments; none named = all fields). A required parameter without a value is a `ConstructionException` (500).

**Call nodes** map the answer of a called operation with `MapResponse`: 2xx becomes `ok(data)`, 4xx a `reject`, every other status throws.

**Rule nodes.** A rule is a node that rejects with `ResponseStatus::RuleViolation` (422) or goes on. `ruleMode($node, RuleMode::Guard)` (default) ends the run on a violation; `RuleMode::Decision` turns a 422 into `Route::OnFalse` and the process goes on there. Which Closure backs the node and how its inputs are bound is Designer territory (`generated-code-extend`, the Closure catalog) — the engine only sees the node.

**Event nodes.** A node that only announces something returns `NodeResult::route(Route::OnTrue)->withEvents(new <Event>(...))`; its missing data hands on the previous step's answer. Events of all steps are reported in execution order, a rejected result and a failed run report none. **The package never publishes:** the caller publishes `$outcome->events()` after the run — the transaction is committed by then (event transport recipes: `generated-code-recipes` §1).

### 5. The `nodeFactory`

`run()` takes a `\Closure(class-string): callable(ProcessContext): NodeResult`. The runner asks it for each node when the node is due, by the class name the definition declares — also once per pass of a loop. The generated handler provides the factory; hand-written code returns any callable. Class-version resolution of nodes is a property of that factory (`generated-code-versioning`).

### 6. Answer and failure forms

| Outcome | Status | Response |
|---|---|---|
| run ends on a routed node | 200 | the data of the last node (typed when `response()` is declared) |
| input invalid | 400 | `ValidationFailure` with `fields[]`, key `process.inputInvalid` — before the first node |
| actor missing (`actor()` declared) | 401 | key `process.actorMissing` |
| `reject(404/409/422)` | same | `NotFound` / `Conflict` / `RuleViolation` |
| other `reject(4xx)` | same | `{messageKey, context}` |
| thrown exception, unrouted route, loop limit | 500 | empty object, `$outcome->cause()` |
| `TransientConflictException` in the owning run | 409 | key `process.transientConflict` |

A reject is never routed by the engine; only a Dev decision inside a node (for example a sub-process node) may map a 4xx to a route.

### 7. Transaction and sub-process

The run opens ONE transaction (`transactional(false)` opts out); an answer >= 400 or an exception rolls it back, events are reported only for a successful run. Inside an open transaction a run works in a savepoint `sp_{depth}` — the connection must implement `SavepointInterface`. A sub-process node calls `$runner->run($inner, $input, $actor, $innerNodes, $context)` with the surrounding context as parent; a rejected inner run rolls back to its savepoint and the outer node decides — reject again or route (`OnFalse`). Use case + measured values for the invariant-as-state pattern: `generated-code-recipes` Recipe 11.

### Anchors

- `generated-code-extend` (generated baseline, override targets, decision tree, Closure catalog).
- `support-workflow` (the engine implementation itself).
- `generated-code-recipes` (event transport, Recipe 11).
