---
name: generated-code-workflow-api
description: Workflow-Engine API used by Process-Designer-generated Use-Case orchestrators — seven routing statuses (`ON_SUCCESS` / `ON_FAIL` / `ON_TIMEOUT` / `ON_SKIP` / `ON_CANCEL` / `ON_EVENT` / `ON_EXIT`), `WorkflowConfig`/`addNode` graph construction, the Event node ◇ variant, `handlerFactory` Closure conventions, three opaque `WorkflowContext` slots (`reference`, `response`, `exception`), R5 routing-safety rules.
zone: post-active
persona: C
profile: jardis
prerequisites: [generated-code-extend]
next: []
---

The Process-Designer-Orchestrator (`<Name>Handler.php`, generated under `{BC}/Process/{Name}/`) wires a `WorkflowConfig` and calls `$workflow($config, $dto)`; the Engine walks the graph, invokes each Node-Action-Stub `__invoke(WorkflowContextInterface): WorkflowResultInterface`, and stamps every result with its producing handler FQCN. Knowing this API is mandatory both for Node bodies (whose editable `logic()` returns an `array{status, data}` that the generated `__invoke` wraps into a `WorkflowResult`) and for hand-written orchestrator-shell code.

### 1. The seven routing statuses

The editable node body is `protected function logic($cmd, WorkflowContextInterface $context): array` and returns exactly one of these as `['status' => WorkflowResult::ON_*, 'data' => [...]]` (same shape as the Event node below); the generated `__invoke` wraps that into a `WorkflowResult` automatically — never construct `WorkflowResult` inside the `logic()` body:

| Constant | Meaning |
|---|---|
| `ON_SUCCESS` | Successful completion of the handler — default happy path. |
| `ON_FAIL` | Business failure (validation, business rule violated). |
| `ON_TIMEOUT` | Planned recovery path: service-side timeout translated into business routing. |
| `ON_SKIP` | Handler not applicable — flow skips to the re-convergence point. |
| `ON_CANCEL` | Business abort (cancellation, consent withdrawn) — cleanup path. |
| `ON_EVENT` | Active async hand-off via DomainEvent — follow-up runs arise externally (no synchronous follow-up node). |
| `ON_EXIT` | Loop/block termination — actively ends a loop or a block (no synchronous follow-up node). |

User code always leaves `handlerFqcn` as `null` — the engine stamps it itself via `WorkflowResult::withHandler()` as soon as it appends the result to the context.

### 2. `WorkflowConfig` in the `config()` body

The generator emits a private `config(): WorkflowConfigInterface` that registers **every** node of the drawn graph via `addNode()` — even an end node appears as `addNode(X::class, [])`. Routing is a `[WorkflowResult::ON_* => NextNode::class]` map per node — the engine walks the drawn edges, exclusive branches run exclusively. Hand edits to the orchestrator (e.g. an additional transition) follow the same pattern:

```php
private function config(): WorkflowConfigInterface
{
    return (new WorkflowConfig())
        ->addNode(ValidateInput::class, [
            WorkflowResult::ON_SUCCESS => LoadAggregate::class,
            WorkflowResult::ON_FAIL    => RejectInput::class,
        ])
        ->addNode(LoadAggregate::class, [
            WorkflowResult::ON_SUCCESS => MutateState::class,
            WorkflowResult::ON_SKIP    => RejectInput::class,
        ])
        ->addNode(MutateState::class, [
            WorkflowResult::ON_SUCCESS => PersistAndEmit::class,
            WorkflowResult::ON_CANCEL  => CompensateState::class,
        ])
        ->addNode(PersistAndEmit::class, [])
        ->addNode(RejectInput::class, [])
        ->addNode(CompensateState::class, []);
}
```

End nodes (empty routing map `[]`) let the engine terminate properly.

### Event node ◇

A Designer node can be marked as **Event ◇** instead of **Action** (`mode: async`). In the Designer the author declares an **event field binding** on the node — a list `eventFields: [{label, source}]`, where each `source` points to a command field of the process input (`ProcessEventFieldEditor.svelte`, details tab of the ticket panel).

**There is no dev task on the generated node body** — the only author activity is the field binding **in the Designer**, not in code. Rules for binding (V-EVT-*): at least one binding, source must be identity-bearing, no name collision, union branches same chain depth. Publication after commit is the caller's concern (event transport recipes: `generated-code-recipes` §1).

### Rule-node

A Designer node can instead be marked **Rule** — it references one entry of the BC's own `Closures.json` catalog (`generated-code-extend`), never authoring predicate logic itself. Not every catalog entry qualifies: only a Closure that is **rule-node-capable** — `output.type: verdict` AND exactly one resolvable `command`/`aggregate` input (the same guard-bindable shape `dockable.ruleNode` checks) — can back a node; a freely composed, multi-input, or non-verdict Closure is a build-blocking `V-PROC-RULE-SUBJECT` finding regardless of what the node declares. Given a qualifying entry, the node must additionally declare `ruleSubject.payloadField`, naming exactly one field of the process's own input as the referenced Closure's subject: for a `command`-subject entry, a command-field whose `accepts:` list includes that Command; for an `aggregate`-subject entry, a scalar root-identifier field. A node with no `ruleSubject.payloadField`, or one naming a field that does not fit the entry's subject, is the same `V-PROC-RULE-SUBJECT` finding at every door (Designer, `build`, `validate`) — the generated adapter cannot invoke the Closure without it. Routing is fixed, not authored per node: `passed → ON_SUCCESS`, `rejected → ON_FAIL`.

### 3. handlerFactory closure

`new Workflow($factory)` optionally accepts `Closure(string $fqcn, mixed $data): object`. The convention in the aggregate context is `fn($cls, $data) => $this->context($cls, $data)`, so that every node gets a fresh BC with `$data` as payload — nodes read it via `$this->payload()`. With `$data === null`, `$this->handle($cls)` is the default, and the outer payload is retained. Without a factory the engine calls `new $fqcn()` (`$data` ignored) — usable for pure PHP code outside the aggregate context.

### 4. Three opaque context slots

`WorkflowContext` carries, besides the result chain, three free slots that are *not* inspected by routing:

| Slot | Getter / Setter | Usage |
|---|---|---|
| `reference` | `reference()` / `setReference(mixed)` | Out-of-band channel orchestrator → node (e.g. pass on a pre-resolved aggregate identity). |
| `response` | `response()` / `setResponse(mixed)` | Out-of-band channel node → orchestrator (e.g. store `DomainResponse` from the end node, instead of via `WorkflowResult.data`). |
| `exception` | `getException()` / `setException(\Throwable)` | Feed in a caught `Throwable` without interrupting the engine loop — cleanup nodes can read it out and carry it over into the response mapping layer. |

Slots are explicitly *not* meant for data flowing between sequential nodes — `WorkflowResult.data` and `WorkflowContext::getPrevious() / getLatest($fqcn) / getAll($fqcn) / getChain()` serve that purpose.

### 5. R5 — routing safety

The engine aborts without a throw if

1. the current handler has no transitions configured at all, **or**
2. no transition exists for the returned status, **or**
3. the configured transition target is not itself registered via `addNode()`.

In all three cases the caller receives the complete `WorkflowContext` back; the responsibility for "was that an intended end or a configuration error?" lies with the orchestrator shell (typically: `try/catch` + checking `$context->getException()` and `$context->getPrevious()`).

### 6. `responseStatus` and the status derivation at run end

The node body additionally puts `'responseStatus' => $response->getStatus()` into its
return map (next to `status`/`data`); both are generator emission, not engine behaviour.

This is a refinement of the three-level separation from §1: **branching** (`ON_SUCCESS`/`ON_FAIL`
= true/false) remains unchanged pure path selection; **response status** still always comes from the
actual `DomainResponse`, but from the last decisive execution of the node, not from the
edge declaration and not simply from the first or last chain member — a
convergent No terminal (several predecessor nodes lead into the same reject node) makes "last
chain member" structurally wrong, a healed retry of the same node makes "any earlier
4xx" structurally wrong; **transaction** remains: the No path keeps committing, only a
thrown technical error rolls back. Use case + measured values: `generated-code-recipes`
Recipe 11.

### Anchors

- `generated-code-extend` (Generated baseline, override targets, decision tree).
- `support-workflow` (the engine implementation itself).
- `generated-code-recipes` (Recipe 11 — Invariant as state, uses §6 of this file).
