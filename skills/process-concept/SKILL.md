---
name: process-concept
description: Use when a task is an undertaking (tier 3) and stage 0 starts — concept interview, understanding sheet, target picture or executable example, human approval, project folder docs/vorhaben/<name>/ with progress file, pool scaffold and project profile when missing.
zone: process
persona: O
prerequisites: [process-choose-tier]
next: [process-write-prd]
---

## Scope

Stage 0 turns an idea into an approved target picture before anything is specified or built. The result is a project folder with a progress file and a concept artefact the human has accepted. Nothing after this stage starts without that acceptance. The main session runs this stage and is the only writer of the files it creates.

### 1. Start

1. Take the name of the undertaking from the human, or propose one. It is kebab-case (`export-format`, not `Export Format`); ask again until it is.
2. Look at `docs/vorhaben/<name>/`:
   - It does not exist: go on.
   - It exists and holds a `PROGRESS.md`: stop this skill and continue with `process-resume`. Never overwrite, never re-create.
   - It exists without a `PROGRESS.md`: it is not an undertaking. Tell the human, name the folder, and ask whether to use another name. Create nothing inside it.
3. Run the two set-up rules of sections 2 and 3, each only when its condition holds.
4. Create `docs/vorhaben/<name>/PROGRESS.md` from `templates/PROGRESS.md`, title and goal filled in, the head left at phase `concept`. From then on the progress file carries the state. The understanding sheet (section 5) is created from `templates/UNDERSTANDING.md`.

If the project keeps its documents out of the commit (switch `process-docs` set to `local`), the folder stays local. Do not touch the project's ignore files to make that happen.

### 2. Pool scaffold

<!-- rule:pool-scaffold -->
Create the knowledge pool scaffold only when `.claude/wissen/` is missing: copy only `INDEX.md` from `skills/knowledge-maintain-pool/templates/` into `.claude/wissen/`, without the example line. `themenseite.md` stays a template in the skill; copy it only when the first real topic page is written. An existing `.claude/wissen/` is never changed, reformatted or filled in; it belongs to the project. Layout and caps: `knowledge-maintain-pool`.

### 3. Project profile

<!-- rule:project-profile -->
Create `.claude/PROJECT_PROFILE.md` from `templates/PROJECT_PROFILE.md` only when it is missing. An existing profile is never overwritten. Fill the placeholders from the project itself (Makefile targets, compose file, test configuration) and ask the human only for what the project does not show. The profile holds the project's concrete facts: QA entry, ports, build, pitfalls. Briefs, checkers and implementers read it instead of guessing.

### 4. Interview

Clarify one point at a time. Before the first question, read `.claude/PROJECT_PROFILE.md` when it exists (section 3 creates it only when it is missing); it holds the QA entry, ports and build facts, so the interview does not ask for what it already states.

1. Ask one question. Ask the next only when the current one is answered unambiguously.
2. Accept no silent assumption. Where the human has not said it, ask; where the project can answer, read it first (code, knowledge pool, `process-check-existing` for what the environment already does).
3. Cover at least: the goal in one sentence, who uses the result and how, what it must not do, the error and empty cases, what is explicitly out of scope.
4. Record every settled point in the concept artefact, not in a chat summary.

<!-- rule:decide-yourself-no-gate-waiver -->
A human's "decide open points yourself" or "proceed autonomously" waives no gate. A question the human left open and the project cannot answer (code, knowledge pool, target artefact) is never settled by the main session, neither in the concept, nor in the PRD, nor by ruling a board finding on it as resolved. It goes to the open-question gate (`open-question-gate`, see `process-run-stage`); what the gate cannot decide stays in the progress head as `STOPP: <YYYY-MM-DD> · <question>` and is put to the human at the next approval.

### 5. Concept artefact

The concept artefact has three forms. The understanding sheet is written for every undertaking; a picture or an executable example stands next to it wherever there is something to see.

| Form | Examples | File |
|---|---|---|
| Understanding sheet | the requirement in fixed sections | `docs/vorhaben/<name>/UNDERSTANDING.md`, from `templates/UNDERSTANDING.md` |
| Picture | HTML pages showing the screens, states and flows; a sketch | `docs/vorhaben/<name>/KONZEPT.html` |
| Executable example | a test, a golden file, a sample response | in the project's own test or fixture location, named in the progress file |

Apart from the sheet the artefact is never prose: the sheet is the one allowed text form, and its sections are fixed. Create `KONZEPT.html` for every undertaking that has something to see. For a change without a surface, the executable example carries the concept and `KONZEPT.html` states where it lives. Draft the artefacts while the interview runs and show them to the human as they grow.

The sheet has exactly these headings in this order, each kept even when empty:

- `## Occasion`: what triggered the undertaking.
- `## Problem`: what is wrong or missing.
- `## Goal`: what must be true at the end.
- `## Acceptance criteria`: a numbered list (`1.`, `2.`, ...), each criterion observable.
- `## Affected`: one line per thing, `` - <Kind> `<Name>` ``; the kind is one word for the sort of thing, for example `Aggregate`, `Process`, `Module`.
- `## Documents`: one line per document, a Markdown link with a path relative to the undertaking folder.

For a bug, two sections follow `## Goal`: `## Reproduced` and `## Cause`.

### 6. Approval

The human accepts the sheet and the picture or example; no one else does. Show them, name what it fixes and what it leaves open, and ask for a yes.

- **No yes, no next stage.** A change request goes back into the interview. The next stage, the PRD, starts from the approved sheet, not from the conversation.
- On approval, save a screenshot of the picture as `docs/vorhaben/<name>/KONZEPT.png` with the browser tool at hand. If none is available, say so and ask the human to save it.
- Set the progress head to phase `prd`, stage `—`, and a next step naming the PRD as one line. Fill in the goal and the path of the approved artefact. An open question stays in the head as `STOPP: <YYYY-MM-DD> · <question>` and blocks autonomous work.

### 7. Progress file rules

- `## Kopf` is the first heading after the title, with four lines in this order: `Phase`, `Stage`, `Next step`, `Open decisions`. Five free lines may follow, each optional, in this form:
  - `- **Title:**` the display name;
  - `- **Type:**` one of `feature`, `bug`, `project`;
  - `- **Ticket:**` free text;
  - `- **BC:**` comma-separated;
  - `- **Skipped:**` comma-separated, with the keys `concept`, `prd`, `plan`, `stage`, `acceptance`.
- `Phase` is one of `concept`, `prd`, `prd-review`, `plan`, `plan-review`, `stage`, `acceptance`, `close`.
- `Stage` is `—` before phase `stage` and `E<n>/<total>` from phase `stage` on, for example `E1/3`.
- `Next step` is one action in one line.
- `Open decisions` is `—` or one line starting `STOPP: <YYYY-MM-DD> · `. With several open questions keep that one line and number them: `STOPP: 2026-10-01 · (1) <first question> (2) <second question>`. Never a second `STOPP:` line; the check reads only the start of the value.
- The file has at most 60 lines. It holds state, not a chronicle; only the main session writes it.
- `php vendor/jardis/dev-skills/scripts/pool-check.php` checks head and length.

### 8. Reference

- Pick the tier, start here for tier 3: `process-choose-tier`
- Continue an existing undertaking: `process-resume`
- Pool layout and templates: `knowledge-maintain-pool`
- Check what the environment already does: `process-check-existing`
- Next stage after approval: the PRD, `process-write-prd`
