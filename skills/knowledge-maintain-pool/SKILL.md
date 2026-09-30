---
name: knowledge-maintain-pool
description: Use when a project keeps decisions, pitfalls and current facts in a knowledge pool under .claude/wissen/ — INDEX.md and topic page layout, size caps, condensing instead of appending, the pool check, the scaffold rule.
zone: process
persona: O
prerequisites: [foundation-working-principles]
next: [knowledge-record-decision]
---

## Scope

The knowledge pool is the project's memory of what holds, what was decided and which traps exist. It lives in `.claude/wissen/` inside the project. An agent reads the pool before a format, architecture or emission decision and keeps it short enough to read whole. This skill describes the layout, the caps and the upkeep. Writing a single entry is `knowledge-record-decision`.

### 1. Layout

```
.claude/wissen/
  INDEX.md          entry point: one line per topic page
  <topic>.md        one topic page per subject
```

- `INDEX.md` is the only entry point. It lists every topic page as `[[page]] — one-line description`. Start here, then open only the pages the task touches.
- A topic page holds one subject: what holds today, the decisions behind it, the traps. Use `templates/themenseite.md` as the starting point, `templates/INDEX.md` for the index.
- Pages link to each other as `[[page]]`. A link names a page id, not a path.

### 2. The scaffold is created by the agent, never by the plugin

If `.claude/wissen/` is missing, the agent creates it through `process-concept`: an empty `INDEX.md` and the topic-page template. The plugin never writes the scaffold. An existing `.claude/wissen/` is never overwritten or reformatted on install or update; it belongs to the project.

### 3. Topic page shape

Frontmatter (all keys present): `id`, `description` (one sentence, at most 300 characters), `heimat` (the project slug), `ersetzt` (list of page ids this page absorbs, `[]` if none), `schema_version`.

Exactly five sections, in this order, with these literal German headings. The commit note and the check script name them that way.

| Section | Holds |
|---|---|
| `## Stand` | What holds today: one fact per sentence, each with its source (commit hash, `path:line`, `[[page]]` or date). No history. |
| `## Entscheide` | One line per decision: `Datum · Urheber · Entscheid · Beleg`. A line without source is invalid. |
| `## Fallen` | One entry per trap: `Symptom: … Ursache: … Regel: … Beleg: …`. |
| `## Ersetzt` | One line per replaced page id: what moved here, and where the rest went. |
| `## Verweise` | Links to non-pool sources and `[[page]]` links to related pages. |

All five sections are mandatory, in this fixed order. An empty section carries a one-line placeholder (for example "Nothing yet."); the check script reports a missing heading. Any other `##` heading is a violation.

### 4. Caps

| Object | Cap |
|---|---|
| Topic page | at most 16,384 bytes and at most 160 lines |
| `INDEX.md` | under 10,240 bytes |

The caps exist so an agent can read a page or the index in one go. A page that hits the cap is split by subject or condensed; it is never trimmed by deleting sources.

### 5. Upkeep by condensing

The pool is condensed, not appended to.

1. A new fact replaces the old sentence in `## Stand`; the old sentence, if it still proves something, moves to `## Ersetzt` with a date.
2. A decision that has become the norm is folded into `## Stand`; the line stays in `## Entscheide` as the record of who decided when.
3. A page that loses all content is deleted and its id is listed in `ersetzt` of the page that took over.
4. After every change, `INDEX.md` still has one line per page and no line for a deleted page.
5. History belongs to git, not to the pool.

### 6. Check

`php vendor/jardis/dev-skills/scripts/pool-check.php` checks the sections and their order, the links and redirects, the path references and the size caps, and the head and size caps of the progress and plan files under `docs/vorhaben/`; it also runs as `vendor/bin/pool-check.php`, `--root=<dir>` names the project root and `--help` lists the options. Run it after every pool change and fix what it reports.

### 7. Reference

- Writing one entry and the commit note: `knowledge-record-decision`
- Working rules for every task: `foundation-working-principles`
