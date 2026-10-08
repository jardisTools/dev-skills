# jardis/dev-skills — Agent Notes

Composer plugin that distributes Jardis skills (into `.claude/skills` and `.agents/skills` of the consumer project) and aggregates `AGENTS.md` from Jardis vendor packages into the consumer project.

## What this package contributes

- **Discovery** of skills from `vendor/jardis*/*/.claude/skills/*/SKILL.md` and from this repo's own `skills/` directory.
- **Bundle skills** — 34 folders in `skills/`, listed in `src/Data/BundleSkills.php`. Each declares `profile: core` or `profile: jardis` in its frontmatter; the installed set is the skills of the resolved profile (`core` 25, `jardis` 34 — the nine `jardis` skills are `start-orientation`, `design-*`, `generated-code-*`), unless `extra."jardis/dev-skills"."bundled-skills"` narrows it (`false` or `[]` keeps only the mandatory groups `foundation-*` and `process-*`). Resolution (`Handler/Install/ResolveInstallProfile`): the key `extra."jardis/dev-skills"."profile"`, else an installation from before 1.7.0 (manifest without `profile`, or legacy folders) keeps `jardis` and is not marked, else detection of a `vendor/jardis*/*` package other than `jardis/dev-skills`. The router (`profile:` marker areas) and the reviewer shells (17 in `core`) follow the profile. By area prefix:
  - `start-orientation` — entry point and routing into the other skills
  - `packages-find-existing` — package catalog; generated from `catalog/manifest.json` (`make generate-catalog`), never edited by hand
  - `design-draft-schema`, `design-headless-mcp`, `design-model-capabilities` — drafting a Schema.json; driving the Designer through `jardis mcp`; what each Jardis tool delivers before interview and PRD
  - `generated-code-extend`, `-wire-transport`, `-versioning`, `-workflow-api`, `-recipes` — working with Designer-generated PHP code
  - `foundation-architecture`, `-patterns`, `-testing`, `-frontend-review`, `-php`, `-working-principles` — cross-cutting rules
  - `git-setup-repository`, `-start-branch`, `-commit-change`, `-push-and-open-pr`, `-check-compliance` — Gitflow workflow
  - `knowledge-maintain-pool`, `knowledge-record-decision` — the decision pool of a project
  - `process-*` (ten skills, reviewer sources in `skills/process-review-board/reviewers/`) and `code-review-change` — the development process
  - The 18 names of 1.3.x (`src/Data/RenamedSkills.php`) live on as redirect skills until 2.0.0.
- **Managed skills via manifest:** the plugin installs and removes only the skill folders listed in `.claude/skills/.jardis-managed.json` (`src/Data/Manifest.php`); folders of the user are never touched. Without a manifest, `BundleSkills::NAMES` plus the old names govern, never a name prefix. Locally changed folders are backed up to `.claude/.jardis-backup/`.
- **AGENTS.md aggregation** between markers `<!-- BEGIN jardis/dev-skills ... -->` / `<!-- END jardis/dev-skills -->`; the router text (`router/AGENTS-router.md`) opens the block. User content outside the markers is preserved. A source package's own managed block is stripped before embedding (`Handler/Install/StripManagedBlock`), so the result is always a single, non-nested block. An `AGENTS.md` that is a link, or lies behind one, is not written; a pre-existing `AGENTS.md` without markers is moved to `AGENTS.md.backup`.
- **`agents-md` key** (`src/Data/AgentsMdMode.php`, resolved in `Handler/Discovery/ResolveAgentsMdMode`): `aggregate` or `none`; without the key the default is `none` when the vendor part of the root package name begins with `jardis`, else `aggregate`. With `none` there is no block, no `CLAUDE.md` import and no Gemini entry, and `Handler/Install/RetireAgentsMd` removes what an earlier run left; skills, shells, manifest and exclude block run unchanged.
- **Add-ons** (each one only warns on failure): `CLAUDE.md` import block, `.gemini/settings.json` context entry, reviewer shells for five tools (`src/Data/ShellFormat.php`), Git exclude block (`process-docs`). The git rules of the router have three stances through `extra."jardis/dev-skills"."git-rules"`: `true` (default, branch, commit and merge are gates of the human), `"delegated"` (the session creates branch and commits itself, merge and push stay gates of the human), `false` (no git rules).
- **Tools for consumers:** `scripts/pool-check.php` (knowledge pool checker, linked as `vendor/bin/pool-check.php`), `scripts/commit-msg` and `scripts/install-commit-msg-hook` (the hook warns, it never rejects a commit), `scripts/check-commit-messages` (CI range check).

## Working in this repo

- **Architecture:** Closure-Orchestrator — `src/SkillInstaller.php` and `src/SkillUninstaller.php` compose the sub-orchestrators `src/InstallSkills.php`, `src/InstallAddons.php` and `src/UninstallAddons.php` and the handlers in `src/Handler/`. Data classes under `src/Data/`. No business logic in orchestrators.
- **Plugin entry:** `src/Plugin.php` (`Composer\Plugin\PluginInterface` + `EventSubscriberInterface`) wires `post-install-cmd`, `post-update-cmd`, `pre-package-uninstall`.
- **Tests:** Integration > Unit. New tests go under `tests/Integration/<area>/<ClassName>Test.php`. Use `tests/Support/TempProject` for filesystem fixtures.
- **Quality gates:** `make phpunit`, `make phpstan` (Level 8), `make phpcs` (PSR-12), `make validate-skills`, `make generate-catalog-check`, `make check-public-text`. All must be green. Before a release tag: `make check-changelog-top VERSION=<x.y.z>`.
- **Skill authoring:** Every bundled `SKILL.md` follows `docs/SKILL-FORMAT.md` v6 — frontmatter `name`/`description`/`zone`/`persona`/`profile`/`prerequisites`/`next`, single-line description (≤175 words hard limit, new skills ≤45), topical numbered body sections (`### 1. …`), per-zone line budget (`crosscut` 225, `pre`/`post-reference` 250, `process` 250, `discovery` 150, `post-active` 700). Long working artefacts live in a sibling `skills/<name>/examples/` directory and do not count against the body budget.

## Don'ts

- Do not add, rename or remove a bundle skill without updating `src/Data/BundleSkills.php` (and `src/Data/RenamedSkills.php` for a rename). Do not introduce a new area prefix without updating `docs/SKILL-FORMAT.md` §2.
- Do not edit a generated AGENTS.md block in a consumer project — the plugin overwrites it on next install.
- Do not bypass `TempProject` in tests with raw `tempnam()` / hardcoded paths.
- Do not duplicate content across bundle skills. Patterns live only in `foundation-patterns`, architecture only in `foundation-architecture`, frontend review rules only in `foundation-frontend-review`, test rules only in `foundation-testing`, generated-code layout only in `generated-code-extend` §1, transport wiring only in `generated-code-wire-transport`. Other skills link.
- Do not let the commit-msg hook or any other dev-skills tool block a commit: they warn and exit 0.

## Pointers

- README (consumer-facing): `README.md`
- Skill format spec: `docs/SKILL-FORMAT.md`
- Skill format validator: `bin/validate-skills.php` (run via `make validate-skills`)
- Router text of the managed block: `router/AGENTS-router.md`
- Package catalog source: `catalog/manifest.json`
- Release notes: `CHANGELOG.md`
