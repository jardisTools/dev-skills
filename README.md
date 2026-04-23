# jardis/dev-skills

Composer plugin that automatically supplies your AI agent (Claude Code, Cursor, Continue, Aider, ...) with all rules and APIs for the Jardis packages in use — one command, no configuration.

Part of the **[Jardis Business Platform](https://jardis.io)** — DDD ecosystem with integrated AI support.

---

## What does the plugin do?

After `composer install` or `composer update`, the plugin scans your `vendor/` directory for `jardis*` packages, copies their skill definitions (`vendor/<pkg>/.claude/skills/<name>/`) into your project (`.claude/skills/<name>/`), and aggregates all `AGENTS.md` files from Jardis packages into a single `AGENTS.md` in the project root.

Result: Claude Code, Cursor & Co. automatically know the rules, patterns, and APIs of the packages you have pulled in via Composer.

Additionally, the plugin ships **7 cross-package skills** of its own (architecture, pattern, and testing rules; the schema-authoring guide for the Jardis Designer; a reference skill for reading the Designer YAML output; the platform-implementation guide for fachlogik on top of generated code — including the layout of generated PHP classes; and the platform-usage guide for wiring the generated Commands/Queries into an HTTP / CLI / queue transport). Which of these get copied into your project is controlled via `composer.json` — see [Configuring bundled skills](#configuring-bundled-skills). **No bundled skills are installed by default**; you opt in explicitly.

---

## Installation

```bash
composer require --dev jardis/dev-skills
```

After installation you will see a line such as:

```
Jardis Skills installed: 7 skills, 3 AGENTS.md aggregated. See https://docs.jardis.io/skills
```

From that point on, your project contains:

```
your-project/
├── .claude/
│   └── skills/
│       ├── adapter-cache/       ← from vendor/jardisadapter/cache/
│       ├── support-repository/  ← from vendor/jardissupport/repository/
│       ├── rules-architecture/  ← from jardis/dev-skills itself
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
| `core-*` | `jardiscore/*` (Foundation, Kernel) |
| `support-*` | `jardissupport/*` (Repository, Validation, Workflow, ...) |
| `tools-*` | Mixed: `jardistools/*` (DbSchema) **and** plugin-bundled (`tools-definition` — Designer YAML reference) |
| `schema-*` | Plugin itself — `schema-authoring` for the Designer input format |
| `platform-*` | Plugin itself — `platform-implementation` (working on generated code) and `platform-usage` (wiring Commands/Queries into a transport) |
| `rules-*` | Plugin itself — `rules-architecture`, `rules-patterns`, `rules-testing` |

**Custom skills with a different prefix** (`my-*`, `internal-*`, ...) are left untouched by the plugin — neither during install nor during uninstall.

---

## Configuring bundled skills

The 7 bundled skills (`schema-authoring`, `tools-definition`, `platform-implementation`, `platform-usage`, `rules-architecture`, `rules-patterns`, `rules-testing`) are **opt-in**. Controlled via `composer.json`:

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
| Key absent or `false` | No bundled skills (default) |
| `true` | All 7 bundled skills |
| `["rules-*", "schema-authoring"]` | Whitelist shortcut — only matching skills |
| `{ "include": [...], "exclude": [...] }` | Apply include first, then exclude |

**Examples:**

```json
"bundled-skills": ["rules-*"]
```
Installs `rules-architecture`, `rules-patterns`, `rules-testing`.

```json
"bundled-skills": {
    "include": ["rules-*"],
    "exclude": ["rules-patterns"]
}
```
Installs `rules-architecture` and `rules-testing`, **not** `rules-patterns`.

```json
"bundled-skills": {
    "exclude": ["tools-*"]
}
```
Installs all except `tools-definition` (missing `include` = all).

**Pattern syntax:** Shell globs via `fnmatch()` — `*` matches anything, `?` matches one character. No regex.

**Sync behavior:** The config is the source of truth. If you narrow `bundled-skills` (e.g. from `true` to `["rules-*"]`), the next `composer install` removes the deselected bundled skills from `.claude/skills/`, **even if you modified them locally**. Vendor skills and custom skills without a Jardis prefix (`my-*`, `internal-*`) are left untouched.

**Invalid config** (e.g. `bundled-skills: 42`): console warning, falls back to the default (no bundled skills). No abort.

> **Upgrade notes:**
> - The bundled-skills set was reshaped: the three `plan-*` skills (`plan-requirements`, `plan-ddd-modeling`, `plan-data-discovery`) were removed, and `schema-authoring` was added as their focused replacement. `tools-builder` was merged into `platform-implementation` (the layout of generated PHP code is now §1 of `platform-implementation`). `tools-definition`, `platform-implementation`, and the three `rules-*` skills remain but were rewritten for clarity. See `docs/PRD-skill-overhaul.md`.
> - **New:** `platform-usage` covers the thin layer between the generated Domain entry points and HTTP / CLI / queue / worker code — call chain, bootstrap lifetime, DomainResponse → transport mapping. Pair with `platform-implementation` when shipping a service.
> - `schema-authoring` and `tools-definition` now ship a companion `examples/` directory with a full MeterDevice `Schema.yaml` and `Counter/Aggregate|Source|FieldMap|Lists.yaml` so the AI can refer to a complete working artefact instead of reconstructing one from the spec.
> - Before this version all bundled skills were always installed. Set `"bundled-skills": true` to restore the always-install behavior, or choose a subset config that fits your project.

---

## Conflicts

### Skill directories

If a skill directory under `.claude/skills/<name>/` already exists during install (e.g. because you maintained it yourself or an older version came from a Jardis package), the plugin moves the existing directory to `.claude/skills/<name>.backup/` and installs the new version. The console shows a warning with the backup path.

```
<warning>jardis/dev-skills: existing skill "adapter-cache" moved to .claude/skills/adapter-cache.backup</warning>
```

The backup is **not** deleted automatically — you decide whether you need it.

### AGENTS.md

The plugin manages a **managed block** in `AGENTS.md` between the markers `<!-- BEGIN jardis/dev-skills ... -->` and `<!-- END jardis/dev-skills -->`. Everything outside these markers belongs to you and is left untouched.

- **File does not exist yet:** The plugin creates `AGENTS.md`; content is the managed block only.
- **File exists without markers** (your own `AGENTS.md`): The plugin backs up the original once to `AGENTS.md.backup`, carries your content into the new file, and appends the managed block at the bottom. Console:
  ```
  <warning>jardis/dev-skills: existing AGENTS.md moved to /path/AGENTS.md.backup</warning>
  ```
  The backup is **not** deleted automatically.
- **File exists with markers** (re-run): The managed block is replaced in place; your content above and below remains unchanged — including its position. No backup, no warning.
- **Corrupt markers** (only HEADER or only FOOTER, or multiple pairs): Install aborts with `InstallFailedException`. Fix the file manually and run `composer install` again.

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
make install          # composer install
make phpunit          # Tests
make phpstan          # Static analysis level 8
make phpcs            # PSR-12
make start / make stop  # Container lifecycle
```

Architecture: Closure-Orchestrator pattern (`src/SkillInstaller.php`, `src/SkillUninstaller.php`), handlers as `__invoke()` closures under `src/Handler/`, value objects under `src/Data/`. Composer events via `Composer\Plugin\PluginInterface` + `Composer\EventDispatcher\EventSubscriberInterface` in `src/Plugin.php`.

More details: <https://docs.jardis.io/en/skills>

---

## License

MIT — see [LICENSE.md](LICENSE.md).
