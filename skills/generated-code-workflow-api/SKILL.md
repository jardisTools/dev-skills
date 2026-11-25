---
name: generated-code-workflow-api
description: Process door and engine API behind Process-Designer-generated use-case orchestrators (workflow 5.4, response 4.5, repository transaction bracket). Use when working with generated process handlers, node classes, NodeResult routes, answerFrom/@outcome, or failure forms.
zone: post-active
persona: C
profile: jardis
prerequisites: [generated-code-extend]
next: []
---

This skill is an index; the engine and door API is split by topic into `reference/` leaves (§4–§8). Read exactly the leaf whose question matches your task, not all of them.

## Triggers

Process door and engine API behind Process-Designer-generated use-case orchestrators (`workflow` 5.4 engine, `response` 4.5 door steps, `repository` transaction bracket) — generated handler as the one door class (`ValidateInput`, `RunOwnedTransaction`, `ProcessRunner`, `definition()` with `answerFrom([Node::class => 'outcome', …])`, `AnswerOutcome`, the answer field `@outcome`), the typed run view `{Name}Context` with one getter per node, node classes `extends {Domain}Context` with `__invoke({Name}Context): NodeResult`, six routes plus reject, `read()` / `handle()` / `tenant` and `user` of the process input inside a node, call (`MapResponseInto`), external call to another system (`DecodeAnswer`, `MapExternalResponse`), sub-process (`MapResponse`/`MapResponseInto`), rule (hermetic) and event nodes, the object value of `gives`, rule modes, strict routing, transaction bracket with savepoints, failure forms with envelope data and message keys, 5xx cause logged by the door.

> Applies to `workflow` >= 5.4 (engine only, no session knowledge in its API) and `response` >= 4.5 — the package floors the current generator checks — and to that generator, whose handler is the door built from `response` and `repository` steps. Truth for the APIs: skills `support-workflow` (engine), `support-response` (door steps, failure forms, envelope data), `support-repository` (transaction bracket).

The Process-Designer handler (`<Name>Handler.php`, generated under `{BC}/Process/{Name}/`) is the **door** of the process: it checks the input (`ValidateInput`, only where the input carries a rule), checks the required kernel resources (`RequireResource`, one line per `requires` name), opens the transaction bracket `RunOwnedTransaction` around `ProcessRunner::run($definition, $input, $nodeFactory, $depth)` and answers through the `response` closures (`AnswerFindings`, `AnswerOutcome`, `AnswerThrowable`, `LogFailure`). It is ONE class: the graph is its private method `definition()`, there is no run-step class and no definition class next to it. The door itself computes nothing: it chains these calls and branches on what they hand back. The runner is the engine only: it walks the `ProcessDefinition`, builds each node through the factory, invokes it and answers with one `ProcessOutcome` (status, response object, errors, events, message key, context). It validates nothing, opens no transaction, answers no 5xx and knows no session: who calls is decided in front of the door by the route middleware (`RequireSession`, `RequireRight` of `core/app` >= 3.0, set in the generated `routes.php`); the door fills the fields `tenant` and `user` of the process input from the request attributes, a node reads them as `$ctx->input()->tenant` / `->user`. Handler and facades check no right themselves. Knowing this API is mandatory for node bodies and for `App/**` delivery code around the handler.

### 1. Where it lives

| File under `{BC}/Process/{Name}/` | Content | Owner |
|---|---|---|
| `Command/{Name}.php` (+ parts) | process input (readonly DTO) | generated |
| `Command/Handler/{Name}Handler.php` | the door: input check, requires, bracket, runner, events, answer; the graph as `definition()` | generated, hermetic |
| `Command/Handler/{Name}Context.php` | typed run view: `input()` and one typed getter per node | generated, hermetic |
| `Command/Handler/{Name}Validator.php` | input gate (only emitted when it carries a rule) | generated, hermetic |
| `Command/Handler/{Name}{Name}.php` (e.g. `EndsOnFieldsSummary`) | object value of a node whose `gives` is `value` with fields: `final readonly`, one constructor property per field | generated, hermetic |
| `Command/Handler/Action/` | one class per node | action / call / sub-process / event: generated stub with an `@node-id` marker, edits to the body are preserved on rebuild; rule: generated whole, hermetic |
| `Event/` | event classes | generated |

```
{BC}/
├── Closure/                          ← Closure/Rule bodies (hand code, §10 of generated-code-extend)
├── Service/                          ← Domain Services (hand code, the one location, §10 of generated-code-extend)
└── Process/{Name}/
    ├── Command/{Name}.php            ← input DTO (+ parts)
    ├── Command/Handler/              ← {Name}Handler, {Name}Context, {Name}Validator (if any), object values (hermetic)
    │   └── Action/{Node}.php         ← one class per node — the body of a node stub is hand code
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

The generator emits the graph as a fluent definition in the private method `definition()` of the door (hermetic, rebuilt on every build, never edited by hand):

```php
return ProcessDefinition::for(LendBook::class, 'LendBook')
    ->context(LendBookContext::class)
    ->transactional(true)
    ->node(CheckAvailability::class)
    ->onTrue(CreateLoan::class)
    ->rejects(ResponseStatus::Conflict, 'lending.bookOnLoan')
    ->node(CreateLoan::class)->onTrue(NotifyMember::class)
    ->node(NotifyMember::class)->onTrue(LoanCreatedNode::class)
    ->node(LoanCreatedNode::class)->terminal(Route::OnTrue)
    ->answerFrom([LoanCreatedNode::class => 'loanCreated'])
    ->build();
```

`transactional` is a declaration for the door: the engine ignores it, the generated handler reads it (`isTransactional()`); the input gate and the `requires` lines are written into the door itself, not into the definition. `answerFrom([...])` names where the success answer comes from — ONE entry listing all end nodes of the model as named candidates (`Node::class => 'outcome'`, the name is the `gives.name` of that end node; the list form without names is the 5.1 form, mixing both throws `ConstructionException`); the package takes the one that ran last and marks its name as `@outcome` (`MarkOutcome`, §7). The first `node()` is the entry node. Every route a node can take must be declared — an undeclared route throws `UnroutedStatusException` (500) — and an end is declared with `terminal(...)` / `onX(null)`. `rejects(status, key)` declares the message key of a node's reject for that status; several keys declared for one status are an allow list (the key the reject carries stands when it is among them, otherwise the first declared one answers). `build()` rejects an unknown target and freezes the definition. `loopLimit(n)` caps the node steps of one run (default 100).

### Navigation

Navigation: one leaf per topic under `reference/` (§4–§8). Pick the row whose question fits the task.

| Question the leaf answers | Leaf |
| --- | --- |
| How is a node class written — the `__invoke` signature, the typed `{Name}Context`, `NodeResult`, `gives`, object values? (§4) | reference/node-classes.md |
| What do the call, read, sub-process, external-call, rule and event nodes look like in the node body? (§5) | reference/node-kinds.md |
| What does the generated handler (the door) do step by step, and how is the `nodeFactory` built? (§6) | reference/handler-and-node-factory.md |
| Which status, `@type`, `@outcome` and failure form does each way out of a run answer with? (§7) | reference/answer-and-failure-forms.md |
| How does the transaction bracket behave, and what changes in a nested sub-process run? (§8) | reference/transaction-and-subprocess.md |

### Anchors

- `generated-code-extend` (generated baseline, override targets, decision tree, Closure catalog).
- `support-workflow` (the engine implementation itself), `support-response` (door steps, failure forms, envelope data), `support-repository` (transaction bracket).
- `generated-code-recipes` (event transport, Recipe 11).

### Zitate §N (citation targets)

- §1 → „1. Where it lives“ (this index)
- §2 → „2. The six routes and the reject“ (this index)
- §3 → „3. The generated `ProcessDefinition`“ (this index)
- §4 → „4. Node classes“ (node-classes)
- §5 → „5. Node kinds“ (node-kinds)
- §6 → „6. The handler (door) and the `nodeFactory`“ (handler-and-node-factory)
- §7 → „7. Answer and failure forms“ (answer-and-failure-forms)
- §8 → „8. Transaction and sub-process“ (transaction-and-subprocess)
