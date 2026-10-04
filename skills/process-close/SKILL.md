---
name: process-close
description: Use when the acceptance gate is green — triage every open point and carry lessons into the pool before the human accepts; after the acceptance sync docs once, write the digest, archive the project folder, deliver once, run the retro.
zone: process
persona: O
profile: core
prerequisites: [process-verify]
next: [knowledge-record-decision]
---

## Scope

Closing runs **once per undertaking** and the same way in every repository. Afterwards nothing of the undertaking is left open, nothing is lost and nothing is kept twice: the lessons sit in the pool, the account sits in one page, the folder is archived. The main session runs it; a single stage without a folder of its own is one line in the digest.

The order is fixed: triage (2) → lessons (3) → the human accepts, the head becomes `close` → docs sync and digest (4) → archive the folder (5) → delivery (6). Triage and lessons are data in the progress file and come before the acceptance, so the human accepts with them in sight. The folder is archived before the delivery, so that the delivery carries no working papers.

### 1. Entry

Read `docs/vorhaben/<name>/PROGRESS.md`. There are two entries:

- Head phase `acceptance`, the acceptance gate `GREEN` and its result written as `## Acceptance check` (`process-verify` section 5): run sections 2 and 3, then hand back to `process-verify`, which shows the human the result and writes the head phase `close` only when the human accepts.
- Head phase `close`: the human accepted. Continue with section 4, and with the next section that has no result yet after an interruption.

Any other head: go back to `process-verify`.

### 2. Carry-over triage

Every open point from `## Open points` and from the open decisions (the progress head and every decision of the PRD that is not `answered`) gets **exactly one** of four decisions. Noting it is not a decision. The triage runs before the acceptance; the head still says `acceptance`.

Each decision ends as **one line** in `## Open points`, in this form:

`- <text> — resolved|backlog|rejected — <due sentence>`

| Decision | Meaning | Line |
|---|---|---|
| Fix now | Done in this closing, with the gates of the project profile | `resolved`; the due sentence says what was done and which gate showed it |
| Let the human decide now | A line in the list of rulings for the human, not in a backlog | The line is written only after the answer, as `resolved`, `backlog` or `rejected` by that answer; a point without an answer has no line and blocks the acceptance |
| Strike | With the reason in one line | `rejected`; the due sentence is the reason |
| Own item | Only when it names a trigger ("how to tell it is due") and a consequence ("what breaks if never"); one line per item in the backlog file named by the line "Backlog file" of `.claude/PROJECT_PROFILE.md`, no fixed path | `backlog`; the due sentence names the trigger and the consequence |

A point without a nameable consequence is struck. Collective blocks are forbidden: no "remaining items", "follow-ups" or "leftover debts" of an undertaking. A bare line `- <text>` without a status is not triaged yet; the acceptance does not start while one is left.

### 3. Lessons into the pool

This section runs before the acceptance as well. Every lesson of the run is one line in `## Knowledge`, in this form:

`- yes|no — <entry>`

`yes` is a lesson that goes to its place now, `no` one that is struck (a lesson that fits no place below is struck). The entry names the place. When there is nothing to propose, one line `- no — nothing to propose` is enough and counts as done; an empty section does not.

A `yes` lesson goes to exactly one place:

- a **principle of the way of working**: one sentence in the project's rules;
- a **concrete fact** (command, path, port, skill injection): `.claude/PROJECT_PROFILE.md`, one heading per topic, one fact per line;
- **topic knowledge or a basic decision**: the sections `Stand`, `Entscheide` (date, author, source) or `Fallen` of the responsible topic page, with `knowledge-record-decision`; no separate entry per decision.

There is no lessons file that everyone must read.

<!-- rule:close-lessons-via-skill -->
Load `knowledge-record-decision` before the first write to a topic page, and write every entry in its format, never from memory of the format.

### 4. Docs sync and digest

Runs only after the human accepted, with the head phase `close`.

**Docs sync, once.** One sub-agent updates only the documents that have a **real change**: READMEs and changelogs of the touched packages, the agent instructions of the affected repositories, component skills in the source repository, linked `docs/`. Investigate first (grep, skill manifest), then adapt selectively. No delta, no run.

**The digest.** The digest is mandatory, one page, an account and not an entry point. Path: `docs/digests/digest-<name>-<jjjj-mm>.md` in the repository of the undertaking, with this skeleton:

```markdown
# Digest — <name> (<jjjj-mm>)
- **Period:** <from–to> · **Result:** <one sentence: what is true now>
- **Decisions:** <one link per line to the topic page of section 3>
- **Figures:** <stages · runs · duration>
- **Last git hash:** <SHA> (`git show <SHA>` carries the detail)
- **Stages without a folder of their own:** <one line each with hash>
```

If the project keeps its documents out of the commit (switch `process-docs` set to `local`), the digest stays out of the commit too: write it at the same path, never stage it and never touch the project's ignore files.

Commit the docs sync and the digest per `git-commit-change`. The folder is still there; it is archived next, before the delivery.

### 5. Archive the folder

Runs after section 4 is committed and before any delivery. The archive keeps the working papers at hand on the machine; `main` carries only code, pool pages and the digest.

1. Bring the project's docs index, if it has one, to the final state.
2. `mkdir -p tmp/archiv`. The archive folder is covered by the managed block in `.git/info/exclude` (both modes); if the line `tmp/archiv/` is missing there, append it to `.git/info/exclude` once — never touch `.gitignore`.
3. **Move** `docs/vorhaben/<name>/` to `tmp/archiv/<name>/` (project-relative). The folder is not deleted.
4. Committed mode: commit only the staged removal of `docs/vorhaben/<name>/` per `git-commit-change`. With `process-docs: local` the folder never was in the index: move it without a commit.

The digest stays where section 4 put it, in `docs/digests/`.

<!-- rule:close-pool-check-after-delete -->
Before the delete (the move into the archive), no source of a pool page may point into `docs/vorhaben/<name>/`: a source names a commit hash or the digest. Run `php vendor/jardis/dev-skills/scripts/pool-check.php` on the final state only after the delete, and report "no errors" only from this run, never from one made before the delete.

### 6. Delivery

The delivery goes out only after the folder is archived (section 5). Deliver **once**, at the end, over the release path of the project (`git-push-and-open-pr`, commits per `git-commit-change`); commit and merge are a human gate (`process-run-stage`); with the delegated git rules the session makes the commits itself and only the merge stays a gate of the human. The pull request is merged by **squash**: one commit on the base, the branch is deleted on merge. No tag or release per phase; several releases only when the plan names release milestones. The protective stops of the release path apply unchanged. A `feat:` or `fix:` commit carries its `Wissen:` note line. The delivery carries the digest and the code, never the working papers of the undertaking.

### 7. Retro

While the run goes on, anything that fell out of the flow (a skipped step, a wrong stop, a phase that was too big, a guessed assumption) is noted at once under `## Open points`. Here it is evaluated and, where it calls for one, a change of the process is proposed. The human decides; the process never changes itself autonomously. Without notes, write one line.

### 8. Reference

- Previous step: `process-verify`
- Next step: `knowledge-record-decision` (entry format, commit note, bugfix lessons)
- Pool layout and caps: `knowledge-maintain-pool`
- Commit and pull request: `git-commit-change`, `git-push-and-open-pr`
