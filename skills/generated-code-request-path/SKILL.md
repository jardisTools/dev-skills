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

- **Layer 1, route guard (station 2):** presence, type, unknown keys, paging range — 400 with a closed vocabulary of reasons.
- **Layer 2, process door (station 3):** type and value list of each input field, command fields declared with `accepts:` — before the first node, at every door (route, `process()`, sub-process).
- **Layer 3, command capsule (station 4):** limits from the schema columns (`NotEmpty::trimmed`, length, decimal pattern), then the Rule chain (422), then persist (409).
- **Form layer (station 5):** the answer is cut by the packages (`ProjectOutcome`, one form per outcome); the failure form comes from status, `messageKey` and context. A body never shapes either.

## Reliance list

Each line is a thing you never do in a body, because a station does it.

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
2. **Query** — every read, filter, order and cut is a declared query.
3. **Aggregate** — field rules and behaviour come from columns, value lists and relations.
4. **Closure** — a Rule body is the judgement, a Value body the computation; a Set has no body.
5. **Node body** — only the business logic of the description of that one node.

## Two questions

Ask both before every node, before saving the process.

1. **Does a station already do it?** If the route guard, the process door, a query or the command capsule covers it, there is no node (compare the reliance list).
2. **Can the model say it?** If a means higher in the order carries it, use that; if none does, report a missing capability and build no node around the gap.

## Signs of a superfluous node

1. It checks what station 2, 3 or 4 checks — a 400 on a field of the input or of the target command.
2. It filters, sorts or cuts what a query can: parameter, `in`, optional parameter, join.
3. It computes what a Rule or a Closure would be (a judgement on data, a derived value).
4. It copies the body of another node; the answer is a Closure, a Domain Service or a sub-process.
5. It reads without declaration: a body calls `read()` but the node lists no matching `reads` — declare the read in the model or drop it.

## Reference

- Leaves: `reference/station-1-request.md` · `reference/station-2-dto.md` · `reference/station-3-handler.md` · `reference/station-4-execution.md` · `reference/station-5-return.md`
- Where hand code may stand, V1-V13, the one place table: `generated-code-extend`.
- Process and node declaration, tools and ordering of a headless build: `design-headless-mcp`.
