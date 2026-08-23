---
name: do-git-compliance
description: Jardis project repository compliance check - 10 checks for git hooks, uncommitted secrets, Gitflow branches, CI wiring, and branch sync. Use to verify a project repo follows the Gitflow conventions.
zone: crosscut
persona: D
disable-model-invocation: true
prerequisites: []
next: []
---

## Repo info
!`git remote get-url origin 2>/dev/null`
!`git branch --show-current 2>/dev/null`

## Task

Run the Jardis project compliance check on the current repository.

This is the **project** variant: it checks the Gitflow and CI conventions of
a Jardis application project (derived from the app template). Package
concerns — version tags, releases, Packagist consistency — are deliberately
not part of it.

### The 10 checks

**Hooks & safety (1-5):**
1. Pre-commit hook installed → `test -x .git/hooks/pre-commit`
2. Pre-push hook installed → `test -x .git/hooks/pre-push`
3. No secrets committed → `git ls-files .env.local '*.key' | wc -l` is 0
   (the stack `.env` itself IS versioned by design in template projects —
   check it carries no real credentials, only the trivial defaults)
4. develop branch exists → `git ls-remote --heads origin develop`
5. No stale merged branches → `git branch -r --merged origin/develop | grep -vE 'main|develop'`

**CI wiring (6-8):**
6. CI workflow present → `test -f .github/workflows/ci.yml`
7. CI job names match the ruleset's required checks (`phpcs`, `phpstan`, `tests`)
   → compare `gh api repos/{org}/{repo}/rulesets` with the workflow's job ids
8. Last CI run on main green → `gh run list --branch main --limit 1`

**Gitflow state (9-10):**
9. Branch ruleset active for main + develop
   → `gh api repos/{org}/{repo}/rulesets --jq '.[] | .name+" "+.enforcement'`
10. develop not behind main → `git rev-list --count origin/develop..origin/main` is 0

### Output format

```
1.  [OK]   Pre-commit hook installed
2.  [FAIL] Pre-push hook missing → make install-hooks
3.  [OK]   No secrets committed
...
```

On deviations: propose the fix and wait for confirmation — this skill only
reads, it never repairs on its own.
