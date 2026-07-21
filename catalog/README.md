# Catalog Manifest

This directory contains the curated source of truth for the Jardis Capability Catalog.

## Purpose

`manifest.json` is the hand-curated input from which `bin/generate-catalog.php` renders
`skills/jardis-catalog/SKILL.md`. It lists every Packagist-published Jardis package with
capability-oriented metadata so an AI agent can discover suitable packages **before**
installing them — and recommend `composer require` instead of building from scratch.

The file is versioned and checked in. The generator is deterministic: given the same
manifest it always produces the same `SKILL.md` (byte-for-byte). CI verifies this.

## Schema

Each entry in the top-level JSON array must have the following fields:

| Field              | Required | Description |
|--------------------|----------|-------------|
| `package`          | yes      | Composer package name, e.g. `"jardissupport/scheduling"` |
| `capability`       | yes      | One-line description of what the package provides, in plain capability language. **No class names, method names, API signatures, or code.** English. |
| `use_when`         | yes      | When an agent should reach for this package. Starts with "you need…". English. |
| `composer_require` | yes      | Exact install command, e.g. `"composer require jardissupport/scheduling"` |
| `alternatives`     | no       | Short note when another package covers overlapping ground — helps the agent distinguish between similar packages. |

The array must be sorted alphabetically by `package`. Do not add vendor-specific grouping
keys at the top level; the generator handles grouping for rendering.

## Grundgesamtheit — which packages belong

**In scope:** every package published on Packagist under the four vendor namespaces
`jardiscore`, `jardisadapter`, `jardissupport`, `jardistools`.

**Excluded:**
- `jardis/dev-skills` (the plugin itself)
- `core/starter`, `tools/magicfaker`, `tools/builder` (not published on Packagist)

**Special case:** `jardissupport/contracts` has no dedicated bundle skill but **must** have a
catalog entry. The curated text explains the package's role without referencing any
concrete class.

CI validates the manifest against the live Packagist vendor lists
(`/packages/list.json?vendor=<vendor>`) and emits a warning when a package is present on
Packagist but missing from the manifest. The check is additive-only — the reverse direction
(present in the manifest, absent from Packagist) is deliberately not reported, since a
curated entry may legitimately precede publication. An unreachable Packagist API is
not a build failure.

## Curation rules

- `capability` and `use_when` texts are **curated by hand** — not generated from
  package-skill descriptions (which contain class names and API references).
- No Jardis-specific class names, interface names, method signatures, or code snippets
  in any text field.
- Technology and protocol names (e.g. SMTP, Redis, MySQL, AES-256-GCM) are allowed
  because they are industry-standard terms, not Jardis API identifiers.
- All text fields are in **English**.
