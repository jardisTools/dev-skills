# Changelog

All notable changes to `jardis/dev-skills` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **New bundled skill `platform-usage`** (zone `post-active`). Covers the thin layer between Designer-generated Domain entry points and transport code: the 4-hop Api-Registry call chain, `MyApp` bootstrap lifetime per transport (HTTP / CLI / queue / worker), `DomainResponse` → HTTP status / CLI exit code mapping, and the forbidden-patterns list for controllers. Pairs with `platform-implementation` when shipping a service. Brings the bundle total to **7 skills**.
- **Companion `examples/` directories for `schema-authoring` and `tools-definition`.** Full working MeterDevice artefacts (`schema-authoring/examples/Schema.yaml`; `tools-definition/examples/Counter/{Aggregate,Source,FieldMap,Lists}.yaml`) ship alongside the skill bodies. The skills reference them by relative path so the AI can consult a complete, Designer-accepted artefact instead of reconstructing one from the spec. Companion files are copied into consumer projects together with the `SKILL.md` (existing installer behaviour — it always recurses into each skill directory).
- **`AGENTS.md` for the plugin repo itself.** The plugin aggregates `AGENTS.md` from Jardis vendor packages — now ships its own so consumer projects also get the dev-skills context.
- **End-to-end Composer integration test** (`tests/Integration/E2E/PluginEndToEndTest.php`) that runs real `composer install` / `composer remove` against the plugin via path repository, with a fake vendor fixture for skill + AGENTS.md aggregation. Replaces mock-only assertions of the install/uninstall lifecycle.
- **Skill format validator** (`bin/validate-skills.php`, `make validate-skills`, CI job) that checks every bundled `SKILL.md` against `docs/SKILL-FORMAT.md`: required frontmatter fields, kebab-case name matching directory, valid `zone`, single-line description within word cap, presence of at least one `##`/`###` section heading, and length budget per zone. CI fails the build on any violation.

### Fixed
- **AGENTS.md aggregation marker parser was too naive** (`AnalyzeAgentsMd::__invoke`). It used `substr_count`, so any inline mention of the marker string inside a vendor's AGENTS.md (e.g. as documentation) was counted as a marker pair and the file was reported as having "corrupt markers" — blocking AGENTS.md cleanup on `composer remove`. The parser now matches markers only when they stand alone on their own line. Caught by the new E2E test. Two new regression tests in `AnalyzeAgentsMdTest`.

### Changed
- **Skill format spec relaxed (`docs/SKILL-FORMAT.md` v3).** Hands-on iteration on the bundle showed that the prescriptive five-heading body template (`## When this skill applies`, `## What the AI does`, `## Output / Artefact`, `## Handoff`, `## References`) was too narrow for reference-heavy skills like `tools-definition` and `platform-implementation`, and that denser descriptions with multiple trigger terms fire more reliably than minimal one-liners. v3 drops the fixed heading template (skills now pick a topical numbered structure `### 1. …`), raises the description word cap from 30 to 60, and raises the `post-active` line budget from 400 to 550 to fit `platform-implementation`. All remaining invariants (frontmatter shape, kebab-case, zone, single-line description, at least one section heading, per-zone budget) stay enforced by `bin/validate-skills.php`. See `docs/SKILL-FORMAT.md` §4 and §5 for the details, `docs/PRD-skill-overhaul.md` for the postscript.
- **BREAKING — bundled skills reshaped (greenfield overhaul).** The plugin now ships **7 bundled skills** instead of 9, redesigned around a three-zone topology of Jardis development (Pre-Designer / Designer black box / Post-Designer). All seven follow a unified format described in `docs/SKILL-FORMAT.md`. Background, scope, and acceptance criteria: `docs/PRD-skill-overhaul.md`. Phase plan: `docs/PLAN-skill-overhaul.md`.
  - **Removed:** `plan-requirements`, `plan-ddd-modeling`, `plan-data-discovery` (Pre-Designer planning is generic AI work and not Jardis-specific; data discovery is replaced by the focused `schema-authoring`).
  - **Removed (merged):** `tools-builder` — the non-trivial parts (per-aggregate directory layout, generated-vs-skeleton catalogue, Api-registry entry-point rule) are now §1 of `platform-implementation`. Reading generated PHP code is largely self-documenting; the reference skill was redundant.
  - **Added:** `schema-authoring` — guides the developer from "only an idea" to a complete `Schema.yaml` import-ready for the Jardis Designer.
  - **Rewritten:** `tools-definition` (now focused on the non-trivial vocabulary — `erm`/`depend`/`adopt`/`relates` hints, parameter binding — instead of restating the YAML format), `platform-implementation` (now: generated-baseline layout, ClassVersion v2/override mechanics, V1–V12 prohibitions, the seven implementation levels — pattern definitions and architecture rules moved to `rules-*`), `rules-architecture`, `rules-patterns`, `rules-testing` (rewritten in English, narrower triggers, no cross-skill duplication).
  - **Total content reduction:** ~3050 → ~880 lines across the bundle (~71% smaller) while improving trigger reliability and removing duplications.
- **Plugin recognises `schema-*` as a Jardis prefix** for install / uninstall / sync. The legacy `plan-*` prefix is still recognised by the uninstaller so old installations can be cleanly removed.

### Migration

If you were on a previous version with bundled skills enabled, on next `composer install`:
- The new 7 skills are installed (subject to your `bundled-skills` config).
- Old `plan-requirements`, `plan-ddd-modeling`, `plan-data-discovery`, `tools-builder` directories under `.claude/skills/` are **not** automatically removed because they are no longer part of the plugin's bundled set. Delete them manually if you want a clean state.
- A full `composer remove jardis/dev-skills` followed by reinstall removes all `plan-*`, `platform-*`, `rules-*`, `tools-*`, `schema-*` directories cleanly.

## [0.1.0]

### Added
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
- **BREAKING:** Bundled skills are now opt-in. Before this version all 9 skills were always installed; now only with explicit config. Migration: `"extra": { "jardis/dev-skills": { "bundled-skills": true } }` restores the old behavior.
