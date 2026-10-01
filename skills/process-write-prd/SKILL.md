---
name: process-write-prd
description: Use when the target picture of an undertaking is approved and stage 1 starts — write the PRD that adds error cases, states, limits and data paths to the picture, run the requirements board once, resolve every finding, get the human's confirmation.
zone: process
persona: O
prerequisites: [process-concept]
next: [process-write-plan]
---

## Scope

The approved target picture shows what the result looks like. It does not show what happens when something goes wrong, which states exist, where the limits are and where the data flows. The PRD states exactly that, and nothing else. The main session writes it; the human confirms it. No plan is written before the confirmation.

### 1. Entry

Read `docs/vorhaben/<name>/PROGRESS.md`. The head must say phase `prd`; the target picture must be approved (`KONZEPT.html` or the named executable example). If it is not, go back to `process-concept`. Do not write a PRD for a picture the human has not accepted.

### 2. The PRD adds, it does not translate

The picture stays the source. Link it at the top of the PRD; never re-describe a screen, a flow or an example in prose. A sentence that only repeats what the picture shows is deleted.

Write `docs/vorhaben/<name>/PRD.md` with these parts, each only as long as the undertaking needs:

| Part | Content |
|---|---|
| Source | Link to the approved target picture and the goal in one sentence |
| Error cases | What happens on invalid input, missing data, a failing dependency, a repeated call |
| States | Every state of the thing that changes, with the transitions and who triggers them |
| Limits | Sizes, counts, time, permissions, what the result must not do |
| Data paths | Where each datum comes from, where it is stored, who reads it, what leaves the system |
| Out of scope | What is explicitly not built |
| Open decisions | Forks that the human has not settled yet |

Every statement is observable: a reader can tell from the result whether it holds. Where a requirement is a rule ("outwards, X holds"), name the place where X holds today; `process-check-existing` answers that when the answer is not at hand.

### 3. Requirements board

<!-- rule:prd-board -->
Run the requirements board exactly once per undertaking, blind and in parallel, before the human confirms the PRD. Set the progress head to phase `prd-review` while it runs. The board is run by `process-review-board`; it reads the PRD and the target picture, never your reasoning.

Roles:

- The skeptic is always part of it: unproven assumptions, missing requirements, scope creep.
- Every further role needs a reason taken from the nature of the work: the domain is modelled (domain expert, domain strategy), there is a public API, a business decision is open, there is a user interface (interface and flow role). "A backend track exists" is no reason.
- Where the tool cannot run agents in parallel, run the roles one after another, each in a fresh context.

### 4. Findings

1. Merge the answers into one list of findings; drop duplicates.
2. Rule on every finding: resolve it in the PRD or reject it with a reason. No finding stays unruled.
3. A finding that names a fork or an open decision goes to the human, even when it is minor. Do not decide it yourself.
4. The board does not run a second time. The rulings are the gate; there is no re-check round.

<!-- rule:decide-yourself-no-gate-waiver -->
A human's "decide open points yourself" or "proceed autonomously" waives no gate. A question the human left open and the project cannot answer (code, knowledge pool, target artefact) is never settled by the main session, neither in the concept, nor in the PRD, nor by ruling a board finding on it as resolved. It goes to the open-question gate (`open-question-gate`, see `process-run-stage`); what the gate cannot decide stays in the progress head as `STOPP: <YYYY-MM-DD> · <question>` and is put to the human at the next approval.

### 5. Confirmation

Show the human the PRD with the list of findings and their rulings. The human confirms; no one else does. A change request goes back into the PRD and the affected rulings, without a new board run.

On confirmation set the progress head to phase `plan`, stage `—`, next step "write the plan". An open question stays in the head as `STOPP: <YYYY-MM-DD> · <question>` and blocks autonomous work.

**Exit:** `docs/vorhaben/<name>/PRD.md`, confirmed by the human.

### 6. Reference

- Previous stage, target picture and progress file: `process-concept`
- Continue after a break: `process-resume`
- Run the board: `process-review-board`
- Check what the environment already does: `process-check-existing`
- Next stage: `process-write-plan`
