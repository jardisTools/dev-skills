---
name: process-resume
description: Use to continue a running undertaking in a fresh session — find the active progress file, honour STOPP markers, run the preflight, take up exactly one next step; also when a project folder of the same name already exists.
zone: process
persona: O
prerequisites: [process-choose-tier]
next: [process-write-prd]
---

## Scope

A fresh session knows nothing of the last one. The progress file is the single living record of an undertaking: its head says where it stands and what comes next. This skill finds that file, checks that the ground still is what the file assumes, and takes up one step. It does not guess which undertaking is meant and does not work around a red check.

### 1. Find the active progress file

List `docs/vorhaben/*/`. A folder with a `PROGRESS.md` whose `## Kopf` says `Phase` other than `close` is active.

- The human named the undertaking: use `docs/vorhaben/<name>/PROGRESS.md` and skip the search.
- Exactly one is active: that is the run.
- Several are active: show the human a list (name, phase, stage, next step) and ask which one. Never pick by file time or guess.
- None is active: report that no undertaking is running and start nothing. Name every folder without a `PROGRESS.md`; it is not an undertaking, and the human decides what to do with it.
- A new undertaking is not started here; that is `process-concept`.

### 2. Read the head first

Read the whole `PROGRESS.md`. The head holds `Phase`, `Stage`, `Next step` and `Open decisions`. Open other files only when the next step needs them; the progress file points to them and is not the archive.

**Hard stop:** a `STOPP:` entry under `Open decisions` blocks all autonomous work. Put the open question to the human. Only the human's answer clears the entry; then the main session removes it.

### 3. Preflight

Run every check before the first step. A red check stops the resume: clarify it, ask the human if needed, then work. Never skip past it.

| Check | How |
|---|---|
| Working tree | `git status --short` and the current branch and commit; clarify leftovers of an aborted run first |
| Orphaned agent tasks | Are background tasks or processes of the last session still running? An aborted worker otherwise keeps building unseen |
| Artefact freshness | After the commit changed, rebuild binary or generated code before any QA run; otherwise the gate measures the old state |
| Containers and ports | List the running containers; clear leftovers of an aborted QA run; look for foreign processes on the test ports |
| Project profile | Read `.claude/PROJECT_PROFILE.md`; when it is missing, create it as `process-concept` describes |

When the progress file has its own `## Preflight` section, run those checks as well.

### 4. Take up one step

1. Take the `Next step` of the head and do exactly that one action. Start only work the file lists as open; never repeat finished work.
2. The phase names the skill that carries the work:

| Phase | Skill |
|---|---|
| `concept` | `process-concept` |
| `prd` | `process-write-prd` |
| `prd-review`, `plan-review` | `process-review-board` |
| `plan` | `process-write-plan` |
| `stage` | `process-run-stage` |
| `acceptance` | `process-verify` |
| `close` | `process-close` |

3. The guard rails of the progress file hold unchanged: scope, prohibitions, rulings.
4. After each building block update the progress file: move the head on, shrink a finished stage to one line, do not append a chronicle. The file stays at most 60 lines and only the main session writes it.

### 5. Reference

- Start a new undertaking and the file format: `process-concept`
- Pick the tier: `process-choose-tier`
- Check head and length: `php vendor/jardis/dev-skills/scripts/pool-check.php`
