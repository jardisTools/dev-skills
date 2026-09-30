# Skill Format — Authoring Standard

**Status:** v6 · 2026-09-30

This document is the single source of truth for **how to write** a bundled skill in this repository. Every `SKILL.md` under `skills/<name>/` MUST follow this format.

> **v6 vs. v5.** v6 adds the `process` zone (budget 250 lines) and the `O` persona for process-orchestrator skills, replaces the reserved-prefix list by the area prefixes of §2, adds the description-length convention for new skills (≤45 words, §2), and adds two validator checks: skill links (§11) and rule markers with cap figures (§12). `ValidateSkillMd` (v6) recognises the zone and persona; `CheckSkillLinks` and `CheckRuleMarkers` are chained by `bin/validate-skills.php`.

> **v5 vs. v4.** v5 introduces the `discovery` zone (budget 150 lines) and the `X` persona for cross-phase capability discovery. Before building a reusable component, an agent with `persona: X` consults a Discovery skill (e.g. `jardis-catalog`) to find existing Jardis packages and recommend `composer require` instead of re-inventing the wheel. The new zone and persona are deliberate, documented extensions — not ad-hoc additions. The "Zones are stable categories" note in §3 is updated accordingly. `ValidateSkillMd` (v5) recognises both the new zone and the new persona value.

> **v4 vs. v3.** v4 introduces a required `persona:` frontmatter field (A / C / D — see §2) and bans Generator-Internas from bundle skills. Root cause: bundle skills were drifting into Generator-implementation details (renderer file paths, IR struct names, pipeline-stage names) that go stale at every builder refactor and serve no bundle persona — that material now lives exclusively in `tools-builder-engine` (Persona E, Builder repo). The retired skill `tools-definition` is no longer part of the bundle; its Schema-YAML coverage was already in `schema-authoring`, its Aggregate / Source / FieldMap / Lists / Flow vocabulary moved to `tools-builder-engine`. v4 also adds a Werkzeugkasten cross-ref convention for `platform-implementation` (see §10). See `docs/PRD-skill-overhaul.md` §V4 for the full rationale.

> **v3 vs. earlier drafts.** Earlier revisions prescribed a fixed five-heading body template (`## When this skill applies`, `## What the AI does`, …) and a 30-word description cap. Hands-on iteration showed that reference-heavy skills (e.g. `platform-implementation`) communicate more clearly with a topical numbered structure and denser trigger descriptions. v3 replaced the prescriptive template with a lighter set of invariants and lets each skill pick the section structure that fits its content. The validator (`bin/validate-skills.php`) enforces only the invariants below.

---

## 1. Why this standard exists

Skills are loaded by AI agents (Claude Code, Cursor, Aider, …) based on their **frontmatter description**. A weak description means the skill never fires. A bloated body means the AI loses focus. A duplicated rule across skills means the AI gets contradictory guidance.

This format optimises for three things:

1. **Trigger reliability** — dense, situation-specific descriptions that fire when needed and stay silent otherwise.
2. **Action density** — body that tells the AI *what to do / what to look up*, not what *exists*. References, not repetitions.
3. **Phase-awareness** — every skill knows its zone in the Jardis workflow and points to the next step.

---

## 2. Frontmatter (mandatory)

```yaml
---
name: <kebab-case-name>
description: <One sentence in English. Names the situation + key trigger terms.>
zone: pre | post-active | post-reference | crosscut | discovery | process
persona: A | C | D | X | O
prerequisites: [<other-skill-name>, ...]   # may be empty []
next: [<other-skill-name>, ...]            # may be empty []
---
```

### Field rules

| Field | Required | Rules |
|---|---|---|
| `name` | yes | Kebab-case. Matches the directory name. Starts with one of the area prefixes listed below. |
| `description` | yes | **One sentence**, single line, English. ≤175 words (hard limit of the validator); **new skills ≤45 words** (convention, see below). Starts with the situation or artefact (`"Use when …"`, `"Reference for …"`, `"Extending …"`, `"Wiring …"`). Dense comma- or em-dash-separated trigger terms are welcome — the sentence is both the trigger prompt for the loader and the first piece of context the AI sees. |
| `zone` | yes | One of six values. See §3. |
| `persona` | yes | One of `A`, `C`, `D`, `X`, `O`. See §3a — every skill must serve exactly one persona. Values `B` (Designer-Companion, retired) and `E` (Builder-Dev, lives in `tools-builder-engine`) are deliberately not part of the bundle. |
| `prerequisites` | yes | Array of skill names that should have run before this skill is useful. Use `[]` if independent. |
| `next` | yes | Array of skill names that typically follow. Use `[]` if terminal. |

### Area prefixes

The name of a bundle skill starts with the prefix of its area. The prefix tells the agent and the reader what the skill is for; it is not a zone.

| Prefix | Area |
|---|---|
| `start-` | Orientation: entry point and routing into the other areas. |
| `packages-` | Finding an existing Jardis package before hand-building a component. |
| `design-` | Designing a domain before code exists (schema drafting, headless design surface). |
| `generated-code-` | Extending, wiring and versioning code the Designer generated. |
| `foundation-` | Cross-cutting rules: architecture, patterns, testing, frontend review. |
| `git-` | Git workflow: branch, commit, push and pull request, compliance, repository setup. |
| `knowledge-` | Keeping project knowledge: the decision pool and its maintenance. |
| `process-` | Running a development process: choosing the tier, concept, PRD, plan, stages, verification, closing. |
| `code-review-` | Reviewing a change. |

Do not introduce a new prefix without a deliberate, documented extension of this table. The prefixes `adapter-`, `core-`, `support-` and `tools-` belong to package skills shipped by the Jardis packages themselves, not to bundle skills.

### Description length for new skills (≤45 words)

The validator's hard limit stays at 175 words so existing skills remain valid. **New skills keep the description to ≤45 words.** Reason: every skill description is part of the skill listing the agent sees in every session. Claude Code truncates that listing at 1 % of the context window and cuts each single description at 1,536 characters; a listing with many long descriptions loses the tail of the trigger text or whole skills. A short description (situation plus the two or three strongest trigger terms) keeps every skill fully visible.

### `description` quality bar

**Too thin** (fires on everything):
> *"Use for DDD modeling in Jardis."*

**Good** (narrow situation + explicit trigger surface):
> *"Wiring Designer-generated Commands/Queries into a transport layer — bootstrap lifetime, 4-hop Api-Registry call chain, DomainResponse→transport mapping, error handling for HTTP / CLI / queue / worker."*

Why the second works: one situation (transport-layer wiring), four discoverable sub-triggers (bootstrap, call chain, response mapping, error handling), four transports named explicitly. An agent scanning descriptions can match any of these and load the right skill.

**Checklist:**

- [ ] One sentence, single line, ≤175 words (new skills ≤45).
- [ ] Names a concrete situation, not a topic.
- [ ] Names ≥2 concrete sub-triggers (artefacts, methods, call-chain stages, file names).
- [ ] No vague "for X-related tasks" filler.

---

## 3. Zones

Every skill belongs to exactly one zone. Zone determines when the skill should fire.

| Zone | Meaning | Examples |
|---|---|---|
| `pre` | Before the developer enters the Jardis Designer. AI helps prepare Designer input. | `design-draft-schema` |
| `post-active` | After Designer-generated code exists. AI actively guides implementation or wiring. | `generated-code-extend`, `generated-code-wire-transport` |
| `post-reference` | After Designer-generated code exists. AI is consulted to interpret artefacts. | `tools-definition` |
| `crosscut` | Universal rules that apply across phases. | `foundation-architecture`, `foundation-patterns`, `foundation-testing` |
| `discovery` | Cross-phase capability discovery *before* building a reusable component. AI consults this skill to learn which Jardis package already covers the need and can recommend `composer require`. | `packages-find-existing` |
| `process` | Orchestrating a development process: which tier a task needs, writing concept, PRD and plan, running and verifying stages, closing. The skill tells the agent what to do next and which role to hand off to. | `process-*` skills |

Zones are stable categories — do not invent new ones without a deliberate, documented extension (v5 added `discovery` and v6 added `process` as such extensions). If a new skill doesn't fit any zone, the skill scope is probably wrong.

---

## 3a. Personas

Every bundle skill serves **exactly one** of five personas. The `persona:` frontmatter field declares which one. If a skill cannot decide, its scope is wrong — split or rescope before writing.

| Persona | Who / When | What they need |
|---|---|---|
| **A — Greenfield-Schema-AI** | Human + AI, *before* the Designer (path 1.3): user has neither DB nor schema, wants AI help drafting a `Schema.yaml` to import into the Designer. | Schema-YAML vocabulary, authoring heuristics. |
| **C — Implementer-AI (Phase 3.2)** | AI, *after* Flow build, with an Action stub + Requirement-Header in the DocBlock. **Primary bundle consumer.** | Platform-Dir layout, V1–V12, Aggregate / BC / Domain API, response shapes (X-1), ClassVersion, DomainResponse, patterns, tests, **Werkzeugkasten** (which package skill covers cache / mail / queue / repository / …). |
| **D — Application-Layer-Dev** | Human + AI, *outside* Jardis, after Aggregate + Flow build. Writes the transport layer (HTTP / CLI / queue / worker) that calls the Domain API. | Call chain, bootstrap lifetime, `DomainResponse` → transport mapping, response envelope shape. |
| **X — Discovery-Agent** | Any agent, *cross-phase*, before self-building a reusable component (infrastructure or DDD scaffold). Consults a `discovery` skill to learn which Jardis package already covers the need. | Package name, capability summary, "use when" trigger, `composer require` command. |
| **O — Process-Orchestrator** | Human + AI running a development process (zone `process`): picks the tier, writes concept, PRD and plan, runs stages with fresh agent sessions, verifies, closes. | The order of steps, the hand-off between roles, the caps of §12 and the behaviour rules they carry. |

**Retired / out-of-bundle:**

- **B (Designer-Companion)** — struck. The Jardis Designer has no AI hooks; steps 1.1, 1.2, 2, 3.1 are pure click-work in the UI.
- **E (Builder-Dev)** — lives in `tools-builder-engine` / `tools-builder-ui` (Builder repo, `tools/builder/.claude/skills/`). All Generator-Internas (renderer paths, IR structs, pipeline stages, `.go` file references, line numbers) belong there, never in the bundle.

The validator enforces (a) the `persona:` field is one of `A`, `C`, `D`, `X`, `O` (v6); (b) bundle skills must not contain Generator-Internas tokens in the body (§10).

---

## 4. Body structure

**Choose the shape that fits the content — no fixed heading template.** The body is free-form Markdown with these invariants:

1. **Use numbered topical sections.** Sections are typically introduced as `### 1. <Topic>`, `### 2. <Topic>`, …. Level `##` is reserved for optional framing sections at the very top (`## Scope`, `## Driving rules`). This gives the AI a stable lookup surface ("see §3") and encourages the author to think in short, self-contained chunks.
2. **End with pointers.** The last section links to sibling skills, companion `examples/` artefacts, and external reference files. Common names: `### N. Reference`, `### N. Anchors`. Never repeat content that lives in another skill — link to it.
3. **Imperative voice for AI instructions** ("Read the file …", "Ask the user …"); descriptive voice for reference content. Present tense.
4. **Code samples are focused and minimal.** Use language hints (```yaml`, ```php`). 3–40 lines per block is the normal range.
5. **No multi-paragraph narrative.** If a section grows past ~30 lines, split it.

The body has no required heading names. The [reference skeleton in §8](#8-reference-minimal-valid-skill) shows a typical shape; existing skills demonstrate variants.

---

## 5. Length budgets

Hard ceilings. If a skill needs more, content belongs elsewhere or in a companion file under `examples/`.

| Skill type | Max lines (incl. frontmatter) | Notes |
|---|---|---|
| `crosscut` (rules-*) | 225 | Terse reference material |
| `pre` / `post-reference` | 250 | Format documentation + pointers to `examples/` |
| `post-active` | 700 | Implementation / wiring guidance with realistic code samples |
| `discovery` | 150 | Thin capability catalog; no API detail, no code samples |
| `process` | 250 | Process steps, hand-off rules and caps; templates and long artefacts live in companion files |

**Counting:** `wc -l skills/<name>/SKILL.md`. Code blocks count; `examples/` files do not count against the budget.

---

## 6. Companion `examples/` directory

Full working artefacts (Schema.yaml, Aggregate.yaml, controller code, …) that illustrate the skill live in a `skills/<name>/examples/` sibling of `SKILL.md`. Rules:

- Reference them by relative path in the body: `examples/Counter/Aggregate.yaml`.
- Keep inline code blocks minimal; use `examples/` for anything >40 lines.
- `examples/` files are not validated for length or format — they are raw artefacts.
- Do not duplicate content between `examples/` and the body: the body sketches, `examples/` is the full thing.

---

## 7. Linking rules

Skills link to each other by name. Format:

> *See `foundation-architecture` §3 (Closure-Orchestrator) for the orchestrator pattern.*

**Allowed:**

- Reference other skills by name. `prerequisites` and `next` are checked against the skill folders (§11).
- Reference files in this repo by relative path: `docs/PRD-skill-overhaul.md`, `examples/Counter/Aggregate.yaml`.
- Reference Jardis package skills by name (`adapter-cache`, `support-repository`) — assume they are installed via the plugin.
- Reference external repos by absolute path **only** in the final reference section: `<repo>/internal/definition/schema.go`.

**Forbidden:**

- Inline-duplicating content from another skill ("here are the patterns again …").
- References to a skill that does not exist, or to a retired skill name (checked for `prerequisites` / `next`, §11).
- Web URLs in the body (use them sparingly in the final reference section only).

---

## 8. Reference: minimal valid skill

```markdown
---
name: example-skill
description: Reference for <narrow-situation> — <trigger-1>, <trigger-2>, <trigger-3>. Use when <concrete-context>.
zone: post-reference
persona: C
prerequisites: []
next: [generated-code-extend]
---

## Scope

(Optional framing paragraph. One or two sentences on when this skill applies
and how it sits relative to sibling skills.)

### 1. <First topic>

(Topical chunk — prose, table, or code block. Self-contained.)

### 2. <Second topic>

(Another chunk.)

### 3. Reference

- Companion example: `examples/<Artefact>.yaml`
- Adjacent skill: `<other-skill-name>`
- External file (final ref only): `/absolute/path/to/source.go`
```

Every existing bundled skill follows this shape — consult `skills/generated-code-wire-transport/SKILL.md` or `skills/design-draft-schema/SKILL.md` for full examples.

---

## 9. Authoring workflow

When writing or revising a skill:

1. **Draft frontmatter first.** If the `description` does not pass the §2 checklist, the skill scope is unclear — fix the scope before writing the body.
2. **Pick the zone.** If unclear, the skill probably straddles two zones — split or merge.
3. **List `prerequisites` and `next`.** Phase thinking before content.
4. **Sketch the sections.** Numbered topics, each with a one-line purpose. Prefer 3–7 sections.
5. **Self-review:** does any section repeat content that lives in another skill? Replace with a link.
6. **Length check:** `wc -l SKILL.md`. Over budget → move long artefacts to `examples/` or cut.
7. **Validator check:** `make validate-skills`.
8. **Trigger check:** read the description out loud. Would you load this skill given that sentence?

---

## 10. Persona purity (v4)

Two enforced rules keep bundle skills lean and resilient to Builder churn.

### 10.1 No Generator-Internas in bundle bodies

Bundle skills (every persona) MUST NOT cite:

- `.go` file paths or line numbers (`command_handler.go:550-555`, `internal/builder/...`, `tools/builder/internal/...`).
- Renderer / pipeline / IR class or function names: `Render*`, `Stage*`, `PHPRenderer`, `IR`, `BuildEntities`, `BuildAggregates`, `BuildFlow`, `BuildPlatformFacade`, `BuildIntegrationAggregate`, `BuildIntegrationBC`, `BuildIntegrationDomain`.

If the implementer needs to know that something happens during the build (e.g. "the build aborts on ambiguous business keys"), describe **what the implementer observes** — never how the Generator achieves it. That implementation-side content belongs in `tools-builder-engine` (Persona E, Builder repo).

The validator scans the body for these tokens. The **final reference section** (the section after the last `### N. Anchors` / `### N. Reference` heading) is allowed to mention package-skill names freely as cross-refs — it is not scanned for forbidden tokens.

### 10.1a Current truth only — no changelog language

A skill states the **current truth** of the system, never its history. The consuming AI has no
prior version to compare against — every "since refactor X", "no longer", "moved from", "replaced
the former Y" burns tokens on a delta the reader cannot use, and goes stale at the next refactor.

- **Forbidden in the body:** refactor names and dates as provenance ("since the Kernel-Entkopplung
  (2026-07)", "BC-Fassaden-Schichtung Stufe 1, 2026-07-11"), internal planning references
  ("PRD P6", "Vertrag 5", "the F11 follow-up"), and Builder-repo doc paths
  (`docs/kernel-decoupling/`, `docs/rules-layer/`, …) — the consumer project cannot reach them.
  Allowed exception: the **final reference section** (§7) may cite external repos/files.
- **Allowed:** negative statements of current truth ("there is no `Platform/` segment",
  "`jardiscore/foundation` does not exist") and short *term* mappings where the old term still
  appears in real artefacts ("früherer Begriff: „Haustür"", "formerly ForceOverwrite No-Op") —
  these help the AI recognise stale material, unlike provenance, which only narrates.
- **Version floors are facts, not history:** "requires `jardiscore/kernel` ≥ 1.1.0" is fine.

Where the delta matters to maintainers, it belongs in `CHANGELOG.md` or the PRD — not in the skill.

### 10.2 Werkzeugkasten cross-references (`generated-code-extend`)

`generated-code-extend` MUST carry a Werkzeugkasten section that maps each Jardis runtime concern (Cache, Mail, DB, Logger, Events, Filesystem, Auth, …) to the responsible package skill (`adapter-cache`, `adapter-mailer`, `adapter-dbconnection`, …). Without it, Persona C cannot discover which package skill answers "how do I cache / send mail / publish a message / …" — and re-derives APIs from memory, which is exactly the failure mode the bundle is designed to prevent.

Other skills MAY link to package skills when relevant, but only `generated-code-extend` is required to keep the full table.

---

## 11. Link check (v6)

`CheckSkillLinks` reads `prerequisites` and `next` of a skill and requires every entry to name a skill folder (a directory containing a `SKILL.md`) in the same skills root as the checked skill. Two violations:

- **Retired name** — the entry is an old skill name from an earlier release. The message names the current name to use. This holds even when a redirect skill of the old name sits in the same folder: redirects are for readers of old material, not for new links.
- **Unknown name** — no skill folder of that name exists (this includes entries that are not plain kebab-case names).

Redirect skills (description `Renamed to <new>. Load <new> instead.`) carry empty `prerequisites` / `next` and pass the complete validator.

---

## 12. Rule markers and cap figures (v6)

Behaviour rules of the `process` skills are stated as literal text, not only as prose that could drift. A rule is written as the marker line `<!-- rule:<id> -->` with the rule text directly below it. `CheckRuleMarkers` greps each listed skill for the marker and for the mandatory keywords of the rule. It proves that the rule is **present** in the skill, not that an agent follows it.

| Skill | Marker id | Mandatory keywords |
|---|---|---|
| `process-choose-tier` | `chat-end-offer` | `create a project folder?`, `docs/vorhaben/`, `carry knowledge into the pool?` |
| `process-choose-tier` | `tier-escalate` | `only with a named reason`, `the lower tier`, `two or more subtasks are never` |
| `process-choose-tier` | `decide-yourself-no-tier-drop` | `lowers no tier`, `waives no gate` |
| `process-run-stage` | `fresh-session-per-stage` | `fresh agent session` |
| `process-run-stage` | `failure-path` | `fix run`, `follow-up run`, `STOPP:` |
| `process-run-stage` | `question-points` | `at most 2 question points`, `STOPP:` |
| `process-review-board` | `question-points` | `at most 2 roles` |
| `process-concept` | `pool-scaffold` | `.claude/wissen/`, `is missing` |
| `process-concept` | `project-profile` | `.claude/PROJECT_PROFILE.md`, `is missing` |

Keywords are literal, case-sensitive substrings of the skill text (English); write the rule text so that it contains them verbatim.

**Cap figures** are conventions of this format. Each figure must appear in the named skill; a figure that consists only of digits must appear as a whole number (`5` is not satisfied by `15`).

| Skill | Cap | Figures |
|---|---|---|
| `process-write-plan` | stage plan length, referenced plan size | `150` (lines), `16 KB` |
| `process-run-stage` | implementer brief size, commitments per brief, context per brief | `6 KB`, `8`, `30 KB` |
| `process-verify` | verdict items, red evidence items | `5`, `3` |

The two tables live as constants in `CheckRuleMarkers` (`RULES`, `CAPS`). Every marker and every cap figure is mandatory: a skill from the tables whose `SKILL.md` does not exist in the checked skills root is a violation, not skipped.
