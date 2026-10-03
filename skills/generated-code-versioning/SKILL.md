---
name: generated-code-versioning
description: ClassVersion resolution and versioning model for Designer-generated code — the generated `classVersion()` override in the `<Domain>Context` base class wires `LoadClassFromSubDirectory` (injects `v{N}` before the last namespace segment, per class; baseline = the generated class itself), optional `ClassVersionConfig` fallback chains, per-call `$version` argument (no domain-wide default), five guiding principles (additive before version, version changes behaviour never the API, data break = new aggregate, code rescue via service layer, one API surface per aggregate).
zone: post-active
persona: C
profile: jardis
prerequisites: [generated-code-extend]
next: []
---

### 1. ClassVersion resolution — Platform-free, per-class `v{N}`

The aggregate tree is hermetic and **Platform-free** (no `Platform/` segment in path or namespace). Resolution does not walk a `['', 'Platform']` two-segment chain — the **Generator emits a `classVersion()` override** in the generated `<Domain>Context.php` base class that wires the reader `LoadClassFromSubDirectory`.

**No domain-wide version default:** the Domain facade (`<Domain>.php`) is `final` and JardisCore-free (holds only a `DomainKernelInterface` DomainKernel) — it offers no override surface, and `<Domain>Context` (which hosts `classVersion()`/`classVersionConfig()`, `generated-code-extend` §1) is hermetic (never hand-edited). A domain-wide default `version()` hook does not exist — the only lever is the per-call `$version` argument threaded through every facade method (see below).

> **Proxy first, but a no-op here.** The wired `ClassVersion` consults the proxy cache before the
> SubDirectory reader — a generated Domain has no proxy config, so in practice the SubDirectory resolution below
> is what runs.

**How `LoadClassFromSubDirectory` resolves** `$this->context(<Class>::class, $dto, $version)` / `$this->handle(<Class>::class)`. What matters for placing your override:

1. The version is **injected before the last namespace segment** (the class name), per class:
   `…\Command\Handler\CreateCounter` + `v2` → `…\Command\Handler\v2\CreateCounter`.
   The `v2/` subdir is the class's **immediate neighbour** — not an aggregate-root `v2/` tree.
2. The version comes from the `$version` argument threaded through the facade method (`string $version = ''`, verified on `{Agg}/{Agg}.php`). Empty version → **no version subdir is tried**; resolution falls straight to the baseline.
3. With a non-empty version and a `ClassVersionConfig` present, the fallback chain is tried in order (e.g. `v3 → [v3, v2, v1]`); without config only `[$version]`.
4. First match wins; missing versioned class → falls back to the **baseline** (the generated class itself); missing even that → `InvalidArgumentException`.

```
Baseline (the generated, hermetic class — always present):
  MeterDevice\Counter\Model\Counter\Command\Handler\CreateCounter

Versioned override (you author it; Generator neither emits nor deletes v{N}/):
  MeterDevice\Counter\Model\Counter\Command\Handler\v2\CreateCounter
  (file: {BC}/Model/{Agg}/Command/Handler/v2/CreateCounter.php)
```

Namespace = `<Domain>\<BC>\Model\<Agg>\…`. There is **no `Platform` segment** and **no dev-baseline-shadow** stage: the baseline *is* the generated class. The only override surface inside the aggregate tree is a per-class `v{N}/` subdir; everything else is hermetic (`generated-code-extend` §1–§2).

**Two resolution modes:**

- **No version** (`$version = ''`, the 99 % case): the generated baseline runs. Nothing to configure. The aggregate is hermetic, so to *change* the baseline you re-model in the Designer or author a **Process** — not an in-place override (`generated-code-extend` §2).
- **Versioned** (`v1`, `v2`, …) for tenant / feature-flag variants: author the variant at `{Agg}/.../v{N}/<Class>.php` and select it **per call** (reads: `$bc->{agg}()->getCounterById($q, 'v2')`; writes family-internally via the kernel seam: `$this->handle(Counter::class)->createCounter($dto, 'v2')` — the BC accessor returns the read-only `{Agg}Read`). There is **no domain-wide default** (see the note above) — every caller threads its own `$version` argument. The variant survives rebuilds — the Generator does not emit or clean `v{N}/` directories.

> **Scope:** only version **resolution** is wired. No generator emits a `v{N}/` directory — version **creation / design** for the Builder is still open (`OPEN_ITEMS.md` "Versionierung Aggregat-Code + Process-Code" (versioning of aggregate code + process code)). Treat `v{N}/` as the available-but-not-yet-tooled escape hatch.

Optional `ClassVersionConfig` (only when you need fallback chains):

```php
$config = new ClassVersionConfig(
    version:   ['v1' => ['v1'], 'v2' => ['v2'], 'v3' => ['v3']],
    fallbacks: ['v3' => ['v2', 'v1'], 'v2' => ['v1']]
);
```

Versioned override skeleton (the `v2/` subdir sits next to the baseline class; you may extend the generated baseline):

```php
declare(strict_types=1);
namespace MeterDevice\Counter\Model\Counter\Command\Handler\v2;

use MeterDevice\Counter\Model\Counter\Command\Handler\CreateCounter as Base;

final class CreateCounter extends Base
{
    // version-specific behaviour; parent::__invoke(...) reuses the generated pipeline
}
```

### 2. Versioning model

Versions in Jardis are about behaviour, not data shape. Five guiding principles govern when to reach for `v{N}`, when to extend the schema, and when to spin up a new aggregate.

**Guiding principles:**

- **Additive comes before version.** New nullable fields, new enum members, new optional tables go into the base schema without bumping a version.
- **Version changes behaviour, never the API.** `v1` and `v2` of the same aggregate share identical Commands / Queries / Events / Payloads. Only the implementation differs. There is exactly one API surface per aggregate.
- **Data break = new aggregate.** A removed field, flipped type, or shifted semantic of a load-bearing field is honest enough to warrant `<Agg>V4` — its own name, its own tree, its own spec, its own entities.
- **Code rescue runs through abstraction, not through mechanics.** When data breaks, behaviour is rescued by Domain Services authored in the **Process scope** (`{BC}/Process/`), not by version-merge tricks. Entity-agnostic logic survives any data break; entity-bound logic on the broken fields does not.
- **One API surface per aggregate — full stop.** No v{N}-API matrices, no delta-merge variants.

**Rule of thumb — when to use what:**

| Change | Path |
|---|---|
| New nullable column / new enum member / new optional relation | Extend the schema additively, no version. Goes into base definition; FieldMap learns the new key; rebuild. |
| New business rule, different calculation, tenant variant, tightened validation | A **Process** (`{BC}/Process/`) for new behaviour, or a **`v{N}` override** at `{Agg}/.../v{N}/<Class>.php` for a tenant/feature-flag variant of an existing generated class, selected via `$version`. Spec stays invariant. |
| Field removed, type flips, semantic of load-bearing field changes | **New aggregate `<Agg>V4`**, its own tree, its own spec. Generator regenerates separately. |

**Service-layer note.** Version-free, entity-agnostic code belongs in the **Process layer** (`{BC}/Process/`) — the only developer surface, because the aggregate tree is hermetic. This service layer is used by `v1` / `v2` / `v3` AND a later `<Agg>V4`. The consequence of pillar 4 (`foundation-architecture` — Data-Behavior-Separation): logic that need not depend on the entity type should be extracted now as a service in the Process scope — it survives every data break. (Where exactly shared VOs/Services live in the Process scope is a developer convention; see `ARCHITECTURE_VERSIONING.md` "Open points" #2 "Service-layer standard".)

**Rules-Layer precision on "Version changes behaviour, never the API".** A Rule (`{BC}/Closure/{Name}.php`, `generated-code-extend` §1) is ClassVersion-capable the same way any generated class is (`Closure/v{N}/{Name}.php`), but the guiding principle needs a Rule-specific reading: **API** = the `__invoke({Cmd}DTO): RuleResult` signature **plus** the rejection payload shape (`{rule, messageKey, context}`, `messageKey` stable for i18n) — this must not change across versions. **Behaviour** = the accepted set — a `v2` Rule is exactly the place to *tighten* what passes (e.g. add a new precondition), never to change what a rejection looks like to the caller. The Command the Rule guards (its "endpoint identity") is versionless; only the Rule class itself is versioned.

### Anchors

- `generated-code-extend` (hermetic aggregate layout, the customization surfaces incl. the Rule catalog, prohibitions).
- `generated-code-recipes` (Phase-3 recipes, event transport, troubleshooting).
- `support-classversion` (the `LoadClassFromProxy` → `LoadClassFromSubDirectory` resolver implementations themselves).
- `foundation-architecture` (Pillar 4 — Data-Behavior-Separation drives the service-layer note above).
