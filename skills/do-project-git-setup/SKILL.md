---
name: do-project-git-setup
description: One-time Gitflow setup for a Jardis project on GitHub - develop branch, repo settings, branch ruleset, git hooks. No release, no Packagist. Use once after creating the project repo.
zone: crosscut
persona: D
disable-model-invocation: true
argument-hint: [org/repo]
prerequisites: []
next: [do-git-compliance]
---

## Current state

!`git status --short 2>/dev/null || echo "No git repository"`
!`git remote get-url origin 2>/dev/null || echo "No remote"`
!`git branch -a 2>/dev/null | head -6`

## Task

Set up Gitflow for this Jardis project on GitHub — develop branch, repo
settings, branch protection ruleset, git hooks. This is the project
counterpart of a package's first deployment: **no version tag, no release,
no Packagist** — a project is deployed, not published.

Runs **fully autonomously after the safety prompt**, no further questions.

### Preconditions

- The project repo exists on GitHub. The recommended way to create it from
  the template is `gh repo create <org>/<name> --template <template-repo>`
  (single initial commit, no template history). If only a local repo exists,
  create the remote first: `gh repo create <org>/<name> --private --source=. --push`.
- `gh auth status` is green.

---

### SAFETY PROMPT (MANDATORY)

**Before any action**, the user MUST confirm explicitly:

```
ONE-TIME GITFLOW SETUP

The following will be executed:
- Full local QA (make phpcs, phpstan, phpunit)
- Push main (if not pushed yet)
- develop branch created and pushed
- Repo settings (delete-branch-on-merge, no wiki/projects)
- Branch protection ruleset for main + develop (PRs only, required checks)
- Git hooks installed (make install-hooks)

Project: <name> (<path>)
GitHub:  <org/repo>

No tag, no release, no Packagist. Continue? (yes/no)
```

**Proceed only on an explicit "yes". Anything else: abort.**

---

### Phase 1: Quality gate

```bash
make stop 2>/dev/null || true
make phpcs
make phpstan
make phpunit
make stop 2>/dev/null || true
```

An empty test suite ("No tests executed") is fine for a fresh project.
On a QA failure: analyze, fix autonomously, commit separately, re-run.
Never use `--no-verify`.

### Phase 2: develop branch

```bash
git push -u origin main 2>/dev/null || true
git checkout -b develop
git push -u origin develop
git checkout main
```

### Phase 3: Repo settings

```bash
gh api repos/{org}/{repo} --method PATCH --input - <<'EOF'
{
  "default_branch": "main",
  "delete_branch_on_merge": true,
  "allow_squash_merge": true,
  "allow_merge_commit": true,
  "allow_rebase_merge": true,
  "has_wiki": false,
  "has_projects": false
}
EOF
```

### Phase 4: Branch protection ruleset

The required status checks match the job names of the template's
`.github/workflows/ci.yml` (`phpcs`, `phpstan`, `tests`).

```bash
gh api repos/{org}/{repo}/rulesets --method POST --input - <<'EOF'
{
  "name": "branch protection",
  "target": "branch",
  "enforcement": "active",
  "conditions": {
    "ref_name": {
      "include": ["refs/heads/main", "refs/heads/develop"],
      "exclude": []
    }
  },
  "bypass_actors": [
    {
      "actor_id": 5,
      "actor_type": "RepositoryRole",
      "bypass_mode": "always"
    }
  ],
  "rules": [
    {
      "type": "pull_request",
      "parameters": {
        "required_approving_review_count": 1,
        "dismiss_stale_reviews_on_push": true,
        "require_code_owner_review": false,
        "require_last_push_approval": false,
        "required_review_thread_resolution": false
      }
    },
    {
      "type": "required_status_checks",
      "parameters": {
        "strict_required_status_checks_policy": true,
        "required_status_checks": [
          {"context": "phpcs"},
          {"context": "phpstan"},
          {"context": "tests"}
        ]
      }
    }
  ]
}
EOF
```

### Phase 5: Git hooks

```bash
make install-hooks
```

### Phase 6: Verify

```bash
git ls-remote --heads origin | grep -E 'main|develop'   # both exist
gh api repos/{org}/{repo}/rulesets --jq '.[].name'       # ruleset present
test -x .git/hooks/pre-commit && test -x .git/hooks/pre-push && echo "hooks OK"
```

Then run `/do-git-compliance` for the full check list.

### Rules

- Daily work happens on `feature|fix/*` branches into `develop`
  (`/do-git-branch` → `/do-git-commit` → `/do-git-push`), hotfixes into `main`.
- Never force-push to `main` or `develop`.
- This skill is idempotent: re-running it skips what already exists.
