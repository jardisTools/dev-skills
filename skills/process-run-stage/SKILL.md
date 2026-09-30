---
name: process-run-stage
description: Use when the plan is released and a stage is built — one brief per phase, each implementer in a fresh session, commit, one blind verification, the QA gates of the project profile once, merge; carries the failure path and the question points.
zone: process
persona: O
prerequisites: [process-write-plan]
next: []
---

## Scope

A stage is built phase by phase. Each phase has one brief and one implementer; the main session commits, verifies, runs the QA gates and merges. The main session is the orchestrator: it writes briefs, watches, checks against ground truth and does not build. No stage is green on the word of the one who built it.

### 1. Entry

Read `docs/vorhaben/<name>/PROGRESS.md` and the stage plan. The head must say phase `stage` with the stage to build. If it does not, go back to `process-write-plan` (no released plan) or `process-resume` (a session that knows nothing yet). Read `.claude/PROJECT_PROFILE.md`: its QA entry names the gates of this project. A `STOPP:` entry in the head blocks everything until the human answered.

### 2. The stage loop

For each phase of the stage, in plan order:

1. **Brief.** Write one brief from `templates/brief.md` (section 3).
2. **Implementer.** Start a fresh agent session with that brief (sections 4 and 5).
3. **Commit.** When the report says `green`, the main session checks the files against the phase scope, then commits with the commit message from the report, following the commit conventions of the project (`git-commit-change`).
4. Next phase. Independent phases may run in parallel; see section 4.

After the last phase of the stage:

5. **Verifier**, once per stage (section 6).
6. **QA gates**, once per stage (section 7).
7. **Sight gate** where the stage builds a surface (section 9).
8. **Merge** of the stage, following the git flow of the project (`git-push-and-open-pr`). Then shrink the stage in the progress file to `E<n> done <commit>` and move the head to the next stage or to phase `acceptance`.

### 3. The brief

One brief per phase, written by the main session or by a planning agent, filled from the stage plan. A brief is a file in the undertaking folder, for example `docs/vorhaben/<name>/briefe/<stage>-<phase>.md`.

The caps, all three checked when the brief is written:

| Cap | Value |
|---|---|
| Brief size | at most 6 KB |
| Commitments | at most 8, each one countable; a golden file, a test suite or an end-to-end chunk counts one each |
| Context load | at most 30 KB: the summed bytes of every input the brief lists, knowledge pages included |

One brief is one package or one layer. A phase that does not fit is cut wrongly in the plan; correct the plan, do not raise the cap.

A brief contains, in this order:

1. The assignment in one sentence, and the reference to at most one section of the stage plan and to the target artefact (file and section). Never a reference to the whole PRD, the whole plan or the progress file.
2. The inputs, numbered in reading order, each with path and line range. Add `.claude/PROJECT_PROFILE.md` when QA, ports or stack matter, and the knowledge pages of the pool that touch the area, chosen from `.claude/wissen/INDEX.md`. Measure the bytes; do not estimate them.
3. The commitments, numbered.
4. The limits: a list of what is not part of this phase, the rulings that hold around it, the paths the implementer must not touch. A contradiction between a test and a ruling is a stop: the implementer reports `blocked` and never resolves it.
5. The gates of its scope and the return schema, verbatim.
6. A last line that repeats the return duty: one single final report in that schema.

### 4. The implementer

The implementer builds exactly its phase. It runs the quick gates of its scope and the code review (`code-review-change`), and returns the report. It does **not** write to the progress file, does **not** run any Git operation that changes state and does **not** run the full QA entry: commit, state and QA belong to the main session.

A sub-agent that writes in parallel with another gets its own `git worktree`. Parallel writers are allowed only for disjoint files, disjoint contracts and separate QA infrastructure, and never at the same time as a QA run in the same working tree. Containers of the project are never started by parallel agents; gates that use them run one after another, in the foreground.

Read the report against the files, not against its own claims: `git status` and `git diff` of the phase scope, and the gate output the report names.

### 5. A fresh session per stage

<!-- rule:fresh-session-per-stage -->
Every stage is run in a fresh agent session, and so is every implementer session inside it. The only hand-over between sessions is the head of the progress file and the brief; nothing is carried over by the memory of a conversation. A session that has built part of a phase, run out of context or been interrupted ends with a restart point in the report, and the next fresh agent session continues from head and brief. The assignment is not cut into a second brief.

### 6. Verifier and failure path

The verifier runs once per stage, blind, and is never the one who built: doer and checker are two sessions. Give it exactly the acceptance criteria of the stage, the stage diff, the target artefact and the knowledge pages the briefs name. Never hand it the implementer's report. Its source is the stage verifier role under `../process-review-board/reviewers/`. Its assignment carries the line "prove the red-ability": red evidence is taken only at the central comparison test of the stage, mutations only with a backup outside the working tree, never by reverting files through Git. It returns `GREEN` or `RED` with its evidence, each item as file and line, test name or real output.

<!-- rule:failure-path -->
After `RED` the main session does not debug. It classifies the finding. The report's own feedback comes first; where it does not explain the cause, one read-only agent runs as the failure-diagnosis role, and a second diagnosis for the same failure never runs. Exactly one fix run follows, then exactly one follow-up run of the verifier; a third round does not exist. If the follow-up run is red too, write `STOPP: <YYYY-MM-DD> · <what is red and what was tried>` into the progress head under `Open decisions` and take it to the human. This limit is never dosed away.

Classes of a blocked or refuted step:

| Class | Case | Action |
|---|---|---|
| A | Recoverable: gone astray, a flake, an artefact of the environment, a mechanical verifier finding | Exactly one fresh retry with the retry assignment. If it fails: class B |
| B | An answerable question: a missing decision, an ambiguity, a deviation from the plan inside the PRD | The open-question gate (section 8) |
| Direct | Never delegated: see section 8 | `STOPP:` and the human |

### 7. QA gates, once per stage

Run the gates the QA entry of `.claude/PROJECT_PROFILE.md` names, **once per stage**: at the end of the stage, after a green verdict, run by the main session itself. Never per phase and never inside a sub-agent. Rebuild the artefact first when the commit changed, as the profile says; otherwise the gate measures an old state. Never alongside a writing agent in the same working tree. A red gate after the one fix run and the one follow-up run is not a question: it goes to the human as a `STOPP:` without the gate.

### 8. Question points

<!-- rule:question-points -->
A stage has at most 2 question points: before the build, on the plan and on the picture; after the build, on the result. Between them, questions and side findings are collected, no question is put while a series runs, and only a real blocker interrupts it. Every open question goes into the progress head as `STOPP: <YYYY-MM-DD> · <question>` and blocks autonomous work until it is answered.

Before any question reaches the human it goes to the open-question gate (source `open-question-gate` in `../process-review-board/reviewers/`). It derives the answer from the PRD, the target artefact, the constitution of the project and the code, with the place it found it, and never guesses. If it decides, the main session takes the answer over, logs it under `Decisions delegated` in the progress file and corrects the plan where the answer deviates. If the gate says it cannot be decided, the same question runs again on the next stronger model; a `STOPP:` comes only when the strongest model at hand also cannot decide. The serial form (context, case, recommendation, stop) is for questions of principle without a path of derivation.

These go to the human directly and are never delegated:

- destructive or irreversible operations
- a change to a public API or to the observable behaviour of a published package
- scope beyond the PRD
- a contradiction of the PRD's wording
- everything that changes the target picture or a visible surface (send the picture with it)
- everything about publication, cost or third parties

Technical facts are derived or stopped, never guessed.

### 9. Target picture and sight gate

There is one target picture per undertaking, approved in stage 0 and stored once; it is not regenerated.

- A brief that builds a surface refers to file and section of the target picture directly, criterion "looks like there", with no detour through PRD or plan numbers. A technical shortcut that changes the picture is forbidden.
- Before a surface is built new, check what exists (`process-check-existing`). When the rebuild costs more than the new build, the human decides.
- The implementer delivers one screenshot per built surface on real data beside the target picture, with one sentence: "same" or "differs in ...". No series of screenshots, no checklist made of pictures.
- **The sight gate always blocks, headless as well.** The human sees target picture and screenshot side by side and decides; "differs" without the human's release is red. The loop parks (notify, then wait) and the next stage never starts without that sight. Unattended runs are for stages without a sight gate.

### 10. Without sub-agents

The roles of this skill (implementer, verifier, failure diagnosis, open-question gate) run in parallel where the tool can, otherwise one after another, each in a fresh context, never in the conversation that still holds the previous answer. Where the tool has no sub-agents at all, start a fresh headless run per role with a pure assignment and take the result from standard output:

| Tool | Headless call |
|---|---|
| Claude Code | `claude -p "<assignment>"` |
| Codex CLI | `codex exec "<assignment>"` |
| Cursor | `agent -p "<assignment>"` |
| Copilot CLI | `copilot -p "<assignment>"` |
| Gemini CLI | `gemini -p "<assignment>"` |

The implementer's run may write inside the working tree and nothing else; the runs of verifier, diagnosis and gate are read-only. Doer and checker never share a session; where that cannot be arranged, ask the human to run the check in a separate one.

### 11. Reference

- Previous stage: `process-write-plan`
- Brief template: `templates/brief.md`
- Reviewer and gate sources: `reviewers/<role>.md` in `process-review-board`
- Progress file rules: `process-concept`
- Continue after an interruption: `process-resume`
- Check before a proposal: `process-check-existing`
