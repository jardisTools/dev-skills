---
name: git-check-compliance
description: Jardis project repository compliance check - 11 checks for git hooks, uncommitted secrets, Gitflow branches, CI wiring, and branch sync. Use to verify a project repo follows the Gitflow conventions.
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

### The 11 checks

**Hooks & safety (1-6):**
1. Pre-commit hook installed → `test -x .git/hooks/pre-commit`
2. Pre-push hook installed → `test -x .git/hooks/pre-push`
3. commit-msg hook wired (a warning when missing, never a FAIL) → look for a `commit-msg`
   that calls `vendor/jardis/dev-skills/scripts/commit-msg`: in `.husky/`, in the
   `core.hooksPath` folder or in `.git/hooks/`; for CaptainHook, GrumPHP or Lefthook look in
   their configuration (read-only: this check never runs the installer). A `commit-msg` of
   another tool that does not call it counts as missing. Fix: `/git-setup-repository` (commit-msg phase)
4. No secrets committed → `git ls-files .env.local '*.key' | wc -l` is 0
   (the stack `.env` itself IS versioned by design in template projects —
   check it carries no real credentials, only the trivial defaults)
5. develop branch exists → `git ls-remote --heads origin develop`
6. No stale merged branches → `git branch -r --merged origin/develop | grep -vE 'main|develop'`

**CI wiring (7-9):**
7. CI workflow present → `test -f .github/workflows/ci.yml`
8. CI job names match the ruleset's required checks (`phpcs`, `phpstan`, `tests`)
   → compare `gh api repos/{org}/{repo}/rulesets` with the workflow's job ids
9. Last CI run on main green → `gh run list --branch main --limit 1`

**Gitflow state (10-11):**
10. Branch ruleset active for main + develop
   → `gh api repos/{org}/{repo}/rulesets --jq '.[] | .name+" "+.enforcement'`
11. develop not behind main → `git rev-list --count origin/develop..origin/main` is 0

### Output format

```
1.  [OK]   Pre-commit hook installed
2.  [FAIL] Pre-push hook missing → make install-hooks
3.  [WARN] commit-msg hook missing → /git-setup-repository
4.  [OK]   No secrets committed
...
```

On deviations: propose the fix and wait for confirmation — this skill only
reads, it never repairs on its own.
