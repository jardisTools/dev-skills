---
name: adapter-fakecache
description: Test fixture skill for E2E plugin tests. Should be discovered, copied, and removable by the plugin.
zone: crosscut
prerequisites: []
next: []
---

## When this skill applies

- Test fixture only — never used in production.

## What the AI does

1. Nothing. This file exists to verify the plugin's discovery, copy, and uninstall behaviour against a real Composer install run.

## Output / Artefact

None.

## Handoff

None.

## References

- `tests/Integration/E2E/PluginEndToEndTest.php`
