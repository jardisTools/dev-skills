# Skill Format — Authoring Standard

**Status:** v3 · 2026-04-23

This document is the single source of truth for **how to write** a bundled skill in this repository. Every `SKILL.md` under `skills/<name>/` MUST follow this format.

> **v3 vs. earlier drafts.** Earlier revisions prescribed a fixed five-heading body template (`## When this skill applies`, `## What the AI does`, …) and a 30-word description cap. Hands-on iteration showed that reference-heavy skills (e.g. `tools-definition`, `platform-implementation`) communicate more clearly with a topical numbered structure and denser trigger descriptions. v3 replaces the prescriptive template with a lighter set of invariants and lets each skill pick the section structure that fits its content. The validator (`bin/validate-skills.php`) enforces only the invariants below.

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
zone: pre | post-active | post-reference | crosscut
prerequisites: [<other-skill-name>, ...]   # may be empty []
next: [<other-skill-name>, ...]            # may be empty []
---
```

### Field rules

| Field | Required | Rules |
|---|---|---|
| `name` | yes | Kebab-case. Matches the directory name. Reserved prefixes apply: `plan-*`, `platform-*`, `rules-*`, `tools-*`, `schema-*`. |
| `description` | yes | **One sentence**, single line, English. ≤60 words. Starts with the situation or artefact (`"Use when …"`, `"Reference for …"`, `"Extending …"`, `"Wiring …"`). Dense comma- or em-dash-separated trigger terms are welcome — the sentence is both the trigger prompt for the loader and the first piece of context the AI sees. |
| `zone` | yes | One of four values. See §3. |
| `prerequisites` | yes | Array of skill names that should have run before this skill is useful. Use `[]` if independent. |
| `next` | yes | Array of skill names that typically follow. Use `[]` if terminal. |

### `description` quality bar

**Too thin** (fires on everything):
> *"Use for DDD modeling in Jardis."*

**Good** (narrow situation + explicit trigger surface):
> *"Wiring Designer-generated Commands/Queries into a transport layer — bootstrap lifetime, 4-hop Api-Registry call chain, DomainResponse→transport mapping, error handling for HTTP / CLI / queue / worker."*

Why the second works: one situation (transport-layer wiring), four discoverable sub-triggers (bootstrap, call chain, response mapping, error handling), four transports named explicitly. An agent scanning descriptions can match any of these and load the right skill.

**Checklist:**

- [ ] One sentence, single line, ≤60 words.
- [ ] Names a concrete situation, not a topic.
- [ ] Names ≥2 concrete sub-triggers (artefacts, methods, call-chain stages, file names).
- [ ] No vague "for X-related tasks" filler.

---

## 3. Zones

Every skill belongs to exactly one zone. Zone determines when the skill should fire.

| Zone | Meaning | Examples |
|---|---|---|
| `pre` | Before the developer enters the Jardis Designer. AI helps prepare Designer input. | `schema-authoring` |
| `post-active` | After Designer-generated code exists. AI actively guides implementation or wiring. | `platform-implementation`, `platform-usage` |
| `post-reference` | After Designer-generated code exists. AI is consulted to interpret artefacts. | `tools-definition` |
| `crosscut` | Universal rules that apply across phases. | `rules-architecture`, `rules-patterns`, `rules-testing` |

Zones are stable categories — do not invent new ones. If a new skill doesn't fit, the skill is probably wrong.

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
| `crosscut` (rules-*) | 150 | Terse reference material |
| `pre` / `post-reference` | 250 | Format documentation + pointers to `examples/` |
| `post-active` | 550 | Implementation / wiring guidance with realistic code samples |

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

> *See `rules-architecture` §3 (Closure-Orchestrator) for the orchestrator pattern.*

**Allowed:**

- Reference other skills by name.
- Reference files in this repo by relative path: `docs/PRD-skill-overhaul.md`, `examples/Counter/Aggregate.yaml`.
- Reference Jardis package skills by name (`adapter-cache`, `support-repository`) — assume they are installed via the plugin.
- Reference external repos by absolute path **only** in the final reference section: `/Users/Rolf/Development/headgent/jardis/tools/builder/internal/definition/schema.go`.

**Forbidden:**

- Inline-duplicating content from another skill ("here are the patterns again …").
- References to a skill that does not exist.
- Web URLs in the body (use them sparingly in the final reference section only).

---

## 8. Reference: minimal valid skill

```markdown
---
name: example-skill
description: Reference for <narrow-situation> — <trigger-1>, <trigger-2>, <trigger-3>. Use when <concrete-context>.
zone: post-reference
prerequisites: []
next: [platform-implementation]
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

Every existing bundled skill follows this shape — consult `skills/tools-definition/SKILL.md` or `skills/platform-usage/SKILL.md` for full examples.

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
