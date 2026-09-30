---
name: process-review-board
description: Use when a PRD or a plan is ready for its one review — choose roles with a reason, run them blind and in parallel, merge one deduplicated list, rule on every finding, send every fork to the human; includes fallbacks for tools without sub-agents.
zone: process
persona: O
prerequisites: [foundation-working-principles]
next: []
---

## Scope

A board is a set of blind reviewers that read one document and return findings. An author does not see the gaps in a text he or she wrote; a reviewer who has not seen the reasoning can. This skill runs the board: which roles, how they are started, what comes back, what happens to it. The main session runs the board; the human decides every fork.

### 1. Two boards, one run each

| Board | Reviews | Runs | Called from |
|---|---|---|---|
| Requirements board | the PRD | before the human confirms the PRD | `process-write-prd` |
| Design board | the plan | before the human releases the plan | `process-write-plan` |

<!-- rule:question-points -->
One run per board: the requirements board and the design board each run exactly once per undertaking. A finding is ruled on, never re-checked by a second run. The design board has two roles by default, architecture and test strategy: at most 2 roles, unless the nature of the work gives a named reason for one more. The requirements board has the skeptic and every further role that such a reason calls for. An open question that the ruling of a finding cannot settle goes into the progress head as `STOPP: <YYYY-MM-DD> · <question>` and is taken to the human.

### 2. Choose the roles, with a reason

Name every chosen role and the reason in one line before anything is started. "A backend track exists" and "to be safe" are no reasons.

| Role (source in `reviewers/`) | Board | Take it when |
|---|---|---|
| `prd-review-skeptic` | requirements | Always. |
| `prd-review-domain-expert` | requirements | The PRD models a domain, or a domain decision is open. The domain comes with the assignment. |
| `prd-review-ddd-strategy` | requirements | Contexts, domain language or a public API are cut or changed. |
| `prd-review-frontend-ux` | requirements | The PRD has a user interface. |
| `plan-review-architecture` | design | Default. |
| `plan-review-test-strategy` | design | Default. |
| `plan-review-packages` | design | The plan touches new package APIs. |
| `plan-review-ddd-tactics` | design | The plan cuts aggregates, value objects or repositories. |
| `plan-review-php` | design | The plan is mostly PHP code with error handling and edge cases that matter. |
| `plan-review-frontend-architecture` | design | The plan has a user interface. |
| `plan-review-frontend-tests` | design | The plan has a user interface. |
| `plan-review-frontend-a11y` | design | A phase produces an interface people operate. |
| `plan-review-frontend-types` | design | A phase touches types or data flow in the frontend. |
| `plan-review-frontend-ux` | design | The plan carries out interface states and interactions. |

There is no role for Go: a Go review is not part of this board.

### 3. The assignment to a role

Hand each role exactly this and nothing more:

1. The text of its source file, or its installed reviewer by name (see section 4).
2. The reviewed document: the PRD with the target picture, or the plan with the confirmed PRD.
3. The acceptance list of the undertaking.
4. For the domain expert: the domain. For frontend roles: the stack. For the packages role: the area the plan touches.
5. A hard deadline in tool calls. What a role did not reach it marks as a question.

Never hand over your reasoning, the answers of the other roles or a hunch about where the problem is. That is what "blind" means. The reviewers only read; none of them writes a file.

### 4. Run them: parallel where possible

Start all chosen roles at the same time, each as its own sub-agent with a fresh context. Where the project has installed reviewer shells for the tool, start the role by its name; otherwise start a generic sub-agent with the text of the source file.

**Where the tool cannot run agents in parallel:** run the roles one after another, each with a fresh context per role, never in the same conversation that still holds the previous answer. Blindness is kept; only the time grows.

**Where the tool has no sub-agents at all:** start a fresh headless run per role with a pure review assignment (source text, document, deadline), read-only, and take its answer from standard output:

| Tool | Headless call |
|---|---|
| Claude Code | `claude -p "<assignment>"` |
| Codex CLI | `codex exec "<assignment>"` |
| Cursor | `agent -p "<assignment>"` |
| Copilot CLI | `copilot -p "<assignment>"` |
| Gemini CLI | `gemini -p "<assignment>"` |

Do not grant write permission to these runs. The assignment is the review and nothing else; a headless run that is also asked to fix the document is no longer blind.

### 5. One list, every finding ruled

1. Merge all answers into **one** list of findings. Drop duplicates; where two roles name the same place, keep the higher severity and both categories.
2. **Rule on every finding:** resolve it in the document, or reject it with a reason in one sentence. No finding stays unruled, and a minor finding is ruled like a major one.
3. **Every fork goes to the human.** A finding that names a fork or an open decision, and everything the roles list under OPEN FORKS, is not decided by the main session, not even a minor one.
4. Things a role marked as not checked are read as questions: answer them from the document, or carry them to the human.
5. Show the human the merged list with the rulings next to the document. The rulings are the gate.

The board does not run a second time. A change the human asks for afterwards goes into the document and the affected rulings.

### 6. Return

The roles return findings in one shape: a list of findings, each with a category (ambiguity, missing requirement, risk, contradiction), a place and a severity, and, apart from that list, every open fork. The reviewed document and the merged list, with rulings, are what stays in the project: the list goes into the progress file or next to the document, as the project keeps its documents.

### 7. Reference

- Requirements board called from: `process-write-prd`
- Design board called from: `process-write-plan`
- Reviewer sources: `reviewers/<role>.md` in this skill folder
- Checker before a proposal, not part of the board: `process-check-existing`
