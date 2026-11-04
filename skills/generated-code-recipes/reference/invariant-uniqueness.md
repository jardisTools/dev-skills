#### Continuation §2 — Recipes 11–12: invariant as state, duplicate on a unique key

**Recipe 11 — Invariant as state: securing a uniqueness invariant across process boundaries**

> Where a database UNIQUE index exists on the key, use Recipe 12 instead (typed `UniqueViolationException` → 409 `duplicate`, no gatekeeper node).

A decision node that checks for absence via a query ("is there already an invoice for this
order?") and writes afterwards is check-then-act — racy under concurrency, two
simultaneous runs can both pass the check. The replacement: uniqueness is not
checked but **modelled as the state of an existing row**, implemented by a conditional write
(CAS-UPDATE, the `generated-code-extend` Persist-Layer emits `$expected` = old values of the
changed fields automatically). Two simultaneous runs can never both win
— no new mechanism, no lock table, no DB-UNIQUE rule carrier.

**Basic rule:** the gatekeeper (the node with the conditional write) is the **first
writing node** of the process. Nothing unwanted may have been written before it — the
savepoint (see conflict cascade below) afterwards only secures the atomicity of the aborted
single persists, not the order.

**Case A — gatekeeper/status aggregate** (e.g. "one invoice per order"). A dedicated
status aggregate carries a flag (`fakturiert: bool`). The row is created **in advance** (e.g. via
domain event on "order delivered"), not only when invoicing itself — otherwise the
race returns as an initial-creation race. Process flow:

```
N1 "MarkInvoiced"          — conditional UPDATE fakturiert: false → true
   OnTrue → N2 "CreateCustomerInvoice"   (unconditional INSERT)
   lost CAS: the call answers 409, `MapResponse` turns it into a reject → the run ends with 409
             (declare the key with `rejects(Conflict, key)`; no edge to draw, a reject is not a route)
```

A convergent rejection — several functionally different predecessor nodes (not found / already
invoiced / validation) lead into the same reject-terminal node — is the **normal case**,
not a special case, and needs no handling of its own.

**Case B — number range/reservation with retry** (the system assigns the key). A small
counter aggregate (`lastNumber: int`):

```
N1 "ReserveInvoiceNumber"  — conditional UPDATE lastNumber: n → n+1
   OnTrue → N2 uses the new value (nothing is handed on: N2 reads it explicitly in its
            node body via `$ctx->latest(ReserveInvoiceNumber::class)?->data()` — dev surface)
   lost CAS: the call answers 409 → reject → the run ends with 409
```

**Handing the read basis to the write (Case B, and any gatekeeper whose target may be recomputed).** The gatekeeper node reads the row (`$current`), computes the target from it and passes what it read as the CAS expectation through the write facade of the own aggregate: `…->update{Root}($dto, $version, expected: ['<outerField>' => $current])` (outer FieldMap name ⇒ value read; family-internal only — the parameter is not on the BC facade, the Rules door, a route or the OpenAPI document; root writers `Update{Root}` / `Set{Root}Computed` only, child writers and `Remove` do not carry it). The generated handler lays the value over the snapshot (the package's `Hydration::hydrate`) after the guard and before the apply, so the UPDATE's `WHERE` checks the value the node actually read; a second writer that read the same value gets 0 rows → 409 `stale_write`. An unknown key in `expected` is a loud error (`InvalidArgumentException` → 500), not a silent no-op.

**Measured picture (20 concurrent workers, one number range, generated process).** MySQL, MariaDB and Postgres: 0 duplicate numbers (`COUNT(*) = COUNT(DISTINCT number)`), the count of 2xx answers equals the count of assigned numbers, every loser answers 409 `stale_write` with `violated: []`. All 20 workers reach a number **only with a client-side retry on 409** — the 409 is the normal loser answer, not a failure. The same run without `expected` assigns duplicates on Postgres.

**A transient database conflict is a 409 too, not a 500 (contracts ≥ v2.6.0, repository ≥ v1.6.0).** The repository package types a transient concurrency failure of the database — MariaDB ≥ 11.6 with `innodb_snapshot_isolation=1` (error 1020), deadlock (1213 / Postgres 40P01), serialization failure (40001) — as `JardisSupport\Contract\Repository\Exception\TransientConflictException`; the generate only catches the type. The loser answers 409 `stale_write` (every write path except delete) or `stale_delete` (delete persist) with `violated: []`, the same envelope as a lost CAS. **How it reaches your node depends on the database:** where the generated persist owns its transaction or can roll back its savepoint (Postgres, or a Command without a process bracket), the 409 arrives at the node as an ordinary answer: a call node's `MapResponse` turns it into a `reject` 409 that ends the run (the engine routes no reject); only a Dev node that inspects the response itself can map it to a route (`Route::OnFalse`) — the retry guard below. On MariaDB/MySQL the 1020/1213 rolls back the **whole** process transaction and the savepoint is gone — the conflict then does **not** arrive as an answer: the `TransientConflictException` is passed through unchanged by the Command handler and by the nodes, the process bracket (outermost owner) rolls back and answers 409 `stale_write` (`aggregate` = process name, `context` empty, no events); a node retry does not take place there. A nested run (sub-process, call from a node) never answers 409 itself; it rolls back its own savepoint if it can and rethrows to the outer owner. With `runInTransaction: false` the exception is thrown on. **The way to repeat is a new transaction — the client retries the whole request on 409 (or the caller starts a new process run); there is no server-side retry.** Dev code in a node that catches `\Throwable` and carries on swallows this type and would then write on after the transaction was destroyed — in autocommit, i.e. a half process; catch only the typed exceptions you translate, and let `TransientConflictException` pass.

**Retry is convenience, not correctness** and needs two obligations: an **upper bound**
(attempt counter via `$ctx->iteration($node)`; `loopLimit(n)` is only the coarse cap of the whole run, default 100) and — the documented defect of this
class — the target value MUST be **recomputed per attempt**, from the freshly read
`current` state. A hard-wired target value (`lastNumber: 1` on every attempt) produces on the
second attempt a no-op UPDATE whose `rowCount()` interpretation turns out
differently depending on the driver (MySQL accidentally reads it correctly as a conflict, Postgres wrongly as success → double
assignment).

**The give-up node must pass the gatekeeper's 409 on.** When the retry guard gives up after its upper bound, its terminal node ends the run with a `NodeResult::reject(ResponseStatus::Conflict, …)` that carries the gatekeeper's 409 (the `reason` and `aggregate` of the answer it kept). A give-up node that answers anything else does not end as the 409.

**Status behaviour at process end.** A gatekeeper conflict must become visible outwardly as 409,
not merely route internally. Without this refinement a healed retry wrongly reports 409 despite actual success
including the side effect (documented, Postgres number range, see below).

This is the three-level separation from `generated-code-workflow-api` §1: **branching** (`OnTrue`/`OnFalse`,
pure path selection) · **response status** (a `reject` carries its 4xx status and ends the run; a routed run
answers 200 — NEVER from an edge declaration) · **transaction** (the branch edge
is transaction-neutral; the Process runs in ONE transaction by default, and a 4xx END status — also
a declared 409 terminal — rolls the whole run back, as does a thrown technical error; the answer
of a rolled-back run carries no domain event).

**Collision sequence (Case A):** both runs load `fakturiert=false`. The first N1 wins. The
second N1 waits on the row lock, re-evaluates the current state after waiting (the UPDATE
is a current read), hits 0 rows → 409 → N2 is never reached. The 409 ends the run, so the bracket
rolls it back — the loser has written nothing, its answer is the 409 and carries no event.

**Limits:**

- **Only via processes in the bracket.** Every process runs in the bracket by default (Recipe 14); the direct BC path (Command without a process) and a process with the explicit opt-out `runInTransaction: false` stay unprotected.
- **SQLite:** without `busy_timeout` SQLite does not wait on the lock but throws
  `SQLITE_BUSY` immediately — the loser ends up via the Throwable path as a **500 with rollback**, not as
  409. The invariant holds (it writes nothing), but status and waiting behaviour are wrong.
  A known item of its own on the dbConnection adapter, not solved here.
- **Under MySQL/MariaDB `REPEATABLE READ` a retry structurally never heals within the same transaction** —
  every snapshot of the loser sees the same `current` as its first read, never the
  winner value committed in the meantime. Under Postgres `READ COMMITTED` the retry sees the committed state after
  waiting and can win (up to the retry bound). Both pictures are correct — a real
  driver delta, not a bug. The loser's answer is the 409; the way to a number is a **client retry in a new transaction**.
- **rowCount() trap as a warning:** the generated CAS persist layer checks success via
  `rowCount() > 0` — unreliable depending on the driver for no-op updates. Affects every gatekeeper whose target value
  may happen to match the actual state — just as relevant for Case A (bool flag) as for
  Case B (counter).

**Initial creation of the status row.** There is no event subscription inside the system — events are
outbound (§1 above), nothing in the generated code reacts to them. The status row in Case A is therefore
created by the process that completes the triggering step ("order delivered"): as a node in the same BC,
or across BCs through a Cross-BC call to a door of the owning BC (its `process()` or a command door at `domain` or wider). Rows for data that already exists
come from a fixture seed / a one-off migration step.

**Converting the real process.** An existing check-then-act decision node (reads via query
for absence) is replaced in the Designer, not built alongside it: model the status aggregate
(new table, a flag or counter field), put the gatekeeper node as the first writing node
in front of the previous write logic, remove the racy read check, declare the 409 edge
as terminal. The business pre-check ("delivered?", "does the order exist at all?") remains
in place as a read decision BEFORE the gatekeeper — only the uniqueness half moves into the
gatekeeper.

**Recipe 12 — Duplicate on a unique key → 409 `duplicate` (instead of a gatekeeper node)**

Where the database holds a UNIQUE index on the key (the aggregate's public unique key or any
unique column), the generated persist already turns a collision into the business answer — no
gatekeeper node, no check-then-act query. The repository package types the unique-constraint
hit as `JardisSupport\Contract\Repository\Exception\UniqueViolationException` (contracts ≥ v2.2.0,
repository ≥ v1.3.0); every generated Persist catches it per transaction bracket BEFORE the general
catch, rolls back (own transaction) or to its savepoint (open bracket), and throws
`ConcurrencyConflictException('duplicate', …)`. The Command/Process answers 409
`{"@type":"concurrencyConflict","reason":"duplicate","aggregate":"…","context":{<outer-door identifier>}}`;
an FK violation stays a technical 500. No driver knowledge in the generate, nothing to write.

- Use Recipe 11 (gatekeeper) only where NO database UNIQUE index can carry the invariant (a state over several rows, a counter, a status transition).
- Hand code never writes outside the generated persist (no own repository, no SQL): a write is an aggregate Command or a `process()` call, and its unique-key collision is the 409 `duplicate` described above.
- Keep the DB UNIQUE index in `Schema.json` — it is the only carrier; without it nothing throws and the duplicate is stored.
- **Concurrent losers are 409, not 500, also on a transient database conflict** (contracts ≥ v2.6.0, repository ≥ v1.6.0): a snapshot/serialization conflict or deadlock (MariaDB 1020 with `innodb_snapshot_isolation=1`, 1213, 40001, Postgres 40P01) is typed `TransientConflictException` and answers 409 `stale_write` / `stale_delete` with `violated: []` — see Recipe 11 Case B for how it reaches a process node (MariaDB/MySQL: the process bracket answers it, no node retry) and why the repeat is a client retry in a new transaction.

**The 409 names the violated fields (`violated`).** The `duplicate` payload carries one more key: `violated: list<string>` — the **outer** field names (FieldMap) of the unique columns that were hit, so a form can mark the field: `{"@type":"concurrencyConflict","reason":"duplicate","aggregate":"…","context":{…},"violated":["email"]}`. A root field is its plain outer name; a child field is `<kindProperty>.<field>` (e.g. `phones.number`). Columns without an outer name (FKs, the internal `id`) are omitted. `violated` is `[]` — always a JSON list, never `{}` — when the columns are unknown and on every `stale_write` / `stale_delete` answer (those name no field). The columns come from the repository package (`UniqueViolationException::getColumns()` / `getTable()`, contracts ≥ v2.5.0, repository ≥ v1.5.0; `[]` = unknown, never a partial list).

