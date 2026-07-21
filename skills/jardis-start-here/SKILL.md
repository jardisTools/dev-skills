---
name: jardis-start-here
description: Starting or orienting in a Jardis project — the master entry point that walks a developer or AI through the four lifecycle phases (package discovery, Schema.yaml authoring, strategic + Aggregate/Process design incl. Glossar/Steckbrief/Context Map, implementing generated code), names the concrete `jardis ui`/`jardis mcp` commands, and routes to every other skill in this bundle plus the two Builder-repo skills via a complete lookup table.
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

1. **Discover** — before hand-building any infrastructure piece (cache, queue, HTTP client,
   validation, …), check whether a Jardis package already covers it. → `jardis-catalog`.
2. **Schema** — model the domain's tables from a plain-text idea, or introspect an existing
   database. → `schema-authoring`.
3. **Design** — draw Aggregates and Processes in the Jardis Designer (`jardis ui`). The same
   tool also carries the strategic layer: Domain/Subdomain structure with Core/Supporting/Generic
   classification, a per-BC Ubiquitous-Language glossary and canvas ("Steckbrief"), planned
   (not-yet-built) BCs, and a per-Domain Context Map with the eight canonical DDD relationship
   patterns — including an on-demand Abgleich-Sicht (reconcile lens) that checks the declared
   boundaries against the real coupling of the built system. This is click-work in the browser; there is deliberately no
   dedicated AI skill for this step (`docs/SKILL-FORMAT.md` §3a). Drive the same step headless
   instead via `jardis mcp` → `jardis-mcp-consumer`.
4. **Implement** — write the behaviour inside the generated Command/Handler/Action stubs.
   → `platform-implementation` (plus its siblings, see the routing map below).

### 2. Running the Builder

This skill assumes the `jardis` binary is already available in your environment — it does not
cover how to obtain it (no Packagist/binary distribution exists for the Builder yet).

- **Browser UI:** `jardis ui` — opens the Schema-Import / Aggregate-Designer / Process-Designer /
  Build modules described in phases 1–2 above, plus the strategic-design surface (Glossar,
  Steckbrief, Context Map with Abgleich-Sicht).
- **Build:** triggered from the Build module inside `jardis ui` (or headless via the `jardis mcp`
  build tool) — generates the Aggregate/Process code tree from the saved design artefacts onto
  disk (phase 3 handoff). There is no standalone `jardis build` command; the only CLI build entry
  is the legacy Definition-based path `jardis build:code <set>`.
- **Headless (no browser):** drive the same Workspace→Schema→Design→Build sequence
  programmatically → `jardis-mcp-consumer`, which names the concrete calls and their ordering.

### 3. Routing map — "I want to X" → skill Y

| I want to… | Skill |
|---|---|
| Check if a Jardis package already covers something before I build it myself | `jardis-catalog` |
| Draft a `Schema.yaml` by hand from a domain idea, no database yet | `schema-authoring` |
| Extend generated Command/Handler/Action code; understand the hermetic Aggregate tree, `$bc->{agg}()`, V1–V13 | `platform-implementation` |
| Wire generated Commands/Queries into an HTTP/CLI/queue/worker transport layer | `platform-usage` |
| Understand ClassVersion resolution, or add a versioned variant of a generated class | `platform-versioning` |
| Use the Workflow-Engine API inside a Process orchestrator (routing statuses, `WorkflowConfig`) | `platform-workflow` |
| Look up a Phase-3 recipe (event-to-Kafka, VO in a Process node, …) or troubleshoot a stuck build | `platform-cookbook` |
| Check the five architecture pillars / hexagonal dependency direction before writing a class | `rules-architecture` |
| Review a frontend plan or component against Jardis's stack-agnostic frontend rules | `rules-frontend` |
| Pick the right design pattern (Facade, Strategy, Repository, …) for a problem | `rules-patterns` |
| Write a test, or decide what to do about a failing one | `rules-testing` |
| Drive an entire Jardis workspace headless — no browser, an AI or script calls `jardis mcp` directly | `jardis-mcp-consumer` |
| Classify a subdomain (Core/Supporting/Generic), maintain a BC's glossary or canvas ("Steckbrief"), or plan a not-yet-built BC — headless, additive to the code-generation workflow | `jardis-mcp-consumer` |
| Declare a Domain's Context Map (BC relationships via the eight canonical DDD patterns, external systems), or run the read-only drift check — declared boundaries vs. the real coupling of the built system | `jardis-mcp-consumer` |
| Understand how the Builder's own generation engine/pipeline works (Builder-repo, not this bundle) | `tools-builder-engine` |
| Understand the Builder's own browser-UI internals (Builder-repo, not this bundle) | `tools-builder-ui` |

The last two rows are **named pointers only** — `tools-builder-engine` and `tools-builder-ui`
live in the Builder repository's own `.claude/skills/`, not in this bundle; there is nothing to
load here, only somewhere else to look.

### 4. Reference

- Full Tool/Resource surface for headless driving: `jardis-mcp-consumer`.
- Werkzeugkasten (which package skill answers "how do I cache / send mail / …"):
  `platform-implementation`.
- Skill authoring rules and zone/persona model: `docs/SKILL-FORMAT.md`.
