---
name: process-write-plan
description: Use when the PRD is confirmed and stage 2 starts — cut the undertaking into stages and phases with file scope, acceptance criteria and the stage lines Generated, By hand, Done when, Halt, apply cut checks, run the design board once, get the human's release.
zone: process
persona: O
prerequisites: [process-write-prd]
next: [process-run-stage]
---

## Scope

The plan cuts the confirmed PRD into work that one agent session can finish. First stages, then the phases of each stage. A phase that does not fit its cap is cut wrongly; the cap is not raised. The main session writes the plan; the human releases it. No stage is built before the release.

### 1. Entry

Read `docs/vorhaben/<name>/PROGRESS.md` and the PRD. The head must say phase `plan`, and the PRD must be confirmed. If it is not, go back to `process-write-prd`. Read `.claude/PROJECT_PROFILE.md` for the QA gates of the project.

### 2. Stages first, then phases

1. Cut the undertaking into stages: each ends in something that can be verified and merged on its own.
2. Only then cut each stage into phases. A phase is one brief for one implementer session.
3. Give every stage a stage plan with: goal, dependencies, the four lines `Generated`, `By hand`, `Done when` and `Halt`, phase list, file scope per phase, acceptance criteria (AK) per phase, dependencies between phases.
4. Do not cut phases finer than needed and never split a phase afterwards. If the context of a phase does not suffice, the implementer's turn ends with a restart point; the assignment is not torn into two briefs. If a cut proves wrong, correct the plan itself before the next brief is written.
5. Keep the derivation of a cut out of the plan; it belongs in its own evidence file, linked from the plan.

```markdown
## E1 <stage title>
**Goal:** one sentence.
**Depends on:** none | E<n>.
**Generated:** Query `ordersByPeriod`; Process `ExportOrders` · skeleton | none.
**By hand:** <Kind> `<Name>` · <note> | none.
**Done when:** observable; names the tests that hold the result.
**Halt:** none | one sentence on what the human looks at after the stage.
### P1.1 <phase title>
**Scope:** files, each one named; new or changed.
**AK:** numbered, each one checkable by a command or a file.
```

Inside a stage the work runs in this order: first what is generated, then what is written by hand, then the tests as a step of their own. `Done when` names those tests: one test per acceptance criterion and per error case, written against the product of the undertaking, not against the mechanics of the generator (`foundation-testing` section 6). The four lines stay four; tests get no line of their own. Which work is generated and which is by hand: `generated-code-extend` section 10.

`Generated` is what a generator or tool produces, `By hand` what is written by hand. Both lines use one entry form: per thing `` <Kind> `<Name>` ``, optionally followed by ` · <note>`; several things are separated by `; `; nothing is `none`. It is the form of the lines under `## Affected` in the understanding sheet (`process-concept`).

### 3. Cut checks

Run all four before the plan goes to the board.

1. **File list measured, exhaustively.** Candidate files come from a search over the whole area (listing, grep), never from a sample or from memory. Name the command and the count in the plan.
2. **Test migration with the change.** A behaviour change that breaks existing tests puts the migration of those tests in the same phase or an earlier one, never a later one.
3. **A distributed requirement names its case in every AK list.** A requirement that spans several phases appears, with its case, in the AK of each phase that carries a part of it.
4. **Every commitment has an AK.** Each capability the plan promises has an AK in the phase that builds it. A promise without an AK is deleted or given one.

### 4. Size caps

<!-- rule:plan-caps -->
Caps of the plan, each one checked mechanically by `scripts/pool-check.php`:

| Cap | Value |
|---|---|
| Stage plan per stage | `150` lines; in `PLAN.md` the section `## E<n>` counts from its heading to the next one |
| Plan a brief refers to | `16 KB` |

Caps of the briefs written later in `process-run-stage`, not checked mechanically: implementer brief at most 6 KB and at most 8 commitments, context load per brief at most 30 KB. Plan phases so that each brief fits them.

A plan that exceeds a cap is cut again into more stages or fewer promises; the cap stays. Run `php vendor/jardis/dev-skills/scripts/pool-check.php` after writing.

### 5. Design board

<!-- rule:plan-board -->
Run the design board exactly once per undertaking, on the plan, blind and in parallel, run by `process-review-board`. Set the progress head to phase `plan-review` while it runs.

- Default: two roles, architecture and test strategy.
- Add the packages role only when the plan touches new package APIs; it then consults `packages-find-existing` instead of loading a battery of package skills.
- Add a further role only with a reason from the nature of the work, such as tactical domain design or a user interface.
- Where the tool cannot run agents in parallel, run the roles one after another, each in a fresh context.

Merge the answers into one list, rule on every finding (work it into the plan or reject it with a reason) and send every finding that names a fork or open decision to the human. Working the findings into the plan is the gate; there is no second run.

### 6. Release

Show the human the plan with the findings and their rulings. The human releases it; no one else does. Where the undertaking has a surface, the target picture is stored as `KONZEPT.png` before the first stage.

On release set the progress head to phase `stage`, stage `E1/<total>` (the form is `E<n>/<total>`, `<total>` the number of stages in the plan), next step "brief for P1.1", and list the stages in the progress file as one line each.

**Exit:** `docs/vorhaben/<name>/PLAN.md`, released by the human.

### 7. Reference

- Previous stage: `process-write-prd`
- Run the board: `process-review-board`
- Briefs, implementer runs, failure path: `process-run-stage`
- Progress file rules: `process-concept`
- Caps check: `scripts/pool-check.php`
