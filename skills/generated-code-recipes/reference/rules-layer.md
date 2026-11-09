#### Continuation §2 — Recipe 10: guard a Command with a Rule

**Recipe 10 — Guard a Command with a business Rule (Rules-Layer)**

A Rule is a synchronous, endpoint-bound yes/no guard — for an existing-data check that must run before a Command, not for anything multi-step or side-effecting (that stays a Process). Declared in `Closures.json` (BC-level, sibling of Process/): a catalog entry (name, optional Policy reference) plus a binding (which Command, ordered chain; whether the command is reachable from outside is the stance of its row in `Doors.json`, not a switch of the binding).

**Where the catalog entry comes from.** You author it in the Closure-Editor (`…/closures/{name}/{closure|code}`, reached from the model list's "Neu ▾" (New) or the "Closure andocken ▾" (attach Closure) guard-chain menu on the Aggregate's "API" tab) or headless via MCP `save_closures` — never by hand-editing `Closures.json`. Before writing `__invoke()`, pull the ready-composed work package: the MCP Resource template `jardis://closures/{domain}/{subdomain}/{bc}/{name}/work` (same JSON over `GET /api/closures/{domain}/{subdomain}/{bc}/{name}/work`) hands you the free-text task, the contract/signature, `uses`/`reads` call recipes, the path of the test you write from `examples[]` (`files.test`, with `testExists` and `runTest`), and `body: "offen"`/`"geschrieben"` telling you whether the stub still throws `Not implemented`.

```php
// {BC}/Closure/CounterMustBeActive.php — DeveloperOwned, tag RuleClass
final class CounterMustBeActive extends MeterDeviceContext
{
    public function __invoke(UpdateCounter $cmd): RuleResult
    {
        /** @var Counter $counter */
        $counter = $this->handle(Counter::class);   // own BC only (M9: never a foreign BC) — here via the read facade; context() works too
        $read = $counter->counter()->getCounterByIdentifier(
            $this->handle(CounterByIdentifier::class, identifier: $cmd->identifier)
        );

        $record = $read->getData();   // root fields flat (children would be {Child}Response objects, read with ->); [] when the counter does not exist
        if ($record === [] || ($record['status'] ?? null) !== 'active') {
            return RuleResult::reject(
                rule: self::class,
                messageKey: 'counter.must_be_active',   // declare the same key on the catalog entry — the catalog key is what the 422 carries
                context: ['identifier' => $cmd->identifier],
            );
        }

        return RuleResult::pass();
    }
}
```

**What's generated, what's yours:** the Generator emits the stub signature (the verdict class `RuleResult` is the package's `JardisSupport\Response\Data\RuleResult`, imported by the stub header) + a hermetic `Closure/Guard/GuardUpdateCounter.php` that runs the bound chain (AND, short-circuit) from inside the generated `UpdateCounterHandler` — you never call the Guard yourself, and you never wire the rejection into a response: a chain rejection surfaces as `ResponseStatus::RuleViolation` (422) with `{rule, messageKey, context}` automatically. When the catalog entry declares a `messageKey`, the generated Guard (and the generated Process Rule node) sets that key as a literal and the `messageKey` you pass to `reject()` is not taken over — the body only decides passed/rejected and supplies `context` (the work package's `output.rejectNote` says so); declare the key in the catalog and pass the same key in the body. Without a catalog `messageKey` the body's key passes through. Your only job is the `__invoke()` body above.

**Rule as a Process node:** the same catalog entry can additionally be dropped as a node in the Process Designer — a catalog reference (matrix-ineligible, like a sub-process node), the generated node is hermetic: `passed` takes `Route::OnTrue`, a violation is `NodeResult::reject(ResponseStatus::RuleViolation, …)` (422, the run ends — `ruleMode` `guard`, the default) or, with `ruleMode: decision`, a `Route::OnFalse` the process goes on over (a guard rule node declares no `onFalse` edge, `V-NODE-GUARD-ONFALSE`). This is for an **early** check in a flow (before expensive work), not a replacement for the endpoint chain — binding the same Rule both at the endpoint and as a node in a process that calls that endpoint is flagged (M7, a build-time warning, not an Error: possible double-execution / inconsistent existing-data reads between the two runs).
