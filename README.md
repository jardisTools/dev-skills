# jardis/dev-skills

**Make your AI coding agent fluent in Jardis.** Jardis is the Domain-Driven Design platform for PHP: you model your domain, and Jardis generates the production-ready hexagonal code. This Composer plugin automatically supplies Claude Code, Cursor, Continue, Aider & co. with the rules and APIs of every Jardis package you use — and the guides for working on the generated code — in one command, no configuration.

> Part of **[Jardis](https://jardis.io)** — the Domain-Driven Design platform for PHP. You model your domain; Jardis generates the production-ready hexagonal code (DTOs, Command/Query handlers, repositories, persistence). This plugin keeps your AI agent in sync with the rules and APIs that generated code follows.

---

## What does the plugin do?

After `composer install` or `composer update`, the plugin scans your `vendor/` directory for `jardis*` packages, copies their skill definitions (`vendor/<pkg>/.claude/skills/<name>/`) into your project (`.claude/skills/<name>/`), and aggregates all `AGENTS.md` files from Jardis packages into a single `AGENTS.md` in the project root.

Result: Claude Code, Cursor & Co. automatically know the rules, patterns, and APIs of the packages you have pulled in via Composer.

Additionally, the plugin ships **18 cross-package skills** of its own: architecture, frontend-review, pattern, and testing rules; the `schema-authoring` guide for the Jardis Designer; and five `platform-*` guides for working on Designer-generated code — `platform-implementation` (business logic on top of generated code, including the layout of generated PHP classes), `platform-usage` (wiring generated Commands/Queries into an HTTP / CLI / queue transport), `platform-versioning` (ClassVersion resolution and the versioning model), `platform-workflow` (the FlowDesigner Workflow-Engine API), and `platform-cookbook` (Phase-3 recipes, troubleshooting, and event transport). New since 2026-08: five `do-*` Gitflow skills for project repositories — `do-git-branch`, `do-git-commit`, `do-git-push` (the daily branch → commit → PR flow), `do-project-git-setup` (one-time Gitflow setup: develop branch, repo settings, branch ruleset, hooks — no release, no Packagist), and `do-git-compliance` (10 project checks). Which of these fifteen get copied into your project is controlled via `composer.json` — see [Configuring bundled skills](#configuring-bundled-skills). These fifteen skills are **opt-in**; three further skills are installed **by default**: the [Capability Catalog](#capability-catalog-jardis-catalog) (`jardis-catalog`), `jardis-start-here` (the lifecycle master entry point), and `jardis-mcp-consumer` (headless MCP-workflow guide).

---

## Installation

```bash
composer require --dev jardis/dev-skills
```

After installation you will see a line such as:

```
Jardis Skills installed: <N> skills, <M> AGENTS.md aggregated. See https://docs.jardis.io/en/skills
```

From that point on, your project contains:

```
your-project/
├── .claude/
│   └── skills/
│       ├── adapter-cache/       ← from vendor/jardisadapter/cache/
│       ├── support-repository/  ← from vendor/jardissupport/repository/
│       ├── rules-architecture/  ← from jardis/dev-skills itself
│       ├── rules-frontend/      ← from jardis/dev-skills itself
│       ├── rules-testing/       ← from jardis/dev-skills itself
│       └── ...
└── AGENTS.md                    ← aggregated content of all jardis/*-AGENTS.md
```

Requirements: PHP >= 8.3, Composer >= 2.

---

## Which skills are installed?

The plugin **detects** skills exclusively by directory name prefix. These prefixes are reserved for Jardis:

| Prefix | Source |
|---|---|
| `adapter-*` | `jardisadapter/*` (HTTP, Cache, Messaging, Mailer, ...) |
| `core-*` | `jardiscore/*` — `kernel` (the DomainKernel: immutable `DomainKernel` infrastructure holder + optional ENV bootstrap packer) and `app` (HTTP-Delivery: FastRoute router, PSR-15 middleware pipeline, DomainResponse-to-PSR-7 mapper). `jardiscore/foundation` was removed in the Kernel-Entkopplung (2026-07); its ENV-bootstrap role was absorbed into `kernel`. |
| `support-*` | `jardissupport/*` (Repository, Validation, Workflow, ...) |
| `tools-*` | `jardistools/*`. The bundled `tools-definition` skill was retired — Designer YAML vocabulary lives in `tools-builder-engine` (Builder repo); `tools-dbschema` was retired with the `jardistools/dbschema` package (2026-07-24), its capability moves into the Builder core. |
| `schema-*` | Plugin itself — `schema-authoring` for the Designer input format |
| `platform-*` | Plugin itself — `platform-implementation` (working on generated code), `platform-usage` (wiring Commands/Queries into a transport), `platform-versioning` (ClassVersion resolution + versioning model), `platform-workflow` (FlowDesigner Workflow-Engine API), `platform-cookbook` (Phase-3 recipes, troubleshooting, event transport) |
| `rules-*` | Plugin itself — `rules-architecture`, `rules-frontend` (stack-agnostic FE review constitution), `rules-patterns`, `rules-testing` |
| `do-*` | Plugin itself — Gitflow skills for project repositories: `do-git-branch`, `do-git-commit`, `do-git-push`, `do-project-git-setup`, `do-git-compliance` |

**Custom skills with a different prefix** (`my-*`, `internal-*`, ...) are left untouched by the plugin — neither during install nor during uninstall.

---

## Configuring bundled skills

The 15 bundled skills (`schema-authoring`, `platform-implementation`, `platform-usage`, `platform-versioning`, `platform-workflow`, `platform-cookbook`, `rules-architecture`, `rules-frontend`, `rules-patterns`, `rules-testing`, `do-git-branch`, `do-git-commit`, `do-git-push`, `do-project-git-setup`, `do-git-compliance`) are **opt-in**. Three further skills — the Capability Catalog (`jardis-catalog`), `jardis-start-here`, and `jardis-mcp-consumer` — are installed by default and managed separately — see [Capability Catalog](#capability-catalog-jardis-catalog). The 15 opt-in skills are controlled via `composer.json`:

```json
{
    "extra": {
        "jardis/dev-skills": {
            "bundled-skills": true
        }
    }
}
```

**Accepted values:**

| Value | Effect |
|---|---|
| Key absent | `jardis-catalog`, `jardis-start-here`, `jardis-mcp-consumer` installed; no other bundled skills (default) |
| `false` | Nothing installed — opts out of the three default-on skills as well |
| `true` | All 10 bundled skills + the three default-on skills |
| `["rules-*", "schema-authoring"]` | Whitelist shortcut — only matching skills; add `"jardis-catalog"` to the list to include it |
| `{ "include": [...], "exclude": [...] }` | Apply include first, then exclude; absent `include` = all skills, empty `include` (`[]`) = none |

**Examples:**

```json
"bundled-skills": ["rules-*"]
```
Installs `rules-architecture`, `rules-frontend`, `rules-patterns`, `rules-testing`.

```json
"bundled-skills": {
    "include": ["rules-*"],
    "exclude": ["rules-patterns"]
}
```
Installs `rules-architecture`, `rules-frontend`, and `rules-testing`, **not** `rules-patterns`.

```json
"bundled-skills": {
    "exclude": ["rules-patterns"]
}
```
Installs all bundled skills except `rules-patterns` (missing `include` = all).

**Pattern syntax:** Shell globs via `fnmatch()` — `*` matches anything, `?` matches one character. No regex.

**Sync behavior:** The config is the source of truth. If you narrow `bundled-skills` (e.g. from `true` to `["rules-*"]`), the next `composer install` removes the deselected bundled skills from `.claude/skills/`, **even if you modified them locally**. Vendor skills and custom skills without a Jardis prefix (`my-*`, `internal-*`) are left untouched.

**Invalid config** (e.g. `bundled-skills: 42`): console warning, falls back to the default (no bundled skills). No abort.

> **Upgrade notes:**
> - **Bundle grown to 13 skills (2026-07-06).** Two new default-on skills were added: `jardis-start-here` (the lifecycle master entry point, routing to every other skill) and `jardis-mcp-consumer` (headless MCP-workflow guide via `jardis mcp`). Default-on install now brings three skills instead of one (`jardis-catalog` plus these two); opt out via `"bundled-skills": false` as before.
> - **Bundle grown to 10 skills (2026-06-28).** `rules-frontend` was added — a stack-agnostic frontend review constitution (component boundaries, state discipline, an e2e-heavy test pyramid, an accessibility minimum bar, type-safety at the data boundary). Opt-in like the rest; a `["rules-*"]` whitelist now installs all four rules skills. The concrete UI framework arrives via the assignment, never from the skill.
> - **Bundle grown to 9 skills (2026-06-01).** Three `platform-*` guides were added for working on Designer-generated code: `platform-versioning` (ClassVersion resolution + versioning model), `platform-workflow` (FlowDesigner Workflow-Engine API), and `platform-cookbook` (Phase-3 recipes, troubleshooting, event transport). They are opt-in like the rest; a `["platform-*"]` whitelist now installs all five platform skills.
> - **Bundle slimmed to 6 skills (v4, 2026-05-23).** `tools-definition` was retired from the dev-skills bundle. Its Schema-YAML coverage was already in `schema-authoring`; the Aggregate / Source / FieldMap / Lists / Flow vocabulary moved to `tools-builder-engine` in the Builder repo (Persona E — Builder dev, outside this bundle). Consumers with `"bundled-skills"` configs that referenced `tools-definition` or `tools-*` should drop the entry; no other action needed. See `docs/PRD-skill-overhaul.md` §V4.
> - The earlier bundled-skills reshape (v1–v3) removed three `plan-*` skills (`plan-requirements`, `plan-ddd-modeling`, `plan-data-discovery`), added `schema-authoring` as their focused replacement, and merged `tools-builder` into `platform-implementation` §1.
> - **New (v3):** `platform-usage` covers the thin layer between the generated Domain entry points and HTTP / CLI / queue / worker code — call chain, bootstrap lifetime, DomainResponse → transport mapping. Pair with `platform-implementation` when shipping a service.
> - `schema-authoring` ships a companion `examples/` directory with a full MeterDevice `Schema.yaml` so the AI can refer to a complete working artefact instead of reconstructing one from the spec.
> - Before this version all bundled skills were always installed. Set `"bundled-skills": true` to restore the always-install behavior, or choose a subset config that fits your project.

---

## Capability Catalog (`jardis-catalog`)

The Capability Catalog is a discovery skill installed **by default** — a deliberate exception to the opt-in policy of the nine bundled skills above.

**What it does:** It lists every Packagist-published Jardis package with a brief capability description, a "use when" trigger, and the exact `composer require` command. When an AI agent is about to build a reusable component from scratch — caching, scheduling, HTTP clients, validation, workflow orchestration, a context registry, or any other DDD scaffolding — the catalog helps it discover the matching Jardis package and recommend `composer require <package>` instead. The full API skill for a package becomes available only after installation; the catalog is intentionally thin (no class names, no API details).

**Why default-on:** The catalog must be present *before* you know you need a package. An opt-in catalog defeats its own purpose. The same reasoning applies to `jardis-start-here` (the lifecycle master entry point, routing to every other skill) and `jardis-mcp-consumer` (the headless MCP-workflow guide) — both are installed by default alongside the catalog.

**How it is generated:** `catalog/manifest.json` in this repository holds the curated package descriptions. The script `bin/generate-catalog.php` renders them into `skills/jardis-catalog/SKILL.md`, which is committed to the repository and shipped with the plugin. No network access is needed at install time — the catalog is fully offline-deterministic.

**Opt-out:** Set `bundled-skills` to `false` in your `composer.json` to suppress everything including the catalog:

```json
{
    "extra": {
        "jardis/dev-skills": {
            "bundled-skills": false
        }
    }
}
```

To install specific bundled skills without the catalog, use an explicit list that omits `jardis-catalog`:

```json
{
    "extra": {
        "jardis/dev-skills": {
            "bundled-skills": ["rules-*", "schema-authoring"]
        }
    }
}
```

Note: `{ "exclude": ["jardis-catalog"] }` alone (with no `include`) means "all bundled skills except `jardis-catalog`" — it does not suppress the catalog while leaving other skills off.

---

## Conflicts

### Skill directories

If a skill directory under `.claude/skills/<name>/` already exists during install (e.g. because you maintained it yourself or an older version came from a Jardis package), the plugin moves the existing directory to `.claude/skills/<name>.backup/` and installs the new version. The console shows a warning with the backup path.

```
<warning>jardis/dev-skills: existing skill "adapter-cache" moved to .claude/skills/adapter-cache.backup</warning>
```

The backup is **not** deleted automatically — you decide whether you need it.

Existing `*.backup` directories are **never** treated as skills: the plugin skips them during discovery and never backs them up again, so repeated installs cannot pile up `…​.backup.backup` directories.

### AGENTS.md

The plugin manages a **managed block** in `AGENTS.md` between the markers `<!-- BEGIN jardis/dev-skills ... -->` and `<!-- END jardis/dev-skills -->`. Everything outside these markers belongs to you and is left untouched.

If an aggregated source package ships its **own** managed block in its `AGENTS.md` (e.g. a Jardis package that itself consumes this plugin), those markers are **stripped** during aggregation — only the package's content outside its markers is embedded. The result therefore always has exactly one BEGIN/END pair, never nested markers.

- **File does not exist yet:** The plugin creates `AGENTS.md`; content is the managed block only.
- **File exists without markers** (your own `AGENTS.md`): The plugin backs up the original once to `AGENTS.md.backup`, carries your content into the new file, and appends the managed block at the bottom. Console:
  ```
  <warning>jardis/dev-skills: existing AGENTS.md moved to /path/AGENTS.md.backup</warning>
  ```
  The backup is **not** deleted automatically.
- **File exists with markers** (re-run): The managed block is replaced in place; your content above and below remains unchanged — including its position. No backup, no warning.
- **Duplicate / nested markers** (more than one BEGIN and/or END, e.g. left behind by an older plugin version that appended instead of replacing): The plugin **self-heals**. The whole region from the first BEGIN to the last END is treated as the managed block and collapsed into a single fresh block; your content outside the markers stays untouched. A notice is printed so the cause stays visible:
  ```
  jardis/dev-skills: korrupte AGENTS.md repariert (mehrfacher managed block zusammengeführt).
  ```
- **Corrupt markers** (only HEADER, only FOOTER, or the first BEGIN sitting after the last END): Install aborts with `InstallFailedException` — these cases are not unambiguously healable. Fix the file manually and run `composer install` again.

---

## Uninstallation

```bash
composer remove jardis/dev-skills
```

During uninstall the plugin removes:

- All skill directories with a Jardis prefix (`adapter-*`, `core-*`, `support-*`, `tools-*`, `schema-*`, `platform-*`, `rules-*`)
- The managed block between the markers from `AGENTS.md`. Further behavior depends on what else is in the file:
  - Only the managed block in the file → the entire file is deleted.
  - Managed block plus your own content → only the block is removed; your content and the file are kept.
  - Corrupt markers (only HEADER or only FOOTER) → file is left untouched, console shows a warning.

**Left untouched:**

- Skills with the legacy `plan-*` prefix from earlier versions of this plugin (the bundled `plan-*` skills were retired; if a `plan-*` directory still exists in your project it is now treated as a custom skill)
- Skills without a Jardis prefix (your own)
- `.backup` directories from earlier conflicts (including `AGENTS.md.backup`)
- An `AGENTS.md` without markers (no Jardis aggregation → no reason to touch it)

---

## Shipping your own Jardis-compatible skills (for package maintainers)

If you are building a Composer package that should ship a skill to the plugin, two things are sufficient:

1. **Package name with a `jardis` prefix** (e.g. `jardisadapter/foo`, `jardissupport/bar`) — the plugin only scans `vendor/jardis*/`.
2. **Skill file** at `<package>/.claude/skills/<skill-name>/SKILL.md`. Recommended prefix per the table above (`adapter-*`, `support-*`, ...) — otherwise the plugin will not ignore the skill, but the uninstall behavior will not apply.
3. **Optional:** `<package>/AGENTS.md` in the package root. Content is aggregated into the project `AGENTS.md` during install.

No further configuration needed — no `extra:` block in `composer.json` required.

---

## Troubleshooting

**Skills do not end up in the project**
The plugin triggers on `post-install-cmd` / `post-update-cmd`. If you call `composer require jardis/dev-skills` inside another Composer script, these events do not fire. Solution: run a separate `composer install` in the project root.

**My `AGENTS.md` is not removed during uninstall**
This is intentional: if the file does not contain the managed block marker `<!-- BEGIN jardis/dev-skills ... -->`, the plugin assumes you have manually taken over or rewritten it. You can only delete it yourself.

**Vendor package has a skill but it is not copied**
Check: is the package name under `vendor/jardis*/`? The plugin scans exclusively this glob pattern (`vendor/jardis*/*/.claude/skills/*/SKILL.md`). Packages without a `jardis` prefix are ignored.

**Custom skill was moved to `.backup/`**
This happens when your skill uses one of the Jardis prefixes and the plugin copies a same-named skill from a vendor package. Renaming (e.g. `adapter-cache` → `my-cache`) resolves this permanently.

---

## Development (plugin maintainers)

Docker-based via `make`:

```bash
make help                       # List all targets
make install                    # composer install
make update                     # composer update
make autoload                   # composer dump-autoload
make phpunit                    # Tests
make phpunit-reports            # Tests with reports
make phpunit-coverage           # Tests with coverage text
make phpunit-coverage-html      # Tests with HTML coverage
make phpstan                    # Static analysis level 8
make phpcs                      # PSR-12
make validate-skills            # Validate every bundled SKILL.md against docs/SKILL-FORMAT.md
make generate-catalog           # Generate skills/jardis-catalog/SKILL.md from catalog/manifest.json
make generate-catalog-check     # Check the checked-in SKILL.md matches the manifest (exit≠0 on drift)
make check-catalog-packagist    # Check Packagist for Jardis packages missing from catalog/manifest.json (warning only)
make shell                      # Shell inside the phpcli container
make clean                      # Stop containers and clean up volumes
make remove                     # Stop and remove containers, images, network and caches
make ssh-agent                  # Get SSH agent ready
make install-hooks              # Install git hooks (pre-commit + pre-push)
```

Architecture: Closure-Orchestrator pattern (`src/SkillInstaller.php`, `src/SkillUninstaller.php`), handlers as `__invoke()` closures under `src/Handler/`, value objects under `src/Data/`. Composer events via `Composer\Plugin\PluginInterface` + `Composer\EventDispatcher\EventSubscriberInterface` in `src/Plugin.php`.

More details: <https://docs.jardis.io/en/skills>

---

## License

MIT — see [LICENSE.md](LICENSE.md).
