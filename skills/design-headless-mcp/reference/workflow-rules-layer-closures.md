#### Continuation §2 — Rules-Layer and Closures

**Rules-Layer — full MCP parity:** a BC's `Closures.json`
(the Rule catalog + per-Command bindings that guard writes between the aggregate and its process,
`generated-code-extend`) is fully MCP-reachable, same as every step of the workflow (the `workflow-*` leaves) — no browser-only
capability here. Child commands included: every `erm: many` child's `Add{Singular}` / `Update{Singular}` /
`Remove{Singular}` appears in the `catalog` Resource (with `httpMethod` and `target`) and is opened through
its stance row in `Doors.json` (`save_doors`) exactly like a root command — no browser-only step. `save_closures` answers lean: findings and `newMtime`, not the saved catalog/bindings — pass `echo: true` to get
them echoed back, or read the stored content through the closures Resource. `save_closures` persists the catalog+bindings document (LockedSave — a
`CONFLICT` means the on-disk file moved under you, same mtime/force pattern as `save_aggregate`).
The `catalog` is merged **by entry name**: a sent entry is added or replaced as a whole (no field
merge inside the entry), entries you did not send stay, `catalog: {}` without `replace` is a no-op.
To make the sent catalog the whole catalog send `replace: true` — the dropped names come back sorted
in `removed`; `replace: true` without a `catalog` is a 400. `delete_closure` removes a single entry.
`bindings` and `validationOverrides` are **not** merged by entry: a sent map replaces the stored one
(unbinding by omission), an omitted one stays. `validate_closures` composes the same way (incl.
`replace`) and predicts the following Save;
Catalog entries: `reads: []` / `examples: []` clear the stored list, an omitted key keeps it;
a declared `messageKey` is binding (the 422 carries exactly this catalog key; the `reject()` argument in the body is not taken over) and is never cleared — omitted or `""` keeps the stored key, and an empty one on a
verdict closure without `compose` is derived as `{lcfirst(bc)}.{lcfirst(name)}`. Bindings: omit a
command's entry to leave it unbound; `chain: []` is a deliberately empty guard (the guard class is
still generated, the drift report lists it as an empty chain).
`validate_closures` checks a not-yet-saved catalog/bindings set against the V-RULE-* rules
(read-only, no write). Read side: the `closures` Resource template returns the catalog+bindings
as-is, plus `dockable` (per closure: `guard` — commands whose chain it may still join; `ruleNode` —
whether it may back a Process Rule-node; `sets` — Rule-Sets it may join as a member), `usedAt`
(per closure: guard chains, Rule-nodes, other Closures' `uses`), `usedAtUnknown` (true when the
process-usage scan itself failed, so an empty `usedAt` must NOT be read as "genuinely unused"),
`scalarTypes` (the closed column-type vocabulary a scalar input/output may declare) and
`policyCoverage` (Steckbrief (BC canvas) policy id → `{level, closures, wirkorte}`, `level` one of `covered` /
`no-effect` / `no-anchor` / `unknown-usage` — `no-anchor` also stands for a policy with no
anchoring closure at all when the usage scan failed, since that fact is Usage-independent);
`closures-drift` is a read-only finding set — `policy_without_rule` / `rule_without_policy` /
`empty_chain` — mirroring the Context-Map drift-check pattern (computed on demand, never
persisted, no bulk-align tool here either). Lifecycle tools mirror `rename_query`/`delete_query`/
`duplicate_query`'s pattern: `rename_closure` (`confirm=true` required, or `dryRun=true` for a
no-write preview that needs no `confirm`; cascades the rename into every binding chain naming it, every Closure's `uses`
list — Set member or free sub-closure reference alike — and every process Rule-node's `rule`
field — a materialised stub or `Closure/v{N}/` override is never moved, only reported in
`warnings`; the closure's `messageKey` follows the rename when it was the derived key of the old
name, a hand-set key stays), `delete_closure` (blocked with a `409 IN_USE` by >=1 bound chain, >=1 Closure's `uses`
(Set membership or a free sub-closure reference), or >=1 process Rule-node — `force=true` overrides it, stripping the name
from every binding chain and `uses` list and dropping a chain row it empties; deleting a Rule-Set
itself leaves its members untouched in the catalog), `duplicate_closure` (`newName` optional — a
blank value auto-suggests the first free `{name}Copy`/`{name}CopyN`; the copy starts unbound and
carries no dev-body, and duplicating a Rule-Set's member never enters the copy into that Set).
Inspect `jardis://closures/{domain}/{subdomain}/{bc}/usage` before a real rename/delete — the same
bindings/processes lists travel back in the response's `consequences` field either way.

**Writing a Closure body — start from the `…/work` work package.** A Closure catalog entry
declares **0..n** typed `input[]` parameters (`command`/`aggregate`/`scalar`/`valuelist`) and one
`output` that is either `verdict` or a plain scalar — not just a single guard-bound
`command`/`aggregate` input with a `verdict` output. Before writing (or asking an AI to write) a
Closure's `__invoke` body, read the per-Closure Resource template
`jardis://closures/{domain}/{subdomain}/{bc}/{name}/work` — it composes everything needed for that
one Closure on a single call, assembled purely from already-saved catalogs (Closures.json,
Queries.json, the aggregate/command catalog, ValueLists.json, usage), no second source of truth:
`task` (a plain-language brief), `contract` (description, the exact `__invoke` signature, each
input's PHP type, the output's type plus its pass/reject or return shape, `output.rejectNote` when the entry declares a `messageKey` (the 422 carries that catalog key, not the one passed to `reject()`), `policyRef`, `examples`),
`files` (the stub path plus `stubExists`, the path of the test you write from `examples[]` plus `testExists` — both
Go-derived facts, true only when that file was actually found on disk —, and how to run the
test), `uses`/`reads` (every composed Closure / readable query, each with its own signature and
call recipe), `types` (the PHP field shape of every `command`/`aggregate`/`valuelist` input),
`context` (helper text and guardrails for the `handle()`/`context()` corridor), `usedAt` (guard
chains — a guard entry carries `viaSet` when a Rule-Set is the only path binding the Closure to
that chain — process Rule-nodes, and other Closures' `uses`), `body` (`offen`/`geschrieben` —
whether the generated stub still throws its Not-implemented marker; absent for a Rule-Set, which
has no body of its own), and `missing` (concrete next steps). Flow: read the resource → write the
`__invoke` body at `files.stub` → `build` (reconciles the stub's leftover `@throws` tags and markers — run it before PHPStan, otherwise `throws.unusedType` shows up) → write the test at `files.test` from `examples[]` (one case per example, against the generated `ClosureTestCase`), run it (`files.runTest`) until it is
green. Also reachable over HTTP — `GET /api/closures/{domain}/{subdomain}/{bc}/{name}/work` calls
the SAME service method and returns the SAME JSON shape; the contract itself is written via
`save_closures` — Resource and HTTP route only compose a read-friendly, AI-facing view of the same
data.

**Aggregate delete takes its queries along:** `delete_aggregate` removes the queries the aggregate owns from `Queries.json` (the delete preview lists them as `affectedQueries`; duplicated-root queries are `unassignableQueries` and stay); declared readers (`reads:`) of such a query block with 409 `details.queryReaders`, one `force` overrides every block, the answer carries `removedQueries`. `validate_definitions_all` also checks, once per BC, its Queries (rows with `aggregate ""`, handler `queries`), the door files and door list (handlers `doors`, `door-list`) and the artefact sections Closures (handler `closures`), ValueLists (`valuelists`) and every process (`process`) - the same findings the Board shows, from the same check functions; every row of a registry rule carries the V1 fields `ruleId`, `v1Severity` (`blocker`, `warning`, `suggestion`) and `subject` next to its old fields (`severity` keeps its old words), a row without a registry rule or without such a severity (`overridden`, `drift`, `unvalidatable`) carries none; the buffer checks `validate_queries`/`validate_closures`/`validate_value_lists`/`validate_process` read the same door list with the buffer in place of its stored file, so the door-list rules (V-DOOR-1/3, V-RULE-5, V-QDEF-23) apply there too. And the process validator rejects colliding PHP class names derived from one process (Blocker `V-PROC-CLASS-UNIQUE`).

**Rule node in a process (`save_process`).** A node with `rule: <Closure>` binds the closure's inputs in one of two forms, never both (`V-PROC-RULE-ARGS-XOR`): the short form `ruleSubject.payloadField` (closure with exactly one `command`/`aggregate` input; a process input field) or the long form `ruleArgs: [{"param": "<input name>", "source": …}]` that binds EVERY input of the closure exactly once. `source` is a bare string = a process input field, or `{"kind": "latest", "node": "<earlier node id>", "field": "<f>"}` = a field of the latest result of an earlier station (a command node adds `facade` + `method` and offers the fields of its identity chain; a hand-written node offers the members of the object value it `gives`; no `all`). A `command` input only comes from a command field of the process input; an `aggregate` input from a scalar key value (loaded, 404 as before). Any closure with a `verdict` output and at least one input may back a Rule node; `V-PROC-RULE-ARGS` blocks missing/duplicate/unknown params, unfitting types and a source node that is not a mandatory station before the rule.
