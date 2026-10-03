---
name: knowledge-record-decision
description: Use when a decision is made or a non-obvious bug is fixed and the knowledge pool must learn from it — entry with date, author and source, the Wissen commit note for feat and fix commits, bugfix lesson criteria.
zone: process
persona: O
profile: core
prerequisites: [foundation-working-principles, knowledge-maintain-pool]
next: []
---

## Scope

A decision that is not written down gets made a second time, often differently. This skill says what to record, where, and how the commit proves it. Layout, caps and upkeep of the pool are in `knowledge-maintain-pool`; read that first if the pool is new to you.

### 1. Record a decision

Decide where it belongs: the topic page of the subject, or a new page if none fits (then add its line to `INDEX.md`).

- Add one line under `## Entscheide`: `Datum · Urheber · Entscheid · Beleg`.
  - Datum: ISO date, `YYYY-MM-DD`.
  - Urheber: who decided (a person or role), not who typed it.
  - Beleg: commit hash, `path:line`, `[[page]]` or a dated source. A line without source is invalid.
- If the decision changes what holds, update the sentence in `## Stand` with its source. Fold the change in; do not stack a second, contradicting sentence next to the old one.
- If the decision replaces an earlier one, move the old fact to `## Ersetzt` with its date.
- If the page hits a cap, condense per `knowledge-maintain-pool`.

Decisions come from the user or from a documented rule. An agent that chose on its own says so in `Urheber`; it does not attribute the choice to someone else.

### 2. The commit note

Every `feat:` and `fix:` commit carries exactly one note line in the message body:

- `Wissen: <seite>#<abschnitt>` when the commit changed the pool. `<seite>` is the page id, `<abschnitt>` is one of `stand`, `entscheide`, `fallen`, `ersetzt`, `verweise`.
- `Wissen: keins – <Grund>` when nothing in the pool needs to change. Write the reason in one short phrase. The dash is the en dash U+2013, written literally, with a space on each side.

Other commit types (`docs:`, `test:`, `refactor:`, `chore:`) may carry the note but do not need it. A `feat:` normally extends `## Stand` or `## Entscheide`, or creates a page.

### 3. Bugfix lesson

A fix earns a pool entry only if all four criteria hold:

1. The cause was not obvious from the code.
2. The defect is a regression or would come back.
3. It is a trap a later change can fall into again.
4. The pool was wrong or had a gap on this point.

If any criterion fails, write `Wissen: keins – <Grund>`. If all four hold, add 2 to 4 lines under `## Fallen`:

`Symptom: … Ursache: … Regel: … Beleg: …`

The rule must be something a reader can act on before the trap is hit. The source is the fix commit or the test that pins it.

### 4. Before you commit

1. The entry has date, author and source.
2. `INDEX.md` lists every page, including a new one.
3. The pool check passes: `php vendor/jardis/dev-skills/scripts/pool-check.php`.
4. The commit message carries the note line from section 2.

### 5. Reference

- Layout, caps, condensing: `knowledge-maintain-pool`
- Working rules for every task: `foundation-working-principles`
