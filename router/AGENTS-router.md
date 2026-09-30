# Process router

Wissenspool: `.claude/wissen/INDEX.md` — vor Entscheiden lesen, Vermerk-Pflicht

Pick the lowest tier that fits a task and name it in one line. Process is a means against size and risk, not a default: escalate only with a named reason. Two or more subtasks are never a single action. Skill first, then source code, then ask (`foundation-working-principles`). Before proposing anything new, ask what the environment already does (`process-check-existing`).

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
