---
name: start-orientation
description: Starting or orienting in a Jardis project — the master entry point that walks a developer or AI through the four lifecycle phases (package discovery, Schema.json authoring, strategic + Aggregate/Process/Queries design incl. Glossar/Steckbrief/Context Map, implementing generated code), names the concrete `jardis ui`/`jardis mcp` commands, and routes to every other skill in this bundle via a complete lookup table.
zone: crosscut
persona: X
prerequisites: []
next: []
---

## Scope

This skill has one job: orientation. It does not teach any phase itself — every phase links to
the skill that does. Read this first in a fresh Jardis project, or whenever you are unsure which
skill answers the question in front of you. It stretches slightly past a pure cross-cutting
concern (it also names two concrete CLI commands), because a master entry point that cannot say
"run this" is not actually an entry point — the alternative (a second, thinner skill just for
that) would only fragment the routing table this skill exists to provide.

### 1. The four phases

The lifecycle of a Jardis project runs **packages → schema → design → code**:


1. **Discover** — before hand-building any infrastructure piece (cache, queue, HTTP client,
   validation, …), check whether a Jardis package already covers it. → `packages-find-existing`.
2. **Schema** — model the domain's tables from a plain-text idea, or introspect an existing
   database. → `design-draft-schema`.
3. **Design** — draw Aggregates, Processes, and declarative read Queries in the Jardis Designer
   (`jardis ui`). Queries (`Queries.json`) are the fourth designer,
   sibling of Aggregates/Processes: a BC-level artefact for read-only queries against an
   aggregate's data (condition tree, joins, parameters) without hand-written PHP — the Builder
   still renders the code, never SQL. The same tool also carries the strategic layer:
   Domain/Subdomain structure with Core/Supporting/Generic
   classification, a per-BC Ubiquitous-Language glossary and canvas ("Steckbrief"), planned
   (not-yet-built) BCs, and a per-Domain Context Map with the eight canonical DDD relationship
   patterns — including an on-demand Abgleich-Sicht (reconcile lens) that checks the declared
   boundaries against the real coupling of the built system. This is click-work in the browser; there is deliberately no
   dedicated AI skill for this step (`docs/SKILL-FORMAT.md` §3a). Drive the same step headless
   instead via `jardis mcp` → `design-headless-mcp`.
4. **Implement** — write the behaviour inside the generated Command/Handler/Action stubs.
   → `generated-code-extend` (plus its siblings, see the routing map below).

### 2. Running the Builder

This skill assumes the `jardis` binary is already available in your environment — it does not
cover how to obtain it (no Packagist/binary distribution exists for the Builder yet).

- **Browser UI:** `jardis ui` — opens the Schema-Import / Aggregate-Designer / Process-Designer /
  Query-Designer / Build modules described in phases 1–2 above, plus the strategic-design surface
  (Glossar, Steckbrief, Context Map with Abgleich-Sicht).
- **Build:** triggered from the Build module inside `jardis ui` (or headless via the `jardis mcp`
  build tool) — generates the Aggregate/Process code tree from the saved design artefacts onto
  disk (phase 3 handoff). There is no standalone `jardis build` command; the only CLI build entry
  is the legacy Definition-based path `jardis build:code <set>`.
- **Headless (no browser):** drive the same Workspace→Schema→Design→Build sequence
  programmatically → `design-headless-mcp`, which names the concrete calls and their ordering.

### 3. Routing map — "I want to X" → skill Y

| I want to… | Skill |
|---|---|
| Check if a Jardis package already covers something before I build it myself | `packages-find-existing` |
| Design a `Schema.json`'s content from a domain idea, no database yet, and hand it to an authoring door (MCP `import_schema` / UI schema import) | `design-draft-schema` |
| Extend generated Command/Handler/Action code; understand the hermetic Aggregate tree, `$bc->{agg}()`, V1–V13 | `generated-code-extend` |
| Wire generated Commands/Queries into an HTTP/CLI/queue/worker transport layer | `generated-code-wire-transport` |
| Understand ClassVersion resolution, or add a versioned variant of a generated class | `generated-code-versioning` |
| Use the Workflow-Engine API inside a Process orchestrator (routing statuses, `WorkflowConfig`) | `generated-code-workflow-api` |
| Look up a Phase-3 recipe (event-to-Kafka, VO in a Process node, …) or troubleshoot a stuck build | `generated-code-recipes` |
| Check the five architecture pillars / hexagonal dependency direction before writing a class | `foundation-architecture` |
| Review a frontend plan or component against Jardis's stack-agnostic frontend rules | `foundation-frontend-review` |
| Pick the right design pattern (Facade, Strategy, Repository, …) for a problem | `foundation-patterns` |
| Write a test, or decide what to do about a failing one | `foundation-testing` |
| Drive an entire Jardis workspace headless — no browser, an AI or script calls `jardis mcp` directly | `design-headless-mcp` |
| Classify a subdomain (Core/Supporting/Generic), maintain a BC's glossary or canvas ("Steckbrief"), or plan a not-yet-built BC — headless, additive to the code-generation workflow | `design-headless-mcp` |
| Declare a Domain's Context Map (BC relationships via the eight canonical DDD patterns, external systems), or run the read-only drift check — declared boundaries vs. the real coupling of the built system | `design-headless-mcp` |
| Declare a BC's declarative read Queries (`Queries.json`: condition tree, joins, parameters), preview the generated code, or drive Query rename/delete/duplicate — headless | `design-headless-mcp` |
| Guard a Command with a business Rule, declare a BC's `Closures.json` catalog+bindings, or drive Closure rename/delete/duplicate — headless | `design-headless-mcp` |
| Learn the working principles of every task: skill first, then source, then ask; verify instead of guessing | `foundation-working-principles` |
| Write or review PHP 8.3 code: strict types, PSR-4, Closure-Orchestrator form, test naming | `foundation-php` |
| Review a change for typing, security, error handling, API, performance before it is committed | `code-review-change` |
| Start a branch, commit, push and open a pull request, check repository compliance, or set up a new repository | `git-start-branch`, `git-commit-change`, `git-push-and-open-pr`, `git-check-compliance`, `git-setup-repository` |
| Keep decisions, pitfalls and current facts in a knowledge pool under `.claude/wissen/` | `knowledge-maintain-pool`, `knowledge-record-decision` |
| Decide how much process a task needs, or what to offer at the end of a chat | `process-choose-tier` |
| Learn what the environment already does before proposing something new | `process-check-existing` |
| Run an undertaking: concept, PRD, plan, stage runs, verification, close, or continue one | see section 5 |

### 4. Tiers — how much process a task needs

Pick the lowest tier that fits and name it in one line. Process is a means against size and risk, not a default. The rules and the escalation rule are in `process-choose-tier`.

| Tier | Task | How |
|---|---|---|
| **0 Answer** | A question, an explanation, a comment, a typo | Answer directly |
| **1 Single action** | One thing in one place, no open decision | Do it yourself, without apparatus |
| **2 Small assignment** | One big or isolated thing, or several subtasks | One sub-agent per subtask; you supervise and verify |
| **3 Undertaking** | Open decision, dependent steps, new architecture, public API, data, security | The full process in stages 0 to 4 (section 5) |

Before proposing anything new at tier 2 or 3, run `process-check-existing`.

### 5. The process phases and their skill

An undertaking (tier 3) runs in five stages. Every stage has one skill; each names the next.

| Stage | What happens | Skill |
|---|---|---|
| 0 Concept | Interview, target picture, human approval, project folder with progress file | `process-concept` |
| 1 PRD | Requirements on top of the picture, one review board, human confirmation | `process-write-prd` |
| 2 Plan | Stages and phases with acceptance criteria, one review board, human release | `process-write-plan` |
| 3 Build | One brief per phase, implementers in fresh sessions, QA gates, merge | `process-run-stage` |
| 3 Check | One blind verifier per stage, one acceptance gate at the end | `process-verify` |
| 4 Close | Triage, lessons into the pool, digest, delete the project folder | `process-close` |

Supporting skills: `process-review-board` (the review roles and their sources), `process-resume` (continue a running undertaking in a fresh session), `knowledge-record-decision` (record a decision in the pool).

The reviewer roles of the process — requirements, design, frontend, stage verifier, acceptance gate, open-question gate, failure diagnosis, existing-capability check — are sources in `process-review-board`; the role list is given there.

### 6. Reference

- Full Tool/Resource surface for headless driving: `design-headless-mcp`.
- Werkzeugkasten (which package skill answers "how do I cache / send mail / …"):
  `generated-code-extend`.
- Skill authoring rules and zone/persona model: `docs/SKILL-FORMAT.md`.
