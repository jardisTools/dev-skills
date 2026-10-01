# Process router

Wissenspool: `.claude/wissen/INDEX.md` — vor Entscheiden lesen, Vermerk-Pflicht

Pick the lowest tier that fits a task and name it in one line — a present tier-3 criterion is never a matter of fit. Process is a means against size and risk, not a default: escalate only with a named reason. Two or more subtasks are never a single action. Tier 3 is not a judgement call: one tier-3 criterion in the task (an open decision, a public API or observable behaviour, data, migration, security) makes it tier 3 however small the change — start `process-concept`. Name tier 1 or 2 only after loading `process-choose-tier`. At tier 2 the main dialogue delegates every subtask to a sub-agent and verifies; it never implements itself. Skill first, then source code, then ask (`foundation-working-principles`). Before proposing anything new, ask what the environment already does (`process-check-existing`). Before acting in a phase, load its skill in full through the skill mechanism; reading parts of a skill file through the shell does not count. Before any write to a page of the knowledge pool, load `knowledge-record-decision`.

<!-- git-rules -->
Branch, commit and merge are gates of the human: the session never creates a branch, commits or merges on its own, it stops and asks; no commit carries a `Co-Authored-By` line or any other tool attribution. Git flow: work happens on a `feature/*` or `fix/*` branch cut from `develop`, a hotfix on a `hotfix/*` branch cut from `main`, never directly on `develop` or `main`. A halt names exactly one git gate (branch, commit or merge), and the next one only once the step before stands in `git log`; the human starts the branch (`git-start-branch`), the session never offers to create it itself.
<!-- /git-rules -->

## Tiers

| Tier | Task | Skill |
|---|---|---|
| 0 Answer | question, explanation, typo | answer directly |
| 1 Single action | one thing, one place, no open decision | `process-choose-tier` |
| 2 Small assignment | one big or isolated thing, or several subtasks | `process-choose-tier` |
| 3 Undertaking | open decision, dependent steps, new architecture, public API, data, security | `process-concept` |

## Phase to skill

| Phase | Skill |
|---|---|
| Concept (stage 0) | `process-concept` |
| PRD (stage 1) | `process-write-prd` |
| Plan (stage 2) | `process-write-plan` |
| Build a stage | `process-run-stage` |
| Check a stage, accept the whole | `process-verify` |
| Close | `process-close` |
| Continue after an interruption | `process-resume` |
| Review board for PRD or plan | `process-review-board` |
| Review a change before a commit | `code-review-change` |
| Record a decision or a lesson | `knowledge-record-decision` |
| Keep the knowledge pool | `knowledge-maintain-pool` |
| Branch, commit, push, pull request | `git-start-branch`, `git-commit-change`, `git-push-and-open-pr` |
| Repository set-up and compliance | `git-setup-repository`, `git-check-compliance` |

## Jardis projects

Start with `start-orientation`: it walks packages, schema, design and code and names the skill for every question. Before hand-building a reusable component, check `packages-find-existing`. Rules: `foundation-architecture`, `foundation-patterns`, `foundation-testing`, `foundation-php`, `foundation-frontend-review`.

## Reviewer roles

Sources live in `process-review-board/reviewers/`; the main session picks roles with a reason and runs them blind, in parallel where the tool can, otherwise one after another with a fresh context each.

- PRD: skeptic, domain expert, strategic DDD, frontend UX
- Plan: architecture, DDD tactics, PHP, test strategy, packages, frontend architecture, frontend types, frontend a11y, frontend tests, frontend UX
- Stage: stage verifier, acceptance gate
- Support: existing-capability check, open-question gate, failure diagnosis
