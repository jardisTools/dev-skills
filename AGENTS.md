# jardis/dev-skills — Agent Notes

Composer plugin that distributes Jardis skills (`<vendor>/.claude/skills/<name>/SKILL.md`) and aggregates `AGENTS.md` from Jardis vendor packages into the consumer project.

## What this package contributes

- **Discovery** of skills from `vendor/jardis*/*/.claude/skills/*/SKILL.md` and from this repo's own `skills/` directory.
- **Bundle skills** (opt-in via `extra."jardis/dev-skills"."bundled-skills"`) covering Jardis methodology:
  - `schema-authoring` — pre-Designer Schema.yaml authoring (companion `examples/Schema.yaml`)
  - `tools-definition` — reading Designer YAML output (companion `examples/Counter/{Aggregate,Source,FieldMap,Lists}.yaml`)
  - `platform-implementation` — extending Designer-generated PHP code (Extensions/ layout, ClassVersion v2 override mechanics, V1–V12 prohibitions)
  - `platform-usage` — wiring Designer-generated Commands/Queries into a transport (HTTP / CLI / queue / worker), DomainResponse mapping
  - `rules-architecture` / `rules-patterns` / `rules-testing` — cross-cutting rules
- **Managed prefixes:** `adapter-`, `core-`, `support-`, `tools-`, `schema-`, `plan-`, `platform-`, `rules-`. Skills with these prefixes are installed/removed by the plugin; skills without them belong to the user.
- **AGENTS.md aggregation** between markers `<!-- BEGIN jardis/dev-skills ... -->` / `<!-- END jardis/dev-skills -->`. User content outside the markers is preserved.

## Working in this repo

- **Architecture:** Closure-Orchestrator — `src/SkillInstaller.php` and `src/SkillUninstaller.php` compose handlers from `src/Handler/`. Data classes under `src/Data/`. No business logic in orchestrators.
- **Plugin entry:** `src/Plugin.php` (`Composer\Plugin\PluginInterface` + `EventSubscriberInterface`) wires `post-install-cmd`, `post-update-cmd`, `pre-package-uninstall`.
- **Tests:** Integration > Unit. New tests go under `tests/Integration/<area>/<ClassName>Test.php`. Use `tests/Support/TempProject` for filesystem fixtures.
- **Quality gates:** `make phpunit` (108+ tests), `make phpstan` (Level 8), `make phpcs` (PSR-12). All three must be green.
- **Skill authoring:** Every bundled `SKILL.md` follows `docs/SKILL-FORMAT.md` v3 — frontmatter `zone`/`prerequisites`/`next`, single-line description (≤60 words), topical numbered body sections (`### 1. …`), per-zone line budget (`post-active` = 550). Long working artefacts live in a sibling `skills/<name>/examples/` directory and do not count against the body budget. Reshape rationale in `docs/PRD-skill-overhaul.md`.

## Don'ts

- Do not introduce a new top-level skill prefix without updating `RemoveJardisSkills::MANAGED_PREFIXES` and `docs/SKILL-FORMAT.md` §2.
- Do not edit a generated AGENTS.md block in a consumer project — the plugin overwrites it on next install.
- Do not bypass `TempProject` in tests with raw `tempnam()` / hardcoded paths.
- Do not duplicate content across bundle skills. Patterns live only in `rules-patterns`, architecture only in `rules-architecture`, test rules only in `rules-testing`, Designer YAML vocabulary only in `tools-definition`, generated-code layout only in `platform-implementation` §1, transport wiring only in `platform-usage`. Other skills link.

## Pointers

- README (consumer-facing): `README.md`
- Skill format spec: `docs/SKILL-FORMAT.md`
- Skill format validator: `bin/validate-skills.php` (run via `make validate-skills`)
- Bundle overhaul rationale: `docs/PRD-skill-overhaul.md`, `docs/PLAN-skill-overhaul.md`
