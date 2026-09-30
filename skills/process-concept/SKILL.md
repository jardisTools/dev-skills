---
name: process-concept
description: Use when a task is an undertaking (tier 3) and stage 0 starts — concept interview, target picture or executable example, human approval, project folder docs/vorhaben/<name>/ with progress file, pool scaffold and project profile when missing.
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
4. Create `docs/vorhaben/<name>/PROGRESS.md` from `templates/PROGRESS.md`, title and goal filled in, the head left at phase `concept`. From then on the progress file carries the state.

If the project keeps its documents out of the commit (switch `process-docs` set to `local`), the folder stays local. Do not touch the project's ignore files to make that happen.

### 2. Pool scaffold

<!-- rule:pool-scaffold -->
Create the knowledge pool scaffold only when `.claude/wissen/` is missing: copy `INDEX.md` and `themenseite.md` from `skills/knowledge-maintain-pool/templates/` into `.claude/wissen/`, the index without the example line. An existing `.claude/wissen/` is never changed, reformatted or filled in; it belongs to the project. Layout and caps: `knowledge-maintain-pool`.

### 3. Project profile

<!-- rule:project-profile -->
Create `.claude/PROJECT_PROFILE.md` from `templates/PROJECT_PROFILE.md` only when it is missing. An existing profile is never overwritten. Fill the placeholders from the project itself (Makefile targets, compose file, test configuration) and ask the human only for what the project does not show. The profile holds the project's concrete facts: QA entry, ports, build, pitfalls. Briefs, checkers and implementers read it instead of guessing.

### 4. Interview

Clarify one point at a time.

1. Ask one question. Ask the next only when the current one is answered unambiguously.
2. Accept no silent assumption. Where the human has not said it, ask; where the project can answer, read it first (code, knowledge pool, `process-check-existing` for what the environment already does).
3. Cover at least: the goal in one sentence, who uses the result and how, what it must not do, the error and empty cases, what is explicitly out of scope.
4. Record every settled point in the concept artefact, not in a chat summary.

### 5. Concept artefact

The artefact is a picture or an executable example, never prose.

| Form | Examples | File |
|---|---|---|
| Picture | HTML pages showing the screens, states and flows; a sketch | `docs/vorhaben/<name>/KONZEPT.html` |
| Executable example | a test, a golden file, a sample response | in the project's own test or fixture location, named in the progress file |

Create `KONZEPT.html` for every undertaking that has something to see. For a change without a surface, the executable example carries the concept and `KONZEPT.html` states where it lives. Draft the artefact while the interview runs and show it to the human as it grows.

### 6. Approval

The human accepts the artefact; no one else does. Show it, name what it fixes and what it leaves open, and ask for a yes.

- **No yes, no next stage.** A change request goes back into the interview.
- On approval, save a screenshot of the picture as `docs/vorhaben/<name>/KONZEPT.png` with the browser tool at hand. If none is available, say so and ask the human to save it.
- Set the progress head to phase `prd`, stage `—`, and a next step naming the PRD as one line. Fill in the goal and the path of the approved artefact. An open question stays in the head as `STOPP: <YYYY-MM-DD> · <question>` and blocks autonomous work.

### 7. Progress file rules

- `## Kopf` is the first heading after the title, with four lines in this order: `Phase`, `Stage`, `Next step`, `Open decisions`. Free lines may follow.
- `Phase` is one of `concept`, `prd`, `prd-review`, `plan`, `plan-review`, `stage`, `acceptance`, `close`.
- `Next step` is one action in one line.
- The file has at most 60 lines. It holds state, not a chronicle; only the main session writes it.
- `php vendor/jardis/dev-skills/scripts/pool-check.php` checks head and length.

### 8. Reference

- Pick the tier, start here for tier 3: `process-choose-tier`
- Continue an existing undertaking: `process-resume`
- Pool layout and templates: `knowledge-maintain-pool`
- Check what the environment already does: `process-check-existing`
- Next stage after approval: the PRD, `process-write-prd`
