---
name: do-git-push
description: Push the current branch with the quality gate and create a pull request following Gitflow. Use when committed work is ready for review.
zone: crosscut
persona: D
disable-model-invocation: true
prerequisites: [do-git-commit]
next: []
---

## Current state
!`git branch --show-current 2>/dev/null`
!`git log --oneline -3 2>/dev/null`
!`git status --short 2>/dev/null`

## Task

Push the current branch and create a pull request.

### Flow

1. **Push:**

```bash
git push -u origin <current-branch>
```

The pre-push hook runs the quality gate automatically: `make phpcs` · `make phpstan` · `make phpunit` (documentation-only changes take the fast path).

2. **Create the PR** (after a successful push):

```bash
# Feature / Fix → develop
gh pr create --base develop \
  --title "<type>(<scope>): <description>" \
  --body "$(cat <<'EOF'
## Summary
- ...

## Test plan
- [ ] ...

## Checklist
- [ ] Tests pass (`make phpunit`)
- [ ] PHPStan clean (`make phpstan`)
- [ ] PHPCS clean (`make phpcs`)
EOF
)"

# Hotfix → main
gh pr create --base main \
  --title "fix(<scope>): <description>"
```

### Rules

- The PR title follows the Conventional Commits format
- The PR body contains Summary, Test plan, Checklist
- Hotfix branches target `main`, everything else targets `develop`
- On a push failure (quality gate): show the error, do not retry blindly — fix the cause first
- Never merge with a red CI
