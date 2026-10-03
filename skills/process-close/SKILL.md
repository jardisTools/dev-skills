---
name: process-close
description: Use when the acceptance gate is green and the human has accepted — triage every open point, carry lessons into the pool, sync docs once, write the digest, delete the project folder, deliver once, run the retro.
zone: process
persona: O
prerequisites: [process-verify]
next: [knowledge-record-decision]
---

## Scope

Closing runs **once per undertaking** and the same way in every repository. Afterwards nothing of the undertaking is left open, nothing is lost and nothing is kept twice: the lessons sit in the pool, the account sits in one page, the folder is gone. The main session runs it; a single stage without a folder of its own is one line in the digest.

### 1. Entry

Read `docs/vorhaben/<name>/PROGRESS.md`. The head must say phase `close`: the acceptance gate was green and the human accepted. If it does not, go back to `process-verify`.

### 2. Carry-over triage

Every open point from `## Open points` and from the open decisions (the progress head and every decision of the PRD that is not `answered`) gets **exactly one** of four decisions. Noting it is not a decision.

| Decision | Meaning |
|---|---|
| Fix now | Done in this closing, with the gates of the project profile |
| Let the human decide now | A line in the list of rulings for the human, not in a backlog |
| Strike | With the reason in one line |
| Own item | Only when it names a trigger ("how to tell it is due") and a consequence ("what breaks if never") |

A point without a nameable consequence is struck. Collective blocks are forbidden: no "remaining items", "follow-ups" or "leftover debts" of an undertaking.

### 3. Lessons into the pool

Every lesson of the run goes to exactly one place:

- a **principle of the way of working**: one sentence in the project's rules;
- a **concrete fact** (command, path, port, skill injection): `.claude/PROJECT_PROFILE.md`, one heading per topic, one fact per line;
- **topic knowledge or a basic decision**: the sections `Stand`, `Entscheide` (date, author, source) or `Fallen` of the responsible topic page, with `knowledge-record-decision`; no separate entry per decision;
- otherwise: strike.

There is no lessons file that everyone must read.

<!-- rule:close-lessons-via-skill -->
Load `knowledge-record-decision` before the first write to a topic page, and write every entry in its format, never from memory of the format.

### 4. Docs sync, once

One sub-agent updates only the documents that have a **real change**: READMEs and changelogs of the touched packages, the agent instructions of the affected repositories, component skills in the source repository, linked `docs/`. Investigate first (grep, skill manifest), then adapt selectively. No delta, no run.

### 5. The digest

The digest is mandatory, one page, an account and not an entry point. Path: `docs/digests/digest-<name>-<jjjj-mm>.md` in the repository of the undertaking, with this skeleton:

```markdown
# Digest — <name> (<jjjj-mm>)
- **Period:** <from–to> · **Result:** <one sentence: what is true now>
- **Decisions:** <one link per line to the topic page of section 3>
- **Figures:** <stages · runs · duration>
- **Last git hash:** <SHA> (`git show <SHA>` carries the detail)
- **Stages without a folder of their own:** <one line each with hash>
```

If the project keeps its documents out of the commit (switch `process-docs` set to `local`), the digest stays out of the commit too: write it at the same path, never stage it and never touch the project's ignore files.

### 6. Delete the folder

Bring the project's docs index, if it has one, to the final state. Then **delete** `docs/vorhaben/<name>/`. There is no archive: the git history carries the account and the digest is the history within reach. A folder that stayed local needs no commit for its deletion.

<!-- rule:close-pool-check-after-delete -->
Before the delete, no source of a pool page may point into `docs/vorhaben/<name>/`: a source names a commit hash or the digest. Run `php vendor/jardis/dev-skills/scripts/pool-check.php` on the final state only after the delete, and report "no errors" only from this run, never from one made before the delete.

### 7. Delivery

Deliver **once**, at the end, over the release path of the project (`git-push-and-open-pr`, commits per `git-commit-change`); commit and merge are a human gate (`process-run-stage`); with the delegated git rules the session makes the commits itself and only the merge stays a gate of the human. No tag or release per phase; several releases only when the plan names release milestones. The protective stops of the release path apply unchanged. A `feat:` or `fix:` commit carries its `Wissen:` note line.

### 8. Retro

While the run goes on, anything that fell out of the flow (a skipped step, a wrong stop, a phase that was too big, a guessed assumption) is noted at once under `## Open points`. Here it is evaluated and, where it calls for one, a change of the process is proposed. The human decides; the process never changes itself autonomously. Without notes, write one line.

### 9. Reference

- Previous step: `process-verify`
- Next step: `knowledge-record-decision` (entry format, commit note, bugfix lessons)
- Pool layout and caps: `knowledge-maintain-pool`
- Commit and pull request: `git-commit-change`, `git-push-and-open-pr`
