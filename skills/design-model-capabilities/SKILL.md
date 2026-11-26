---
name: design-model-capabilities
description: Before any interview, concept, PRD or brief for a Jardis project — what each Jardis tool (schema, aggregate, value list, query, process, closure, field map, strategic layer) delivers without hand code, what Jardis does not have, and the mandatory capability table.
zone: pre
persona: A
profile: jardis
prerequisites: [foundation-working-principles]
next: [design-headless-mcp]
---

This skill is an index; the detail lives in `reference/`, one leaf per topic. Read exactly the leaf whose question matches your task, not all of them.
The law (§1), what Jardis does NOT have (§3), the mandatory artefact form (§4) and the reading order (§5) stay in this index.

## Scope

Capability map of the Jardis modelling tools for the AI that decides WHAT is modelled, not only for the AI that builds. It answers one question per model element: which setting of which tool already produces the behaviour? Read it BEFORE the first interview question, concept or PRD line — an interview held without it offers uninformed options ("remove an assignment via PUT" instead of the generated `Remove{Child}` command). Field-level detail of one artefact lives in the tool doors (`design-headless-mcp`); this skill is the decision layer above them.

### 1. The law

1. Whatever is built on Jardis must know and use the capabilities of EVERY Jardis tool completely, for every model element. Programming what a setting already generates is a defect, not a shortcut.
2. Order of means: declarative tools first (Process, Query, Aggregate including its schema-column settings and value lists, field map, strategic layer) → a Closure only where a rule crosses an aggregate boundary → hand code only in the hand-code places (§2.9; table in `generated-code-extend` §10).
3. This knowledge is required when the interview is held and the PRD is written, not only at build time. Every option, concept line and PRD decision names the carrying capability.
4. "Jardis cannot do this" is a question about the cut before it is a finding about Jardis. Before a requirement is called a gap, ask which building block carries it: a bounded context of its own, an aggregate, a process, a query, a closure. A requirement that fits no place in the bounded context at hand usually belongs to another bounded context (examples: the last three rows of §3).

Doors: every write goes through a door (MCP tool or the matching UI surface). Hand-edited definition files are not a supported entry. "MCP" columns below name the tool or resource; "UI" names the surface.

### 2. Per tool — what you get without writing code

#### Navigation

Navigation: one leaf per topic under `reference/` (the per-tool sections §2.1–§2.9). Pick the row whose question fits the task.

| Question the leaf answers | Leaf |
| --- | --- |
| Which Schema.json column or index setting yields which validator, key or stamp (type, nullable, length, unique, auto, enum, default, name conventions, PK/FK)? | reference/schema.md |
| Which aggregate setting (erm, source, relates, required, adopt, depend, identifier, computed, eventIdentifier, orderBy, nesting, size) yields which generated behaviour, and what do the `tenant`/`user` markers, the per-record `access` statement and `writeOnly` do? | reference/aggregate-settings.md |
| Which commands does an aggregate tree yield, with which fields, answers and facade doors? | reference/aggregate-commands-and-answers.md |
| How are value lists bound, and how is a property renamed (field map)? | reference/value-lists-and-field-map.md |
| What can a declared query express (joins, parameters, where/op, chain, limit, total, "my records"), and what do the door stances of `Doors.json` do? | reference/queries-and-doors.md |
| Which process and node settings exist (input types, node kinds, edges, rejects, gives, end node, reads, access statement)? | reference/process.md |
| When and how is a Closure (rule, value, set) declared, bound and answered (422)? | reference/closures.md |
| Which strategic elements (manifests, planned BC, BC canvas ("Steckbrief"), glossary, context map) and tooling surfaces (stack, build, reading, studios, human-only handles) exist? | reference/strategic-and-tooling.md |

#### Continuation 2.9 — where the hand code sits

Where the hand code sits (full table: `generated-code-extend` §10):

```
{BC}/
├── Model/{Agg}/                                 generated, never edited
├── Process/{Name}/Command/Handler/Action/{Node}.php   node body (hand; a Rule or Event node is generated whole)
├── Closure/{Name}.php                           rule / closure body (hand)
└── Service/{Name}.php                           Domain Service (hand)
App/                                             delivery only, no domain logic (App/middleware.php is regenerated)
```

A capability that is missing is not replaced by hand code: extend the model and rebuild; if nothing carries it, stop and tell the human what is missing. When working on Jardis itself, additionally build it in the package or generator. If the human explicitly instructs another way, follow it (the responsibility is theirs), after saying once that it departs from the model way and noting the departure in the code or the project.

### 3. What Jardis does NOT have — do not plan for it

| Missing | Do this instead |
|---|---|
| Minimum/maximum size of a child list (only "at least 1" via `required`) | `required: true` for ≥1; any other bound is a closure or a database constraint |
| Uniqueness within a parent (e.g. one role per contact and organization) | a database unique index (409 `duplicate`) or a closure |
| A field that references another aggregate | store the foreign business key in a plain column; check existence with a closure that reads the other aggregate's list |
| Composite unique key as public key from client-sent columns | none; a composite index stays DDL, the public key is one unique column |
| Per-field regex or read-only flag in `Aggregate.json` (a write-only field exists: `writeOnly`) | column convention, value list, length/type; otherwise a closure |
| Child ordering as data (position, move up/down) | `orderBy` on the derived follow-up query only |
| Configurable cascade (restrict/null on root remove) | the root remove always cascades; FK `onDelete` is DDL only; a guard is a closure on `Remove{Root}` |
| Role pairs / inverse roles in value lists | one list; the inverse label is a frontend label |
| Locking between rule check and command apply | database constraint for hard invariants |
| A second target language | PHP only |
| Sums, averages or grouping in a query (key figures, reports, dashboards); a query answers a paginated list of its root rows | a bounded context of its own that owns the figures as state: a process updates them when the source BC announces an event, a process fills or recomputes them by reading the source BC through its doors; the dashboard is a plain query on that BC (`generated-code-extend` §4) |
| A file format as the answer (PDF, spreadsheet, CSV) | presentation, not domain: the process or query gives the data, delivery code in `App/**` renders the file |
| A system reached without HTTP, or a third-party PHP library inside a use case (SFTP, LDAP) | a Domain Service (closure kind `service`) called from a node; its body takes the library object from the kernel container, never `new` (`generated-code-extend` §4) |

### 4. Mandatory artefact form

Before an interview option is offered, a concept line written or a brief sent, the artefact carries this table for every model element. A closure or hand code may appear only after the declarative row says "not available".

| Model element | Jardis capability (tool · setting) | Used / deliberately not used (reason) |
|---|---|---|

Example (Contact / Organization):

| Model element | Jardis capability | Used / not used |
|---|---|---|
| E-mail and phone of an organization | schema column convention `email`, `phone` → `Email`, `Phone` validators | used — no code |
| Role of a contact at an organization | value list `ContactOrganizationRole` bound to the child column → `ContainOneOf`, 400 `invalid`, closed OpenAPI enum | used — no code |
| Assign / remove a contact ↔ organization | aggregate: child `erm: many` `contactOrganization` → `AddContactOrganization` / `RemoveContactOrganization`, exposed as two processes (`accepts: ["Contact.AddContactOrganization"]`) | used — not "remove by PUT" |
| Contact list filtered by organization | query `contactList`: left join on the child + optional parameter `organizationNumber` | used; check `total` with `probe_query` |
| Organization must exist when assigned | closure `OrganizationExists` (reads `organizationList`, `limit: 1`, `total > 0`), wrapper bound to `Contact`/`AddContactOrganization` chain | closure — not available declaratively (no foreign-aggregate reference field) |
| Organization with contacts must not be deleted | closure `OrganizationHasNoContacts` bound to `RemoveOrganization` | closure — not available declaratively (no configurable cascade/restrict) |

Rows without a Jardis capability name are not allowed. "Deliberately not used" needs a reason that survives a reviewer.

### 5. Reading order before the first interview

1. This skill (the capability decision layer); then resource `jardis://catalog/{domain}/{subdomain}/{bc}` once the aggregates exist — exact command names including child commands, read BEFORE `save_process` and `save_closures` (no guessing binding keys).
2. `design-headless-mcp` — tool and resource calls, ordering, error envelopes; then `generated-code-extend` and `generated-code-recipes` once code exists.

### 6. Reference

- Origin of the law: "Es kann nicht sein, dass alles mit Closure erschlagen wird." and "alle Werkzeuge, die jardis hat, nicht nur die von mir genannten" (product owner, 2026-10-08, "Grundgesetz der Entwicklung mit Jardis"); Reihenfolge der Mittel "Prozess, Query, Aggregate, Closure".
- The source of truth for what the model can express is the generator itself: its validation (the blockers and warnings of `save_*` / `validate_*`) and the `jardis mcp` resources; not a file of your project.
- Absence of per-field regex/read-only flag and configurable cascade in `Aggregate.json`: not in the capability catalog and not among the reserved keys of the aggregate definition (`internal/definition/data.go`, reserved-keys list).
- Tool doors: `design-headless-mcp`. Process skills that require the table: `process-concept`, `process-write-prd`.

### Zitate §N (citation targets)

- §1, §2 → „1. The law“, „2. Per tool — what you get without writing code“ (this index; §2.1–§2.9 below)
- §2.1 → „2.1 Schema (column and index settings)“ (schema)
- §2.2 → „2.2 Aggregate (designer graph)“ (aggregate-settings, aggregate-commands-and-answers)
- §2.3, §2.7 → „2.3 Value lists“, „2.7 Field map“ (value-lists-and-field-map)
- §2.4 → „2.4 Queries“, incl. „2.4a Door stances“ (queries-and-doors)
- §2.5 → „2.5 Process“ (process)
- §2.6 → „2.6 Closures (rules, values, sets)“ (closures)
- §2.8, §2.9 → „2.8 Strategic layer“, „2.9 Tooling surfaces and hand-code surfaces“ (strategic-and-tooling; the hand-code tree of §2.9 is in this index)
- §3 → „3. What Jardis does NOT have — do not plan for it“ (this index)
- §4 → „4. Mandatory artefact form“ (this index)
- §5, §6 → „5. Reading order before the first interview“, „6. Reference“ (this index)
