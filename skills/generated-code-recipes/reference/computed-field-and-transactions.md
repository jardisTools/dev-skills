#### Continuation §2 — Recipes 13–14: computed field, transaction bracket

**Recipe 13 — Write a computed field from a process node (`Set{Entity}Computed`)**

A column the process derives (a sum, a counter) is declared `computed: ["net_total", …]` on its entity in `Aggregate.json` (Designer field table switch "berechnet", MCP `save_aggregate` node field `computed`). Such a column is in **no** Create/Set/Add/Update command and in no OpenAPI request — a caller can neither send nor overwrite it (a body naming it is `400 unknown`); Read, list and Akte still carry it. The one write path is the generated, **family-internal** command `Set{Entity}Computed` (one per entity position with computed columns): its DTO is the entity's addressing path (root identifier; for an `erm: many` entity also the selector and the child identifier) plus exactly the computed columns, nothing else. It has no route, no OpenAPI path, no rule binding and no BC-facade method — the only way in is the kernel seam, from a Process node or Service:

```php
// {BC}/Process/{Name}/Command/Handler/Action/RecalculateTotals.php — node body is yours
$response = $this->handle(Offer::class)->setOfferComputed(     // the aggregate WRITE facade, family-internal
    new SetOfferComputed(                                       // {Domain}\{BC}\Model\Offer\Command\SetOfferComputed
        offerIdentifier: $offerIdentifier,
        netTotal: $net,                                         // only the computed columns
        grossTotal: $gross,
    )
);
return (new MapResponse())($response);   // 2xx → ok(reference payload), 4xx (404 missing aggregate, 409 stale write) → reject, the run ends
```

**Rules:** the command is applied through the position's regular aggregate method (root `set{Agg}`, `erm: one` `set{Sub}`, `erm: many` `update{Kind}`) — persist, change tracking and the stale-write 409 are exactly those of the regular write; a missing aggregate answers 404 like every loading command. Like every command it raises **exactly one event** (one event per command): its own `{Agg}ComputedUpdated` at the root, `{Agg}{Fragment}ComputedUpdated` below it — never the event of the position's regular Update/Set — collected by the handler (`EventScope::Internal`, not dispatched; the caller publishes after commit), registered in `{Agg}Events` and `{Agg}EventRouter`, carrying the entity's public fields after the write (children: identifier plus the computed fields). A `computed` column must be nullable or carry a DB default (`V-CALC-1`) — after Create it holds that null/default until this command writes it. A process **input** field named like a computed property is warned (`V-CALC-2`) and a Closure example using `command.<computed>` too (`V-CALC-3`): take the inputs the value is computed FROM, then write the result with this command. A `erm: one` entity that does not exist yet is created by the Set path with the computed values only — write the entity first. In the node catalogue the command is marked `internal` (no HTTP verb); it is a Command for the process classification (loads the aggregate, validated, identity chain).

**Recipe 14 — The process transaction bracket: default, 4xx rollback, events after commit, opt-out**

Every Process runs in **one** transaction by default — nothing to model. `runInTransaction` in the process definition is three-valued: key absent = bracket on, `false` = explicit opt-out (written to the file), `true` = valid but redundant. Every door shows the effective value (process load answer / MCP resource `runsInTransaction`); the Designer switch and `update_process_metadata` write the key only when you touch it, `false` only as an explicit opt-out.

| Run ends with | What happens |
|---|---|
| Success (status < 400) | one `commit` at the end; the domain events collected by the nodes enter the answer **after** the commit |
| **4xx END status** (400/404/409/422, also a declared reject terminal) | the whole run **rolls back**, the answer stays the 4xx — and carries **no domain event**, not even those of nodes that had succeeded before |
| Thrown technical error / 5xx / failing `commit` | rollback, `500`, no event |
| Nested (sub-process, or a node calling `process()` of another BC inside the caller's bracket) | a **savepoint** with a per-run unique name instead of a second transaction; a rolled-back nested part delivers no events to the outer answer, a caller that carries on after the rejection harvests only the events of the part that was not rolled back |

- **A route is a branch, a `reject` is an abort.** The `Route::OnFalse` path may write; only the **END status** of the run decides rollback — a 4xx `reject` or a technical failure rolls the whole run back. Do not model a manual rollback.
- **One connection:** all BCs of a domain write through the same writer, so a Cross-BC write node (Recipe 9) is part of the caller's bracket — no saga.
- **Events (Wurzel 3 unchanged):** the handler collects, nothing is dispatched; the caller publishes the events of the answer after the commit. With the opt-out there is no bracket, nothing is rolled back and the events are harvested unconditionally.
- **Opt-out** `runInTransaction: false` is for a process that must keep partial writes on purpose; the build warns once per process (`V-PROC-TX-OPTOUT`, a warning, never a blocker).
- **Floor:** the savepoints come from the packages (`jardissupport/contracts` ≥ 2.4.0 `SavepointInterface`, `jardissupport/repository` ≥ 1.4.0, `jardisadapter/dbconnection` ≥ 1.2.0) — no raw `SAVEPOINT` SQL in your Dev code either.

