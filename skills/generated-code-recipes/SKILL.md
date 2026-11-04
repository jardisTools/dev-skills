---
name: generated-code-recipes
description: Phase-3 recipes and troubleshooting for Designer-generated code (events, Closures, External-Call, bulk read, cross-BC, Rules-Layer, transactions). Use when extending generated code, wiring a Process node, or debugging ClassVersion/422 issues.
zone: post-active
persona: C
profile: jardis
prerequisites: [generated-code-extend]
next: []
---

This skill is an index; the detail lives in `reference/`, one leaf per topic. Read exactly the leaf whose question matches your task, not all of them.
Descriptions that no longer apply go to `reference/archiv.md` (currently empty — every historical note still carries present-day facts and stays in its topic leaf).

## Triggers

Phase-3 recipes and troubleshooting for Designer-generated code — Event announce from an Event ◇ node and transport in `App/**` delivery code (Kafka/RabbitMQ/Redis/HTTP-webhook), validating a domain concept with a Closure, external lookup through an External-Call node (Domain Service at `{BC}/Service/`), new aggregate op vs. new Process, Response shapes per operation, bulk read list→ids→`get{Agg}ByIds` (or, with a unique key, list→keys→`get{Agg}By{PluralKey}`), sub-process node, cross-BC write (DTO-translation → foreign door → response-mapping), guard a Command with a business Rule (Rules-Layer), write a computed field from a process node (`Set{Entity}Computed`), cross-BC read only through the doors of the foreign BC (`{Agg}Read`, from a Cross-BC-Call node and its Service; writes through its doors), typed Process inputs (date/datetime/time/decimal/uuid/`valueList`, strict date gate), the Process answer (end-node `gives`), the process transaction bracket (default on, 4xx rollback, events only after commit, `runInTransaction: false` opt-out), Invariant as state (uniqueness via a first-writing gatekeeper node + CAS-UPDATE instead of check-then-act), troubleshooting table (ClassVersion misses, hermetic-tree edits lost, routing-safety, Rule-merge/422 pitfalls).

> **Layout context.** The aggregate tree `{BC}/Model/{Agg}/` (abbreviated `{Agg}/` in this skill) is hermetic — ForceOverwrite, never edited (V1); hand code stands only at the places of the one table in `generated-code-extend` §10 (node bodies of a process reached via `$bc->process()`, Closure/Rule bodies, `{BC}/Service/`, `App/**`). `$bc->{agg}()` reaches only the read facade `{Agg}Read` (the BC facade's public surface); the write facade `{Agg}` is family-internal via the kernel seam `$this->handle({Agg}::class)`. Canonical layout + vocabulary glossary (aggregate facade / read facade / public surface / kernel seam / Context family): `generated-code-extend` §1–§2.

### Mandatory rules

#### §1 — Event transport

An **HTTP webhook** goes the same way through the kernel's PSR-18 `httpClient()` (`adapter-http`). A projection or audit trail is not a free projector class: model it as a Process (or an aggregate Command) that the delivery code calls through `process()` (or, for a command door at `domain` or wider, `{BC}::{command}()`).

**Rules:**

- Never `new` publishers / clients — take them from the DomainKernel accessors (V2 / V3).
- Never fill the generated `<Agg>EventRouter` bodies — the tree is hermetic (V1); the router's channel-key comments are documentation for the topic names, nothing more.
- A node runs **synchronously** in the workflow and publishes nothing; the delivery code publishes after the commit and decides nothing about the business.

#### §2 Recipe 8 — Sub-process node

**Stance `closed` of a process:** a process that is only called as a sub-process of its own BC (never directly via `$bc->process()`) has the row `closed` in the block `process` of `Doors.json` (door table of the BC page, or `save_doors`). This suppresses the thin-dispatch method on the `{BC}Process` facade — the process DTO, orchestrator, and node stubs are always generated regardless of the stance. A sub-process node onto a process of a FOREIGN BC needs the stance `domain` or wider on that process (`V-CONSUMED-EXISTS`).

**Rules:**
- Never `new` the Sub-Handler or call it by hand — the generated `$this->context(…)()` call is the only way (V2 / V3).
- Never return the `DomainResponse` upstream — `MapResponse` is the one mapping.
- The sub-process node body survives rebuilds (unlike ForceOverwrite nodes) — keep the `@node-id` marker intact.

#### §2 Recipe 10 — Rules-Layer

**Binding a Rule node's inputs.** Short form `ruleSubject.payloadField` serves a Rule with exactly one `command`/`aggregate` input (a field of the process input). Any other Rule — several inputs, scalar or value-list inputs — uses the long form `ruleArgs: [{param, source}]`, one entry per Rule input in the Rule's own parameter names, never mixed with `ruleSubject` on the same node. `source` is a process input field (bare name) or `{kind: latest, node, field}`: a field of the latest result of an earlier station (a command node: `facade` + `method` set, the fields of its identity chain; a hand-written node: a member of the object value it `gives`). That earlier node must be a mandatory station before the Rule node; there is no `all` for Rule arguments. Example (neutral): a check `StockAvailable(sku, quantity)` fed `sku` from the input and `quantity` from the object value that a custom node `PriceLine` `gives`: `"ruleArgs": [{"param": "sku", "source": "sku"}, {"param": "quantity", "source": {"kind": "latest", "node": "PriceLine", "field": "quantity"}}]`. The Rule outcome stays `passed → OnTrue` / violation → 422 or `OnFalse` (decision mode); the node routes without data of its own — a later node that needs a value reads the earlier node explicitly (the typed getter of `<Name>Context`), and a process whose end node gives `nothing` answers with no fields of its own (on the wire `data` carries only `@type` and `@outcome`).

**Rules:**
- Never `new` a Rule — always `$this->handle({Rule}::class)` (ClassVersion-capable, `Closure/v{N}/`).
- Read existing data only from your **own** BC (V13/M9) — via that BC's read facade, or directly via the kernel seam (`context()`) for a BC-internal read — a declared list read with `limit: 1`, decided over `total` — a cross-BC existing-data check is process territory, not a Rule. **Worked example (`query-ist-immer-eine-liste.md`):** "customer has open invoices" — Query `openInvoicesByCustomer` (stance `closed`, `limit: 1`) declared via `save_queries`, bound via `Closures.json` `reads:`; the Rule body reads `$this->context(GetOpenInvoicesByCustomerHandler::class, new OpenInvoicesByCustomerFilter(customerId: $cmd->customerId, limit: 1))()` and rejects when `total > 0`.
- **An `aggregate` input is the aggregate entity, not just the root:** `{Agg}\Model\{Agg}\Aggregate\Entity\{Agg}` with the child getters `get{Kind}()` (list for `erm: many`). Check children directly (`foreach ($root->getLines() as $line) …`) instead of declaring a helper query in `reads` for data the Guard already holds; the example's `given.root` carries the children under their relation names and `ClosureTestCase::aggregate()` builds the entity. A body that only uses root getters is unaffected.
- A Rule never throws to reject — `RuleResult::reject(...)` is data, not an exception. Only let a genuinely technical failure (DB down) propagate as an exception (→ 500), never mis-signal it as a 422 by wrapping it in `reject()`.
- A freshly generated, **not-yet-implemented** stub throws too — but for the opposite reason: the emitted body is `throw new \RuntimeException('Not implemented: write the rule predicate for ' . self::class)`, not `RuleResult::pass()` (G03). An unfinished Rule fails loud (500) instead of silently letting every Command through — implement `__invoke()` before binding it live.
- Versioning a Rule (`Closure/v2/`) may **tighten** the accepted set, but must keep the payload shape + `messageKey` stable — that's the contract callers (and i18n) depend on (M5, `generated-code-versioning`); a catalog-declared `messageKey` guarantees this across versions.
- TOCTOU is a known v1 boundary (`generated-code-extend` §7) — a concurrent write between the Rule's read and the Command's persist is not locked against. If the invariant is truly hard, close it in the model: a DB constraint in `Schema.json` (Recipe 12) or state with a conditional write (Recipe 11).

### Navigation

Navigation: one leaf per topic under `reference/`. Pick the row whose question fits the task.

| Question the leaf answers | Leaf |
|---|---|
| I announce a Domain event from an Event ◇ node, publish it from `App/**` delivery code (Kafka / RabbitMQ / Redis / HTTP webhook), or read what a node sees of earlier nodes (§1) | reference/event-transport.md |
| I validate a domain concept with a Closure, do an external lookup (External-Call node, Domain Service), choose aggregate command vs. Process, walk Recipe 4 (an Event end-to-end through a transport), or declare typed Process inputs (§2, Recipes 1–5) | reference/cookbook-basics.md |
| I define the Response shape of an operation or the success answer (`gives`), add a custom field, or do a bulk read list → keys/ids → full records (§2, Recipes 6–7) | reference/response-shapes-and-bulk-read.md |
| I call another process as a sub-process node, or write to / read from a foreign BC through its doors (Cross-BC-Call node, `crossBcCall`) (§2, Recipes 8–9) | reference/subprocess-and-cross-bc.md |
| I guard a Command with a business Rule (Rules-Layer): catalog entry, stub body, Rule node, binding inputs, 422 verdict (§2, Recipe 10) | reference/rules-layer.md |
| I secure an invariant as state (uniqueness across process boundaries: gatekeeper node, CAS-UPDATE, retry, 409) or map a duplicate on a unique key to 409 `duplicate` (§2, Recipes 11–12) | reference/invariant-uniqueness.md |
| I write a computed field from a process node (`Set{Entity}Computed`) or control the process transaction bracket (4xx rollback, events after commit, `runInTransaction: false`) (§2, Recipes 13–14) | reference/computed-field-and-transactions.md |
| I debug a symptom: ClassVersion miss, edit lost after rebuild, V-* build failure, 400/403/409/422 answer, node not invoked (§3) | reference/troubleshooting.md |
| I look for an entry that no longer applies (archive, currently without entry) | reference/archiv.md |

### Zitate §N (citation targets)

- §1 → „1. Event transport — authored in a Process node“ (event-transport)
- §2 → „2. Phase-3 cookbook“ (cookbook-basics Recipes 1–5; response-shapes-and-bulk-read Recipes 6–7; subprocess-and-cross-bc Recipes 8–9; rules-layer Recipe 10; invariant-uniqueness Recipes 11–12; computed-field-and-transactions Recipes 13–14)
- §3 → „3. Troubleshooting“ (troubleshooting)

### Anchors

- `generated-code-extend` (hermetic aggregate layout, the customization surfaces incl. the `{BC}/Closure/` catalog, prohibitions incl. M9/V13, decision tree, TOCTOU boundary).
- `generated-code-versioning` (ClassVersion resolution — `LoadClassFromSubDirectory`, per-class `v{N}`).
- `generated-code-workflow-api` (Workflow-Engine API used by the Process orchestrators + node routing referenced above).
- `adapter-messaging`, `adapter-http`, `adapter-eventdispatcher` (event-transport recipes).
- `foundation-testing` (EventCollector fake for Recipe 4).
