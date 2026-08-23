---
name: do-git-branch
description: Create a Gitflow branch with issue number and type detection. Use when starting work on a feature, fix, or hotfix in a Jardis project.
zone: crosscut
persona: D
disable-model-invocation: true
argument-hint: [description]
prerequisites: []
next: [do-git-commit]
---

## Open issues

First run `gh issue list --state open --limit 10` to see the open issues.

## Current state
!`git branch --show-current 2>/dev/null`
!`git status --short 2>/dev/null`

## Task

Create a new Gitflow branch for: $ARGUMENTS

### Flow

1. **Determine the type** (from context, or ask): `feature` (default) · `fix` · `hotfix`
2. **Suggest an issue number** from the list. The user confirms or changes it.
   No matching issue → use today's date as `YYMMDD`.
3. **Create the branch:**

```bash
git fetch origin

# Feature / Fix (from develop)
git checkout -b <type>/<nr>_<description> origin/develop

# Hotfix (from main)
git checkout -b hotfix/<nr>_<description> origin/main
```

### Branch naming

Format: `<type>/<number>_<description>`
- Number: 1-7 digits (GitHub issue number recommended, date `YYMMDD` as fallback)
- Description: lowercase, hyphens, underscores
- Validated by the pre-commit hook: `^(feature|fix|hotfix)\/[0-9]{1,7}_[a-zA-Z0-9_-]+`

```
feature/42_user-registration
fix/108_login-crash
hotfix/999_critical-security
```
