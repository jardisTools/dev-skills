#### Continuation §2 — Recipes 8–9: sub-process node, cross-BC write and read

**Recipe 8 — Sub-process node: calling another process synchronously**

A sub-process node is a **typed Dev-Stub** (`CreateIfNotExists` + `@node-id` body-preserve) — not a No-Op. The Generator emits the whole call frame on first build; subsequent builds preserve the developer body unchanged.

**Generated skeleton** (example: main process `CounterChange`, sub-process node `NotifyOps` calling `SendOpsNotification`):

```php
// {BC}/Process/CounterChange/Command/Handler/Action/NotifyOps.php
// @node-id 2a48bb04

public function __invoke(CounterChangeContext $ctx): NodeResult
{
    // Input mapping of the sub-process SendOpsNotification: the developer's to complete.
    /** @var DomainResponseInterface $response */
    $response = $this->context(
        SendOpsNotificationHandler::class,
        new SendOpsNotification(
            counterId: \call_user_func(static fn(): mixed => throw new \RuntimeException('Sub-DTO-Feld counterId fuellen: NotifyOps')),   // open place: the developer's
            reason: $ctx->input()->reason   // derivable (same name and type in the calling input): handed on
        ),
        depth: $ctx->depth() + 1
    )();

    return (new MapResponse())($response);
}
```

**What the developer does:** replace each open place (`Sub-DTO-Feld <field> <Node>`) with the real value from the surrounding run — `$ctx->input()->…`, or explicitly from an earlier node through its typed getter, `$ctx-><node>()?->…`. The inner call (`$this->context(<Inner>Handler::class, …, depth: $ctx->depth() + 1)()`) and the `MapResponseInto` mapping are generated — do not touch them, and never map a response by hand.

**What `MapResponse` does with the inner answer** (`JardisSupport\Workflow\Handler\Node\MapResponse`): success → `ok((array) data)` **carrying the Domain events of the inner run**, so they travel up to the outer answer (an inner run that was rolled back reports none; `Internal` events stay behind); a 4xx → a `reject` with the **same** status and the callee's plain context (so the outer wire form equals the inner one); a 5xx → throws a `CalleeFailureException` (the inner door has reported the cause). Events are part of the result, not of the data — there is no channel to fill.

**Routing instead of rejecting:** to carry on after a refused inner run, pass a `Route` as second argument (`(new MapResponse())($response, Route::OnFalse)`) — the node then routes without data, the inner savepoint is rolled back, and the outer run continues on that edge. That is a Dev decision; add the matching edge (here `onFalse`) in the Process Designer — an undeclared route is a 500 (`UnroutedStatusException`, `generated-code-workflow-api` §3). A business verdict (true/false) is data and is routed via a downstream decision node.


**Recipe 9 — Cross-BC write: translate → foreign door (process or command) → map response (G7)**

A Cross-BC-Call node whose target **mutates** state in a foreign BC targets a **door of that BC** whose row in the owner's `Doors.json` stands at `domain` or wider (same domain): its **process** (`process()->{process}()`) or a **command door** (direct BC-facade method `{lcfirst(command)}($dto)`) — never the write facade `{Agg}` of an aggregate, which is family-internal. `V-CONSUMED-EXISTS` checks the stance (Blocker when the door is missing, stands below `domain`, or lies in another domain). The Rules-Layer guards of a command bind to the command in every stance; the process rules of the owner apply only on the process path — pick the process when those must run. Reads stay on the foreign `{Agg}Read`. The generated Service (`{BC}/Service/<Name>.php` of the calling BC, `generated-code-extend` §1 and §10) is the ACL — it never passes the caller's DTO through unchanged.

```php
// {BC}/Service/<Name>.php — generated scaffolding, __invoke() body is yours
final class CheckStockInCatalog extends EcommerceContext
{
    public function __invoke(ProcessContext $context): NodeResult
    {
        /** @var CrossBcServiceDemo $cmd */
        $cmd = $context->input();

        /** @var Catalog $catalog */
        $catalog = $this->handle(Catalog::class);          // the foreign BC facade

        // 1) read the foreign current state via the foreign READ facade
        $read = $catalog->product()->getProductByIdentifier(
            $this->handle(ProductByIdentifier::class, identifier: $cmd->productIdentifier)
        );
        if (!$read->isSuccess() || $read->getData() === []) {
            return NodeResult::reject(ResponseStatus::NotFound, 'catalog.productNotFound', ['productIdentifier' => $cmd->productIdentifier]);   // 404, the run ends
        }
        $current = $read->getData();   // root fields flat: ['@type' => 'product', 'identifier' => …, …]; children are {Child}Response objects (->); [] when not found

        // 2) translate the own input into the foreign PROCESS input DTO (ACL) — an Update
        //    door takes the PATCH (UpdateProductPatch, `Absent` = not delivered = unchanged):
        //    name the address and the changed field only, never copy the rest of the current state
        $update = new UpdateProductInCatalog(new UpdateProductPatch(
            productIdentifier: $current['identifier'],
            price: $cmd->newPrice,
        ));

        // 3) write via a door of the foreign BC (here its process()) — never the foreign aggregate
        $response = $catalog->process()->updateProductInCatalog($update);

        // 4) map the response back into the caller's own vocabulary (ACL) — never
        //    pass the foreign DTO through unchanged
        $answer = ['productIdentifier' => $current['identifier'], 'newPrice' => $cmd->newPrice];

        return $response->isSuccess() ? NodeResult::ok($answer) : NodeResult::route(Route::OnFalse, $answer);
    }
}
```

**Cross-BC read: declare `crossBcCall`.** A node whose `consumedCalls` resolve into a foreign BC (its `{Agg}Read` methods, each a read door of the owner at `domain` or wider) is a Cross-BC-Call node on its own; declare `crossBcCall: {ServiceName}` on it so that one shared Service per target serves every node with that target (without the declaration the generator derives one shared Service per target, named from the target's facade segments after the domain prefix and its call key, e.g. `BillingInvoiceGetById`). The generated Action contains no `use` of the foreign BC and delegates to the Service, and the Service reads through the foreign read facade (step 1 above). A missing foreign record is your Service's decision: a `reject` (404), or a `Route::OnFalse` when the process should carry on. Rules keep reading only in their own BC (M9) — a cross-BC existing-data check is process territory.

**Declarations the body needs:** the `reject(ResponseStatus::NotFound, …)` status belongs in the node's `rejects[]` (`V-NODE-REJECT-UNDECLARED`), and the `Route::OnFalse` branch needs an `onFalse` edge on the node (`V-PROC-ROUTE-UNDECLARED` warns, an undeclared route is a 500 at run time).

**Rules:** the foreign BC facade itself (`$this->handle({TargetBC}::class)`) is fine to hold — `product()`/`process()`/the command doors are its own public surface, not an internal hop; a foreign aggregate, its write facade, its repository and SQL stay off-limits (V6). Same-BC writes stay on the kernel seam (Recipe 3 Case A) — this recipe is only for a write into a **different** BC.

**Dev-body 4xx failure form.** A dev body (custom, sub-process, cross-BC or external-call node) refuses with `NodeResult::reject(ResponseStatus, $messageKey, $context, $errors)` — only 4xx is allowed, a 5xx case is an exception, not a `reject()` (workflow 5.1, `generated-code-workflow-api` §2/§7). The door builds the failure form from the **context keys it knows** (`BuildFailureForm`, package `response`): 400 `validation` from `fields`, 404 `notFound`, 409 `concurrencyConflict` from `reason` / `aggregate` / `violated`, 422 `ruleViolation` from `rule`; every other context key travels as the `context` of the form, and a form field you do not name stays empty (nothing is filled from `errors` or the node name). Any other 4xx (401/403/405) has no form of its own and is passed through as `{messageKey, context}`. The message key a node declares for the status (`rejects(status, key)` on the node's exit) joins `data['messageKey']` of every status >= 400; without one the key named in `reject(...)` is used. The status must be declared in the node's `rejects[]` (`V-NODE-REJECT-UNDECLARED`). So to answer 404 or 422 from a cross-BC service, `reject` with that status and the form's context keys, not free-form business keys. **Contract side:** a Process door declares 422 `ruleViolation` in its OpenAPI whenever a Rule or a dev body of its **own** BC is reachable (a Custom/Query node with a body, also through sub-processes) — with an own-BC dev body reachable the `messageKey` is an open string (no enum), with only Rules reachable it stays the enum of the Rule keys; a cross-BC or external call alone declares no 422 in the contract (it still passes through at runtime). `responses[]` never carries the 422 itself.

