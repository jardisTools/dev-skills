# Changelog

All notable changes to `jardis/dev-skills` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project uses [Semantic Versioning](https://semver.org/).

## [1.16.0] - 2026-10-08

### Changed
- The aggregated `AGENTS.md` lists each Jardis vendor package as a short block instead of its full `AGENTS.md`: the capability from `catalog/manifest.json` (else the package intro), `Use when`, the skills to load and the docs URL. A typical project file shrinks from about 47 KB to below 16 KB.
- The Codex size warning (`AGENTS.md is N bytes, above the Codex limit ...`) appears only when `codex` is listed in `hosts`.

## [1.15.0] - 2026-10-08

### Added
- Skill `design-model-capabilities` (profile `jardis`, zone `pre`): the law "use the platform before you program", per Jardis tool (schema, aggregate, value list, query, process, closure, field map, strategic layer, tooling) the settings, allowed values, generated effect and MCP/UI door, the list of what Jardis does not have, the mandatory capability table "model element → Jardis capability → used / not used" with an example, and the reading order before the first interview.

### Changed
- `foundation-working-principles`: new rule 4 "Use the platform before you program", `next` adds `design-model-capabilities`.
- `start-orientation`: the design phase and the routing table point to `design-model-capabilities` before the interview.
- `process-concept` and `process-write-prd`: in a Jardis project the capability table is a mandatory part of the concept and the PRD.
- The bundle has 34 skills; the profile `jardis` installs 34, `core` stays at 25 (README, `AGENTS.md`, overview pages, `BundleSkills`).

## [1.14.1] - 2026-10-08

### Changed
- The "exists and is not a reviewer shell of the plugin; the file was left unchanged." notice is collected into ONE summary line per install (names shortened after five).

### Documentation
- README: `AGENTS.md` and `.agents/skills/<package>/` change on every install/require of a Jardis package and belong in the commit.
- `generated-code-wire-transport`: the root update carries no child lists.

## [1.13.0] - 2026-10-08

### Added
- **Key `extra."jardis/dev-skills"."hosts"`**: the tools that get reviewer shells, a list of `"claude"`, `"codex"`, `"cursor"`, `"copilot"`, `"gemini"`. Without the key it is `["claude"]`; an invalid value (not a list, unknown name) warns and counts as absent.

### Changed
- Reviewer shells are written only for the listed hosts (before: always all five). A host that drops out of the list loses the shells the manifest lists for it on the next install; foreign files stay, emptied `…/agents` folders go.
- `.gemini/settings.json` is created or changed only with `gemini` in `hosts`; without it an entry of an earlier run is taken out. `.agents/skills`, `.claude/skills`, `AGENTS.md` and the `CLAUDE.md` import do not depend on `hosts`.
- Projects that rely on shells for Codex, Cursor, Copilot or Gemini add the matching names to `hosts`.

## [1.9.0] - 2026-10-04

### Changed
- `process-close`: new order archive before delivery: docs sync and digest (4) → archive the folder (5) → delivery (6) → retro (7); the delivery carries no working papers.
- `process-close`: the workshop folder is archived instead of deleted: `docs/vorhaben/<name>/` is moved to `tmp/archiv/<name>/` (`tmp/archiv/` sits in the managed block of `.git/info/exclude` in both modes, `.gitignore` is never touched); in committed mode only the removal is committed, with `process-docs: local` it is moved without a commit.
- Squash merge is the rule: `git-push-and-open-pr` states the merge method, `git-setup-repository` sets `allow_merge_commit` and `allow_rebase_merge` to `false`.

## [1.8.2] - 2026-10-04

### Changed
- `process-concept`: the progress head line `Type` is `feature` or `bug` only (the former `project` is gone; a head without a valid type shows "type missing"), and `BC` names exactly one bounded context — a workshop never spans more than one BC (Jardis builder, decision 2026-10-04).

## [1.8.1] - 2026-10-04

### Added
- `generated-code-extend` section 11 "The way": model, check, build, hand code, tests, QA, one skill per step; linked from `process-run-stage` and `start-orientation` (DS4).
- Brief template: input line "Skills of the phase", gates line "Model check". Project profile template: lines "Door tests", "Model check", "Model per tier", "Backlog file" (DS4).
- FINDINGS format: third value `- **Finding <n>:** decided — <one sentence: what the human decided>` (DS4).
- Progress head: free line `- **Verdict:** green|red E<n> <date>`, written by step 8 of `process-run-stage`, deleted at the merge (DS4).
- `process-run-stage`: stop wish read before each new phase (tool-neutral); model checks run before the gates; a gate that cannot run leaves the stage unchecked (DS4).
- PROGRESS.md template: three closing sections with exact line grammars — `## Acceptance check` (`- <n>: met|gap|deferred — <evidence>`), `## Open points` (`- <text> — resolved|backlog|rejected — <due sentence>`), `## Knowledge` (`- yes|no — <entry>`); a missing line or missing evidence means "not checked" (DS5).
- `design-headless-mcp`: `run_qa`, `run_make_target`, `stop_make_target`, `post_workshop_card`, the workshop resources (`jardis://workshops[/{name}]`, `jardis://closed-workshops`, `jardis://project/make-targets`) and the section "Workshop build control" (DS6).

### Changed
- `process-run-stage`: one branch per undertaking, created only when missing; the merge per stage keeps the branch (DS4).
- `process-close`: the backlog location comes from the profile line "Backlog file"; order triage → lessons → acceptance → docs sync and digest → delivery → delete the folder after the delivery (DS4, DS5).
- `process-verify`/acceptance gate: the gate returns CRITERION/VERDICT, the main session writes them as `## Acceptance check`; phase `close` is written only when the human accepts (DS5).
- `process-resume`: a folder is active until the closing deletes it (DS5).
- Open-question gate: escalation names the next stronger tier, not a model (the tool shells write no model).

## [1.8.0] - 2026-10-03

### Added
- **Key `extra."jardis/dev-skills"."agents-md"`** (`"aggregate"` or `"none"`; any other value warns and counts as absent). `none` keeps the plugin's managed block out of the `AGENTS.md` of the project, and also the import block in `CLAUDE.md` and the entry in `.gemini/settings.json`.
- **Default by root package name.** Without the key, a project whose root package has a vendor part beginning with `jardis` (`jardis/`, `jardiscore/`, `jardissupport/`, `jardisadapter/`, `jardistools/`) gets `none`; every other project gets `aggregate`, as before. An explicit value beats the default.

### Changed
- Jardis packages get no managed block in their `AGENTS.md` any more, which is a deliverable and no workplace. A block, import block or Gemini entry an earlier release wrote there is removed at the next install (text outside the block stays byte for byte; an `AGENTS.md` the plugin created and that is empty afterwards is deleted). Skills, reviewer agent files, manifest and exclude block are unchanged; projects with another root package name see no difference.

## [1.7.1] - 2026-10-03

### Fixed
- **Changelog.** Entries for 1.6.0 and 1.6.1 added.
- **Overview pages (en, de).** The subtitle names the current version instead of 1.5.0; the reviewer-role count, the skill count in the installation picture and the `bundled-skills` comment name the profile (17 or 19 roles, 25 or 33 skills); the areas `design` and `generated-code` are marked as profile `jardis`.

## [1.7.0] - 2026-10-03

### Added
- **Installation profile `core | jardis`.** Every bundled skill declares `profile: core` or `profile: jardis` in its frontmatter (new required field, checked by the validator). `jardis` holds the eight skills for the Jardis Designer and its generated code (`start-orientation`, `design-*`, `generated-code-*`); the other 25 are `core`.
- **Profile resolution** in every run: the key `extra."jardis/dev-skills"."profile"` (`"core"` or `"jardis"`; any other value warns), else an installation from before 1.7.0 keeps `jardis`, else detection of a package folder `vendor/jardis*/*` other than `jardis/dev-skills`. The manifest records the profile of the last run (optional field, schema stays 1). A Jardis package that arrives later pulls the Jardis skills in, one that goes takes them out again (changed folders are backed up).
- **Router blocks per profile.** `router/AGENTS-router.md` carries a `profile:jardis` and a `profile:core` area; the core router has a `## PHP projects` section instead of `## Jardis projects`. Both stay within 4 KiB.
- **Reviewer shells per profile.** In the profile `core` the roles `plan-review-packages` and `plan-review-ddd-tactics` get no agent files (17 instead of 19 roles); existing files stay.
- **`packages-find-existing` as the bridge into Jardis.** The catalog is for any PHP project and states which packages install on their own: every `jardissupport/*` and `jardisadapter/*` package, none of which requires `jardiscore/*`.

### Changed
- A fresh project without a Jardis package installs 25 skills instead of 33. Existing installations are unchanged: they keep all 33 skills and stay unmarked in the manifest until the key `profile` is set.

## [1.6.1] - 2026-10-03

### Changed
- **`design-headless-mcp` and `design-draft-schema` match the MCP parity of the Builder.** `import_schema` normalises `tables` like `analyze_schema` and the UI and writes the analysis report; `save_process` keeps the stored layout when `layoutJson` is omitted; Closure catalog entries, `rename_closure`, `rename_value_list` and `set_stack_selection` state their clearing and `confirm` rules; the output directory is set with `update_project_settings` (`outputDir`).

## [1.6.0] - 2026-10-03

### Added
- **`git-rules: "delegated"`.** A third value of the `git-rules` key: the session creates the branch and the commits itself, merge and push stay gates of the human. The router carries a second area (`<!-- git-rules:delegated -->`); the installer keeps exactly one of the two git areas, or none with `false`. Any other value stays strict and warns.
- **`process-review-board`: `FINDINGS.md`.** The merged list with its rulings is written to `docs/vorhaben/<name>/FINDINGS.md`, with the new template `templates/FINDINGS.md`.
- **`(Addendum)` mark.** A line changed after the approval ends with `(Addendum)` in `process-write-prd`, `process-write-plan` and `process-concept`.
- **`process-write-prd`: solution in model building blocks.** The part `## Solution` is described in Aggregate, Process, Query, Rule, Value list and the like; what the model does not carry is named as hand code. The skeptic reviewer checks it.
- **`generated-code-extend` section 10: boundary between model and hand code.** One table of what the generator owns and what is written by hand.
- **`process-concept` and the progress template: five optional head lines.** `Title`, `Type`, `Ticket`, `BC` and `Skipped` may follow the four required lines.

### Changed
- **Project profile read earlier.** `process-concept` reads `.claude/PROJECT_PROFILE.md` before the first interview question, `process-write-prd` at entry.
- **`process-write-plan`: tests as a step.** The order inside a stage is generated, by hand, then tests.
- **`foundation-testing` section 6 describes the generated test scaffold** `tests/Support/{Domain}/` and `new DomainKernel(projectRoot:, connection:)`; `generated-code-extend` lists it among the ForceOverwrite files.
- `process-run-stage`, `process-close`, `process-resume` and `docs/SKILL-FORMAT.md` name both git stances.

## [1.5.0] - 2026-10-02

### Added
- **`process-concept`: the understanding sheet.** `docs/vorhaben/<name>/UNDERSTANDING.md` with six fixed sections (two more for a bug) is the third concept form, with the template `templates/UNDERSTANDING.md`.
- **`process-write-prd`: solution and decisions.** A `## Solution` part (BC, New, Changed) and numbered `### Decision <n>` blocks with the line mark `(Decision <n>)`.
- **`process-write-plan`: four lines per stage.** After `Depends on` follow Generated, By hand, Done when and Halt; Generated and By hand use the entry form ``<Kind> `<Name>` · <note>``, separated by `; `, or `none`.
- The follow-up documents carry the new formats: the overview pages (en, de), `start-orientation`, `process-resume`, `process-run-stage`, `process-verify`, `process-close`, the review board with its reviewer texts and the progress template now name the understanding sheet, the PRD solution and decisions parts and the four stage lines.

### Changed
- `process-concept`: the concept artefact is no longer "never prose"; the sheet is the one allowed text form, and the approval covers the sheet.
- `process-write-prd`: the part "Open decisions" becomes "Decisions"; "Source" points to the approved sheet and the picture.

## [1.4.0] - 2026-10-01

### Added
- **33 bundle skills under a new naming scheme.** Names start with the prefix of their area: `start-`, `packages-`, `design-`, `generated-code-`, `foundation-`, `git-`, `knowledge-`, `process-` and `code-review-`. 18 skills are renamed; 15 are new: ten `process-*` skills (tier choice, concept, PRD, plan, review board, stage run, verification, closing, resume, existing-capability check), `code-review-change`, `foundation-php`, `foundation-working-principles`, `knowledge-maintain-pool` and `knowledge-record-decision`.
- **Redirect skills for the 18 old names.** An update from 1.3.x installs one small skill per old name that points to the new name; old `bundled-skills` globs (for example `platform-*`) keep selecting the renamed skills.
- **Manifest** `.claude/skills/.jardis-managed.json`: the plugin records every skill folder it installs and removes only those on update and uninstall. Skill folders of the user are never touched. A manifest written by a newer plugin version is left alone. Locally changed skill folders are moved to `.claude/.jardis-backup/` before they are replaced; a symlinked skill folder is neither backed up nor removed and ends up as a warning.
- **Second skill target** `.agents/skills`, next to `.claude/skills`.
- **Reviewer shells for five tools.** The 19 reviewer sources of `process-review-board` are written as agent files for Claude Code (`.claude/agents`), Codex (`.codex/agents`), Cursor (`.cursor/agents`), GitHub Copilot (`.github/agents`) and Gemini (`.gemini/agents`). A file the plugin did not write is never overwritten.
- **Router text in the managed `AGENTS.md` block**: the process tiers and the phase-to-skill table. The block warns when `AGENTS.md` grows past 32 KiB, the limit up to which Codex reads the file.
- **`CLAUDE.md` import and Gemini context entry.** A managed block in `CLAUDE.md` imports `@AGENTS.md`; `.gemini/settings.json` lists `AGENTS.md` in `context.fileName`.
- **Knowledge pool tools.** `scripts/pool-check.php` (linked as `vendor/bin/pool-check.php`) checks the page layout, links, path references and size caps of the pool in `.claude/wissen/` and the progress head and plan caps of the work folders in `docs/vorhaben/`; it only reads. The `commit-msg` hook (`scripts/commit-msg`) warns when a `feat:` or `fix:` commit carries no `Wissen:` note or a malformed one. **The hook only warns and never rejects a commit.** `git-setup-repository` installs it through `scripts/install-commit-msg-hook`; `scripts/check-commit-messages` runs the same check over a commit range in CI.
- **Switch `extra."jardis/dev-skills"."git-rules"`.** The git rules of the router are on by default; `false` switches them off.
- **Switch `extra."jardis/dev-skills"."process-docs"`.** `committed` (default) leaves everything to the commit; `local` writes a managed block to the Git exclude file (`.git/info/exclude`) that keeps the process document folders, the files the plugin installs and the manifest out of the commit. The backup folder is excluded in both modes.
- **Validator** (`SKILL-FORMAT` v6): zone `process` (250 lines), persona `O`, area prefixes, a link check for `prerequisites` and `next`, and a check of the rule markers with their cap figures.
- **Release gates:** `make check-public-text` (local home paths and private terms in shipped text; also in CI) and `make check-changelog-top VERSION=x.y.z` (the top version heading of this file is the version to tag).

### Changed
- `AGENTS.md` that is a symlink, or lies behind one, is no longer written. The plugin leaves the file unchanged and warns; to keep the managed block, make `AGENTS.md` a regular file and let `CLAUDE.md` import it with `@AGENTS.md`.
- An update from 1.3.x migrates only in the second Composer run: the update run is still driven by the old plugin code. Run `composer install` once more after the update.
- Without a manifest (first run after an update from 1.3.x), the fixed list of the old and new bundle skill names decides what is installed and removed, no longer a name prefix. Backups of locally changed skill folders moved from `<name>.backup` next to the skill to `.claude/.jardis-backup/`.
- The bodies of the generated-code and headless-MCP skills are English.
- **`bundled-skills` defaults.** Without the key, all 33 bundle skills are installed (1.3.x: only the catalog, start and MCP skills). `false`, an empty list and an invalid value install only the mandatory groups `foundation-*` and `process-*` (an invalid value also warns); a glob list or an `{include, exclude}` object still selects, and the mandatory groups are always added.
- Diff emission follows in a later version; `generated-code-versioning` does not describe it yet.

### Removed
- Nothing is removed in this version. The 18 redirect skills for the old names are removed in 2.0.0; switch to the new names before then.

## [1.3.6] - 2026-09-29

### Changed
- CI: Dependabot configuration unified, unused Claude workflow removed, `composer/composer` dev requirement updated. No user-visible changes to the skills.

## [1.3.5] - 2026-09-29

### Changed
- Public skills no longer refer to private knowledge sources.

## [1.3.4] - 2026-09-27

### Changed
- **Closure-Editor consumer state caught up (2026-09-27).** `jardis-mcp-consumer`: load-door fields `dockable`/`usedAt`/`usedAtUnknown`/`scalarTypes`/`policyCoverage`, the `…/work` package (`body`, `files.stubExists`/`testExists`) and its HTTP twin `GET /api/closures/.../work`, plus a rename/delete cascade over every `uses` reference instead of Set members only. `platform-cookbook` recipe 10 names the authoring path (Closure-Editor or `save_closures`) and the work package before the `__invoke()` body; `platform-implementation` states that a Closure rename does NOT move the dev stub (warning instead of merge) and a delete with references returns `409 IN_USE`; `platform-workflow` names rule-node capability (verdict + exactly one input) as its own blocker condition before `ruleSubject.payloadField`; `jardis-start-here` routes headless Closure authoring to `jardis-mcp-consumer`.

## [1.3.3] - 2026-09-26

### Fixed
- Skills caught up on the Rule-subject state: aggregate input, Specification glossary, Closure lifecycle tools and the Rule node.

## [1.3.2] - 2026-09-26

### Fixed
- Skills caught up on the "Closure with contract" state: n-input contract and the MCP work package.

## [1.3.1] - 2026-09-26

### Fixed
- Skills caught up on the Closure move: `Rules.json`, the `{BC}/Rule/*` paths and the MCP tool names now read `Closures.json`, `{BC}/Closure/*/*` and the `*_closures` tools.

## [1.3.0] - 2026-09-24

### Fixed
- Four skills caught up on the generated tree: the aggregate collector directory is now `Model/`, and the Process path no longer contains `processes/`.

## [1.2.9] - 2026-09-20

### Fixed
- `platform-usage`: reference path updated to the projection-conventions page.

## [1.2.8] - 2026-09-06

### Changed
- **`rules-frontend` sharpened (stack-agnostic, 2026-09-06).** Four sentences drawn from a large refactoring: shared mechanics have a measurable denominator (N users / 0 private copies); every derivation lives in a DOM-free module beside the component; URL/query parameters are an external contract read in one place; behaviour-preserving refactoring starts with characterisation tests and pre-change snapshots. No framework named, no new axis.

## [1.2.7] - 2026-09-06

### Fixed
- Definition files are JSON: documentation caught up in six skills, plus `examples/Schema.json`.

## [1.2.6] - 2026-09-05

### Fixed
- Hand-editing definition files is no longer documented as an entry path.

## [1.2.5] - 2026-09-05

### Changed
- Skill history narratives removed; seven consistency defects fixed.

## [1.2.4] - 2026-09-03

### Changed
- `platform-implementation`, `platform-cookbook`, `jardis-mcp-consumer`: every query is a paginated list now (`limit` mandatory, `form` retired with V-QDEF-24, `Lists.yaml` and the derived selector retired 2026-09-03). Rules read the list over the Kernel-Naht (`limit: 1`, decide on `total`); the AI path (`save_queries` → `save_rules reads:` → `build` → rule body) is documented with the "open invoices" example, plus the new drift category `dev_code_dangling_handler`.

## [1.2.3] - 2026-09-02

### Fixed
- `jardis-mcp-consumer`: removed references to an inventory document; the catalog is the live surface.

## [1.2.2] - 2026-08-27

### Fixed
- `platform-*` skills: key-bulk read `getBy{PluralKey}s` and the list-handle rule synced.

## [1.2.1] - 2026-08-27

### Fixed
- `platform-implementation`: outer-door route convention R3 and a clarification of V12.

## [1.2.0] - 2026-08-23

### Added
- `jardis-mcp-consumer`: MCP parity for `Queries.yaml` and the schema SQL export; `jardis-start-here`: the Query-Designer.

## [1.1.0] - 2026-08-23

### Added
- **Five `do-*` Gitflow skills for project repositories** (bundle grown to 18, opt-in count to 15): `do-git-branch`, `do-git-commit`, `do-git-push` (the daily branch → commit → PR flow), `do-project-git-setup` (one-time Gitflow setup: develop branch, repo settings, branch protection ruleset, hooks — deliberately no version tag, no release, no Packagist), and `do-git-compliance` (10 project checks: hooks, uncommitted secrets, Gitflow branches, CI wiring, branch sync). Adapted for application projects from the internal package-release skills; the required status checks match the app template's `ci.yml` job names (`phpcs`, `phpstan`, `tests`). Opt in via `"bundled-skills": {"include": ["do-git-*", "do-project-git-setup", ...]}`.

## [1.0.7] - 2026-08-23

### Fixed
- Catalog docs: removed two nonexistent packages from the excluded list.

## [1.0.6] - 2026-08-22

### Changed
- Docs: the term "Koffer" renamed to "DomainKernel" in README, manifest and skills.

## [1.0.5] - 2026-08-15

### Changed
- Skill bodies sharpened to their WHAT role (implementation-internal material removed).

## [1.0.4] - 2026-08-13

### Changed
- **`auto-release.yml` deployed (fleet standard, byte-identical to the 14 sibling repos).** Releases of this repository are now driven by the `Auto Release` workflow: `fix/*` / `hotfix/*` merged into `develop` produces an automatic PATCH release; `feature/*` opens a release PR that waits for a `/release minor|major` comment. **Manual tagging is retired for this repository.**

## [1.0.3] - 2026-08-13

### Added
- **`platform-cookbook` recipe 11 — "Invariante als Zustand".** Documents how a uniqueness invariant that spans an aggregate is upheld without check-then-act: a first-writing Torwächter node plus a CAS-UPDATE, shown on the Statusaggregat/Fakturierung case and the Nummernkreis/Reservierung-with-Retry case.
- **`platform-workflow` — `responseStatus` derivation.** Describes how the routing status of the terminating node determines the status carried by the `DomainResponse`, so a Process author can predict the response shape from the graph.

## [1.0.2] - 2026-08-09

### Changed
- Dependencies: `php_codesniffer` bumped 3.13.5 to 3.13.6 (security advisory blocked every install).
- Docs: three outdated assurances in the generated-code contract corrected.
- Catalog: `jardistools/dbschema` removed from the catalog and skills.

## [1.0.1] - 2026-07-21

### Fixed
- Docs: `jardissupport/contract` renamed to `jardissupport/contracts`.

## [1.0.0] - 2026-07-21

### Added
- **New bundled skill `platform-usage`** (zone `post-active`). Covers the thin layer between Designer-generated Domain entry points and transport code: the 4-hop Api-Registry call chain, `MyApp` bootstrap lifetime per transport (HTTP / CLI / queue / worker), `DomainResponse` → HTTP status / CLI exit code mapping, and the forbidden-patterns list for controllers. Pairs with `platform-implementation` when shipping a service. Brought the bundle total to **7 skills** (v3); v4 then dropped it to **6** after `tools-definition` was retired.
- **Companion `examples/` directories for `schema-authoring` and `tools-definition`.** Full working MeterDevice artefacts (`schema-authoring/examples/Schema.yaml`; `tools-definition/examples/Counter/{Aggregate,Source,FieldMap,Lists}.yaml`) ship alongside the skill bodies. The skills reference them by relative path so the AI can consult a complete, Designer-accepted artefact instead of reconstructing one from the spec. Companion files are copied into consumer projects together with the `SKILL.md` (existing installer behaviour — it always recurses into each skill directory).
- **`AGENTS.md` for the plugin repo itself.** The plugin aggregates `AGENTS.md` from Jardis vendor packages — now ships its own so consumer projects also get the dev-skills context.
- **End-to-end Composer integration test** (`tests/Integration/E2E/PluginEndToEndTest.php`) that runs real `composer install` / `composer remove` against the plugin via path repository, with a fake vendor fixture for skill + AGENTS.md aggregation. Replaces mock-only assertions of the install/uninstall lifecycle.
- **Skill format validator** (`bin/validate-skills.php`, `make validate-skills`, CI job) that checks every bundled `SKILL.md` against `docs/SKILL-FORMAT.md`: required frontmatter fields, kebab-case name matching directory, valid `zone`, single-line description within word cap, presence of at least one `##`/`###` section heading, and length budget per zone. CI fails the build on any violation.
- **New bundled skill `rules-frontend` (bundle grown 9 → 10, 2026-06-28).** Stack-agnostic frontend review constitution covering five axes — component boundaries, state discipline, an e2e-heavy test pyramid, an accessibility minimum bar, and type-safety at the data boundary — the measuring stick a frontend architect or reviewer holds a UI plan or component against. The concrete UI framework arrives via the assignment, never from the constitution. Opt-in via `bundled-skills`; a `["rules-*"]` whitelist now selects all four rules skills. The `rules-*` prefix was already managed, so install / uninstall / sync needed no change.
- **Three new `platform-*` bundled skills (bundle grown 6 → 9, 2026-06-01).** Added for Persona C / D working on Designer-generated code: `platform-versioning` (ClassVersion resolution chain via `LoadClassFromExtensions` + the five-Leitsatz Versionierungs-Modell), `platform-workflow` (the Workflow-Engine routing API — six statuses, `WorkflowBuilder`, `WorkflowContext` slots — consumed by FlowDesigner-generated Use-Case orchestrators), and `platform-cookbook` (Phase-3 recipes, the troubleshooting table, and event transport via `<Agg>EventRouter.php`). All three are opt-in via `bundled-skills`; a `["platform-*"]` whitelist now selects all five platform skills. The `platform-*` prefix was already managed, so install / uninstall / sync needed no change.
- **Bundled skills configurable** via `composer.json` → `extra."jardis/dev-skills"."bundled-skills"`. Accepted values: `true` (all), `false`/absent (none), `["glob", ...]` whitelist shortcut, or `{"include": [...], "exclude": [...]}`. Patterns are shell globs via `fnmatch()`. Details in the [README](README.md#configuring-bundled-skills).
- **Sync behavior:** When the config is narrowed, the next `composer install` removes the deselected bundled skills from `.claude/skills/`, even if they were modified locally. Vendor skills and custom prefixes are left untouched.
- Invalid config values produce a console warning and fall back to the default; no abort.
- `AGENTS.md` user content preservation during install/uninstall. The managed block is replaced or removed in place; user content outside the markers is left untouched. An existing `AGENTS.md` without markers is backed up to `AGENTS.md.backup` on first install.
- Uninstall error paths throw `UninstallFailedException` instead of silent return values.
- Initial release of `jardis/dev-skills` — Composer plugin for automatic installation of Jardis skills and aggregated `AGENTS.md` in consumer projects.
- Discovery of `vendor/jardis*/*/.claude/skills/*/SKILL.md` and `vendor/jardis*/*/AGENTS.md`.
- 9 cross-package skills bundled: `plan-requirements`, `plan-data-discovery`, `plan-ddd-modeling`, `platform-implementation`, `rules-architecture`, `rules-testing`, `rules-patterns`, `tools-definition`, `tools-builder`.
- Uninstall handler: removes Jardis skills (prefix match `adapter-*`, `core-*`, `support-*`, `tools-*`, `plan-*`, `platform-*`, `rules-*`) and cleans up `AGENTS.md`; local skills without a Jardis prefix are kept.
- Maintainer script `bin/migrate-skills.php` for the one-time rollout into the Jardis package repositories (removes `.claude/` blanket rules from `.gitignore` and replaces them with granular entries).

### Changed
- **Bundle X-1 consistency pass.** Three pre-X-1 examples that survived earlier iterations are gone: (a) `platform-usage` §3 JSON envelope replaced with the actual `{"data": {"identifier": "…"}}` (Command) and `{"data": {"counter": {...}}}` (Query) shapes the generator emits; (b) `rules-testing` §6 assertion key changed from stale `counterIdentifier` to `identifier`; (c) `platform-implementation` Recipe 6 cleaned of invalid PHP pseudo-syntax, "Pillar 7" → V1 reference, and renderer file:line citations.
- **`platform-implementation` Werkzeugkasten index** (new §14) cross-references all 22 adapter-`*` / support-`*` / core-`*` / `tools-dbschema` skills. Persona C now has a single lookup for "I need cache / mail / HTTP / event-dispatcher etc." without guessing skill names. Plus eight Builder-wave refactors (X-2, X-3, F3.1, F3.6, Phase 5.1a, Phase 5.4) reflected as implementer-relevant consequences in §1 / §4 / §6 / §9 / Recipe 6, without leaking generator internals (M-1/M-2 and aggregateNS-removal stayed in the Builder repo only).
- **Skill format spec relaxed (`docs/SKILL-FORMAT.md` v3).** Hands-on iteration on the bundle showed that the prescriptive five-heading body template (`## When this skill applies`, `## What the AI does`, `## Output / Artefact`, `## Handoff`, `## References`) was too narrow for reference-heavy skills like `tools-definition` and `platform-implementation`, and that denser descriptions with multiple trigger terms fire more reliably than minimal one-liners. v3 drops the fixed heading template (skills now pick a topical numbered structure `### 1. …`), raises the description word cap from 30 to 60, and raises the `post-active` line budget from 400 to 550 to fit `platform-implementation`. All remaining invariants (frontmatter shape, kebab-case, zone, single-line description, at least one section heading, per-zone budget) stay enforced by `bin/validate-skills.php`. See `docs/SKILL-FORMAT.md` §4 and §5 for the details, `docs/PRD-skill-overhaul.md` for the postscript.
- **BREAKING — bundled skills reshaped (greenfield overhaul).** The plugin now ships **6 bundled skills** (down from 9 originally; 7 after v3 added `platform-usage`; 6 after v4 retired `tools-definition`). Redesigned around the four-persona axis (A / C / D in the bundle; E lives in the Builder repo) on a three-zone topology of Jardis development (Pre-Designer / Designer black box / Post-Designer). All six follow a unified format described in `docs/SKILL-FORMAT.md`. Background, scope, and acceptance criteria: `docs/PRD-skill-overhaul.md`. Phase plan: `docs/PLAN-skill-overhaul.md`.
  - **Removed:** `plan-requirements`, `plan-ddd-modeling`, `plan-data-discovery` (Pre-Designer planning is generic AI work and not Jardis-specific; data discovery is replaced by the focused `schema-authoring`).
  - **Removed (merged):** `tools-builder` — the non-trivial parts (per-aggregate directory layout, generated-vs-skeleton catalogue, Api-registry entry-point rule) are now §1 of `platform-implementation`. Reading generated PHP code is largely self-documenting; the reference skill was redundant.
  - **Added:** `schema-authoring` — guides the developer from "only an idea" to a complete `Schema.yaml` import-ready for the Jardis Designer.
  - **Rewritten:** `tools-definition` (now focused on the non-trivial vocabulary — `erm`/`depend`/`adopt`/`relates` hints, parameter binding — instead of restating the YAML format), `platform-implementation` (now: generated-baseline layout, ClassVersion v2/override mechanics, V1–V12 prohibitions, the seven implementation levels — pattern definitions and architecture rules moved to `rules-*`), `rules-architecture`, `rules-patterns`, `rules-testing` (rewritten in English, narrower triggers, no cross-skill duplication).
  - **Total content reduction:** ~3050 → ~880 lines across the bundle (~71% smaller) while improving trigger reliability and removing duplications.
- **Plugin recognises `schema-*` as a Jardis prefix** for install / uninstall / sync. The legacy `plan-*` prefix is still recognised by the uninstaller so old installations can be cleanly removed.
- **Skill format spec v4 + persona purity enforcement (`docs/SKILL-FORMAT.md` v4, 2026-05-23).** New mandatory `persona: A | C | D` frontmatter field, two new validator checks: (1) persona value whitelist (B/E rejected — those personas live outside the bundle), (2) generator-internals token ban in the body (`Render(`, `Stage(`, `PHPRenderer`, `\bIR\b`, `Build{Entities,Aggregates,Flow,PlatformFacade,IntegrationAggregate,IntegrationBC,IntegrationDomain}`, `internal/builder/`, `tools/builder/internal/`) — with Anchors-section whitelist so cross-references to builder-side material stay legal. `post-active` length budget bumped from 550 → 700 lines (`platform-implementation` Werkzeugkasten + Builder-wave-refactor entries needed the room). Background: PRD V4.1 / V4.4 / V4.5.
- **BREAKING:** Bundled skills are now opt-in. Before this version all 9 skills were always installed; now only with explicit config. Migration: `"extra": { "jardis/dev-skills": { "bundled-skills": true } }` restores the old behavior.

### Fixed
- **AGENTS.md aggregation now self-heals a duplicated managed block instead of bricking the install** (`AnalyzeAgentsMd::__invoke`). A consumer whose `AGENTS.md` had ended up with the managed block twice (nested `BEGIN BEGIN END END` or sequential `BEGIN END BEGIN END`, e.g. left behind by an earlier plugin version that appended instead of replacing) could never recover: `AnalyzeAgentsMd` threw `InstallFailedException` on anything but exactly one marker pair, so `composer install` aborted — and with it every CI job that runs `make install` first. The analyzer now treats the region from the **first** BEGIN to the **last** END as the managed region, and the orchestrator collapses it into a single fresh block on the next install; everything outside the markers is preserved. Only genuinely ambiguous corruption (BEGIN without END, END without BEGIN, or first BEGIN after last END) still throws. When a heal happens the plugin prints a notice (`io->write`, not an error) via a new `healedDuplicateBlock` flag carried through `AgentsMdAnalysis` → `AggregateAgentsResult` → `InstallReport` → `Plugin`. `AnalyzeAgentsMd` stays pure (no IO); `BuildManagedBlock` and `composePayload` are unchanged. The healing is idempotent (a second install leaves the file byte-identical). Regression tests in `AnalyzeAgentsMdTest` / `AggregateAgentsMdTest`; full requirement in `REQUIREMENT.md`.
- **AGENTS.md aggregation marker parser was too naive** (`AnalyzeAgentsMd::__invoke`). It used `substr_count`, so any inline mention of the marker string inside a vendor's AGENTS.md (e.g. as documentation) was counted as a marker pair and the file was reported as having "corrupt markers" — blocking AGENTS.md cleanup on `composer remove`. The parser now matches markers only when they stand alone on their own line. Caught by the new E2E test. Two new regression tests in `AnalyzeAgentsMdTest`.

### Removed
- **Bundled skill `tools-definition` retired (bundle slimmed from 7 → 6).** Per PRD §V4 (Persona overhaul, 2026-05-23), the bundle now targets four explicit personas (A Greenfield-Schema-AI, C Implementer-AI, D Application-Layer-Dev — Persona B "Designer-Companion" struck because the Jardis Designer has no AI hooks; Persona E "Builder-Dev" lives outside this bundle). The Aggregate / Source / FieldMap / Lists / Flow YAML vocabulary moved to `tools-builder-engine` in the Builder repo (Persona E). Schema YAML coverage was already inside `schema-authoring`. Consumers with `"bundled-skills"` configs referencing `tools-definition` or a `tools-*` whitelist that depended on it should drop the entry; the `tools-*` prefix is still managed (still used by `tools-dbschema`).

### Migration

If you were on a previous version with bundled skills enabled, on next `composer install`:
- The new 7 skills are installed (subject to your `bundled-skills` config).
- Old `plan-requirements`, `plan-ddd-modeling`, `plan-data-discovery`, `tools-builder` directories under `.claude/skills/` are **not** automatically removed because they are no longer part of the plugin's bundled set. Delete them manually if you want a clean state.
- A full `composer remove jardis/dev-skills` followed by reinstall removes all `plan-*`, `platform-*`, `rules-*`, `tools-*`, `schema-*` directories cleanly.
