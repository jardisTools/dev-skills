---
name: generated-code-request-path
description: "Before writing a process node body, Closure or Domain Service, or modelling a node: the fixed path of a request through generated code (request, DTO, handler, execution, return), what each station already guarantees, the reliance list, the order of means."
zone: post-active
persona: C
profile: jardis
prerequisites: []
next: [design-headless-mcp, generated-code-extend]
---

This skill is an index; the detail lives in `reference/`, one leaf per station. Read the leaf of the station your task touches, not all five.
Every request passes five stations before and after your hand-written body. A station that already does something makes that code superfluous: you write only the business logic that is left.
Fact line: requires Jardis Builder ≥ 0.3.0.

## The five stations

1. **Request** — transport, fixed frame, session, right per route, claims `tenant` / `user` become input. Leaf: `reference/station-1-request.md`.
2. **DTO** — a `readonly` carrier; the route guard checks form and type (400) before it exists. Leaf: `reference/station-2-dto.md`.
3. **Handler (orchestrator)** — the process door checks type, value list and command fields, opens the transaction, wires the graph, computes nothing. Leaf: `reference/station-3-handler.md`.
4. **Execution** — process nodes, queries, the command capsule with its own validation and status staircase. Leaf: `reference/station-4-execution.md`.
5. **Return** — projection, failure form, envelope, events, built by the packages from the result of the end node. Leaf: `reference/station-5-return.md`.

## Check layers

- **Layer 1, route guard (station 2):** presence, type, unknown keys and, at a declared-query door, the paging range (`limit` 1 up to the declared cap, `offset` from 0; a value outside is 400 `invalid`, never clamped) — 400 with a closed vocabulary of reasons; the paging range of a read process is checked by its process door (layer 2).
- **Layer 2, process door (station 3):** type and value list of each input field, command fields declared with `accepts:` — before the first node, at every door (route, `process()`, sub-process).
- **Layer 3, command capsule (station 4):** limits from the schema columns (`NotEmpty::trimmed`, length, decimal pattern), then the Rule chain (422), then persist (409).
- **Form layer (station 5):** the answer is cut by the packages (`ProjectOutcome`, one form per outcome); the failure form comes from status, `messageKey` and context. A body never shapes either.

## Reliance list

Each line is a thing you never do in a body, because a station does it. Every generated stub with a hand body names this section in its header (`Before writing this body: dev-skill generated-code-request-path, section "Reliance list".`); the heading keeps its name.

1. **Rights** — never check a right, role or session; the HTTP outer door does it once; a node never answers 403, and a node rejection status outside 400, 401, 404, 405, 409, 422 is `V-NODE-REJECT-STATUS`.
2. **Required / empty / type** — never re-check what the route guard, the process door or the command capsule check; a mandatory field is an input or column declaration, a minimum length is a Rule (422).
3. **Filter / sort / paginate** — never in a body; a query parameter, `orderBy` or `fields` in the model does it, and every query is a paginated list.
4. **Shape the answer** — never build an envelope, projection or failure form; the end node's `gives` with `@outcome` is the answer, the packages cut it.
5. **Transaction** — never open, commit or roll back; the runner owns it and an end status of 400 or above rolls back; a body ends a run with `reject(4xx)` using a declared status.
6. **Tenant / user** — never read the token or resolve them; the door fills the typed input fields and the query filters on the tenant marker itself; a query parameter on a marker column is `V-TENANT-5`.
7. **Read a foreign BC** — never directly; `read()` reaches the own BC only (`LogicException` otherwise), a foreign BC is used through its doors at `domain` or wider from a Domain Service.
8. **Publish events** — never dispatch; the handler collects them and the caller publishes after the commit.

## Order of means

Reach for the means in this order and stop at the first that carries the need:

1. **Process** — flow and branching are the graph, not code.
2. **Query** — every filter, order and cut is a declared query; one aggregate whose key you hold is read through its read base (`{agg}By{Key}`), never as the first row of a list (`generated-code-workflow-api` §5, read node).
3. **Aggregate** — field rules and behaviour come from columns, value lists and relations.
4. **Closure** — a Rule body is the judgement, a Value body the computation; a Set has no body.
5. **Node body** — only the business logic of the description of that one node.

## Two questions

Ask both before every node, before saving the process.

1. **Does a station already do it?** If the route guard, the process door, a query or the command capsule covers it, there is no node (compare the reliance list).
2. **Can the model say it?** If a means higher in the order carries it, use that; if none does, report a missing capability and build no node around the gap outside the hand-code places of the table (`generated-code-extend`, section 10). When you work on Jardis itself, the gap is closed in the package or the generator. Hand code at a place of the table that covers such a gap once is counted by its kind; the same kind a second time is no single case: park the affected place, report the missing capability and keep building everything that does not depend on it.

## Signs of a superfluous node

1. It checks what station 2, 3 or 4 checks — a 400 on a field of the input or of the target command. The validator reports a hand node with `rejects` 400 as `V-PROC-DUPCHECK`.
2. It filters, sorts or cuts what a query can: parameter, `in`, optional parameter, join.
3. It computes what a Rule or a Closure would be (a judgement on data, a derived value).
4. It copies the body of another node; the answer is a Closure, a Domain Service or a sub-process.
5. It reads without declaration: a body calls `read()` (or `context()` on a read handler) but the model lists no matching `reads` — declare the read in the model or drop it. The build reports it as `V-PROC-READ-UNDECLARED`.

## Findings that point here

A hint that names this skill answers a finding of the model check or of the build; the table names the section. Both rules are warnings. A warning of these rules does not stop the build: the code is written, and the warning says nothing about a later Builder version. Read its `hint` and act on it; the goal after a build is 0 warnings and suggestions.

| Finding (`ruleId`) | Where it fires | What it means | What to do |
|---|---|---|---|
| `V-PROC-DUPCHECK` | validate, save, build of a process; one finding per hand node with `rejects` 400 | The node checks the content of an input field itself. `subject.fields` names the fields Jardis already checks (the check is a duplicate); empty means the check has the wrong form (presence is 400 `missing` at the door, a condition is a Rule answering 422). | Drop the check; declare the field as a command field (`accepts:`) or state the condition as a Rule. Section "Signs of a superfluous node", sign 1. |
| `V-PROC-READ-UNDECLARED` | build, after the bodies are merged; node, Closure and Service bodies | A body reads a query of its own bounded context that the model does not declare (`subject.query`), or reads a handler of another bounded context. A read held in a variable and a declared `reads` entry the body never reads are suggestions. | Declare the read (`reads: [{query}]` at the node or at the `Closures.json` entry; the read base `{agg}ById`, `{agg}ByIds`, `{agg}By{Key}`, `{agg}By{Key}s` is declarable too) or drop it. A foreign bounded context is reached through a sub-process, a cross-BC call or the doors of that context. Section "Signs of a superfluous node", sign 5. |

Every finding has the keys `ruleId`, `severity`, `process`, `nodeId`, `file`, `subject`, `message` and `hint`; a finding of the definition check or of a generator self-check leaves `process`, `nodeId` or `file` empty because it is not bound to a node. `severity` is `blocker`, `warning` or `suggestion`. Which refusal carries every blocker of a run: `design-headless-mcp`.

## Reference

Navigation: one leaf per station. Pick the row whose question fits the node you are about to write.

| Question the leaf answers | Leaf |
|---|---|
| I need to know what transport, the fixed frame, the session, the right per route and the claims `tenant` / `user` already do before my code runs | reference/station-1-request.md |
| I need to know what the DTO carries, how the route guard checks form and type (400), or what `Absent` and `null` mean in a patch | reference/station-2-dto.md |
| I need to know what the process door does: input check, transaction, wiring, error mapping, the fixed order of the handler | reference/station-3-handler.md |
| I need to know what a process node, a query or the command capsule does for every request: limits, Rule chain, persist, status staircase | reference/station-4-execution.md |
| I need to know how the answer is built: projection, failure form, envelope, events, `@outcome` of the end node | reference/station-5-return.md |

- Where hand code may stand, V1-V13, the one place table: `generated-code-extend`.
- Process and node declaration, tools and ordering of a headless build: `design-headless-mcp`.
