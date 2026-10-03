---
name: git-commit-change
description: Create a Conventional Commit from the current changes in a Jardis project. Use after a change is complete and reviewed.
zone: crosscut
persona: D
profile: core
disable-model-invocation: true
argument-hint: [optional description]
prerequisites: [git-start-branch]
next: [git-push-and-open-pr]
---

## Current state
!`git status --short 2>/dev/null`
!`git diff --stat 2>/dev/null`
!`git branch --show-current 2>/dev/null`

## Task

Analyze the changes and create a Conventional Commit.

Branching follows Gitflow: commits land on a `feature/*` (or `fix/*`) branch cut from `develop` — see `git-start-branch` — and nothing is committed directly on `develop` or `main`. The branch then goes to a PR against `develop` — see `git-push-and-open-pr`.

### Flow

1. Evaluate `git diff` and `git status`
2. Stage the relevant files — `git add <files>` (never `.env.local`, key files, or other sensitive data)
3. Produce the commit message:

```
<type>(<scope>): <description>

[optional body]
```

### Type

| Type | When |
|------|------|
| `feat` | New feature |
| `fix` | Bugfix |
| `refactor` | Code restructuring without behavior change |
| `test` | Adding/changing tests |
| `docs` | Documentation |
| `chore` | Build, dependencies, config |
| `perf` | Performance improvement |

### Scope

Derive from the touched files: the Bounded Context (`src/<BC>/...` → BC name in lowercase), `app` for `src/App/`, or the area (`config`, `stack`, `ci`).

### Rules

- **No** `Co-Authored-By: Claude` in the commit message
- Description max 72 characters
- Body only when the changes are not self-explanatory
- The pre-commit hook validates the branch name automatically
