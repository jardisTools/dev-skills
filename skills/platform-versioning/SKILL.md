---
name: platform-versioning
description: ClassVersion resolution and Versionierungs-Modell for Designer-generated code — the generated `classVersion()` override in the `<Domain>Context` base class wires `LoadClassFromSubDirectory` (injects `v{N}` before the last namespace segment, per class; baseline = the generated class itself), optional `ClassVersionConfig` fallback chains, per-call `$version` argument (no domain-wide default), five Leitsätze (additiv vor Version, Version ändert Verhalten nie API, Datenbruch = neues Aggregat, Code-Rettung über Service-Schicht, one API surface per aggregate).
zone: post-active
persona: C
prerequisites: [platform-implementation]
next: []
---

### 1. ClassVersion resolution — Platform-free, per-class `v{N}`

The aggregate tree is hermetic and **Platform-free** (no `Platform/` segment in path or namespace). Resolution does not walk a `['', 'Platform']` two-segment chain — the **Generator emits a `classVersion()` override** in the generated `<Domain>Context.php` base class that wires the reader `LoadClassFromSubDirectory`:

```php
class MeterDeviceContext implements GeneratedContextInterface
{
    protected function classVersion(): ClassVersionInterface
    {
        $config = $this->classVersionConfig();

        return new ClassVersion(
            $config,
            new LoadClassFromSubDirectory($config),
            cache: new ClassResolutionCache(),
        );
    }
}
```

**No domain-wide version default:** the Domain facade (`<Domain>.php`) is `final` and JardisCore-free (holds only a `DomainKernelInterface` Koffer) — it offers no override surface, and `<Domain>Context` (which hosts `classVersion()`/`classVersionConfig()`, `platform-implementation` §1) is hermetic (never hand-edited). A domain-wide default `version()` hook does not exist — the only lever is the per-call `$version` argument threaded through every facade method (see below).

> **Proxy first.** The wired `ClassVersion` (no `proxyClassFinder` passed above ⇒ Ctor default `LoadClassFromProxy`) consults the **proxy cache first** and only falls to `LoadClassFromSubDirectory` when the proxy returns `null` (`support/classversion/src/ClassVersion.php:62-70`). A generated Domain has no proxy config, so the proxy yields `null` and the SubDirectory resolution below is what runs in practice.

**How `LoadClassFromSubDirectory` resolves** `$this->context(<Class>::class, $dto, $version)` / `$this->handle(<Class>::class)`:

1. It **injects the version before the last namespace segment** (the class name), per class:
   `…\Command\Handler\CreateCounter` + `v2` → `…\Command\Handler\v2\CreateCounter`.
   The `v2/` subdir is the class's **immediate neighbour** — not an aggregate-root `v2/` tree.
2. The version comes from the `$version` argument threaded through the facade method (`string $version = ''`, verified on `{Agg}/{Agg}.php`). Empty version → **no version subdir is tried**; resolution falls straight to the baseline.
3. With a non-empty version: if a `ClassVersionConfig` is present, the reader expands `config->fallbackChain($version)` (e.g. `v3 → [v3, v2, v1]`) and tries each injected class in order; without config it tries just `[$version]`.
4. **First `class_exists` wins.** If no versioned class exists, it falls back to the **baseline** `$className` — i.e. the generated class itself. If even that is missing, `InvalidArgumentException`.

```
Baseline (the generated, hermetic class — always present):
  MeterDevice\Counter\Aggregate\Counter\Command\Handler\CreateCounter

Versioned override (you author it; Generator neither emits nor deletes v{N}/):
  MeterDevice\Counter\Aggregate\Counter\Command\Handler\v2\CreateCounter
  (file: {BC}/Aggregate/{Agg}/Command/Handler/v2/CreateCounter.php)
```

Namespace = `<Domain>\<BC>\Aggregate\<Agg>\…`. There is **no `Platform` segment** and **no dev-baseline-shadow** stage: the baseline *is* the generated class. The only override surface inside the aggregate tree is a per-class `v{N}/` subdir; everything else is hermetic (`platform-implementation` §1–§2).

**Two resolution modes:**

- **No version** (`$version = ''`, the 99 % case): the generated baseline runs. Nothing to configure. The aggregate is hermetic, so to *change* the baseline you re-model in the Designer or author a **Process** — not an in-place override (`platform-implementation` §2).
- **Versioned** (`v1`, `v2`, …) for tenant / feature-flag variants: author the variant at `{Agg}/.../v{N}/<Class>.php` and select it **per call** (reads: `$bc->{agg}()->getCounterById($q, 'v2')`; writes family-internally via the Kernel-Naht: `$this->handle(Counter::class)->createCounter($dto, 'v2')` — the BC accessor returns the read-only `{Agg}Read`). There is **no domain-wide default** (see the note above) — every caller threads its own `$version` argument. The variant survives rebuilds — the Generator does not emit or clean `v{N}/` directories.

> **Scope:** the Platform-removal PRD (P6) wired version **resolution** only. No generator emits a `v{N}/` directory today — version **creation / design** for the Builder is still open (`OPEN_ITEMS.md` "Versionierung Aggregat-Code + Process-Code"). Treat `v{N}/` as the available-but-not-yet-tooled escape hatch.

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
namespace MeterDevice\Counter\Aggregate\Counter\Command\Handler\v2;

use MeterDevice\Counter\Aggregate\Counter\Command\Handler\CreateCounter as Base;

final class CreateCounter extends Base
{
    // version-specific behaviour; parent::__invoke(...) reuses the generated pipeline
}
```

### 2. Versionierungs-Modell

Versions in Jardis are about behaviour, not data shape. Five Leitsätze govern when to reach for `v{N}`, when to extend the schema, and when to spin up a new aggregate.

**Leitsätze:**

- **Additiv geht vor Version.** New nullable fields, new enum members, new optional tables go into the base schema without bumping a version.
- **Version ändert Verhalten, nie API.** `v1` and `v2` of the same aggregate share identical Commands / Queries / Events / Payloads. Only the implementation differs. There is exactly one API surface per aggregate.
- **Datenbruch = neues Aggregat.** A removed field, flipped type, or shifted semantic of a load-bearing field is honest enough to warrant `<Agg>V4` — its own name, its own tree, its own spec, its own entities.
- **Code-Rettung läuft über Abstraktion, nicht über Mechanik.** When data breaks, behaviour is rescued by Domain Services authored in the **Process scope** (`{BC}/Process/`), not by version-merge tricks. Entity-agnostic logic survives any data break; entity-bound logic on the broken fields does not.
- **One API surface per aggregate — punkt.** No v{N}-API matrices, no delta-merge variants.

**Daumenregel — when to use what:**

| Change | Path |
|---|---|
| New nullable column / new enum member / new optional relation | Schema additiv erweitern, no version. Goes into base definition; FieldMap learns the new key; rebuild. |
| New business rule, different calculation, tenant variant, tightened validation | A **Process** (`{BC}/Process/`) for new behaviour, or a **`v{N}` override** at `{Agg}/.../v{N}/<Class>.php` for a tenant/feature-flag variant of an existing generated class, selected via `$version`. Spec stays invariant. |
| Field removed, type flips, semantic of load-bearing field changes | **Neues Aggregat `<Agg>V4`**, eigener Tree, eigene Spec. Generator regeneriert separat. |

**Service-Schicht-Hinweis.** Versionsfreier, entity-agnostischer Code gehört in die **Process-Schicht** (`{BC}/Process/`) — die einzige Developer-Fläche, seit der Aggregat-Baum hermetisch ist. Diese Service-Schicht wird von `v1` / `v2` / `v3` UND einem späteren `<Agg>V4` genutzt. Die Konsequenz von Säule 4 (`rules-architecture` — Data-Behavior-Separation): Logik, die nicht entity-typ-abhängig sein muss, jetzt schon als Service im Process-Scope extrahieren — sie überlebt jeden Datenbruch. (Wo genau geteilte VOs/Services im Process-Scope wohnen, ist eine Developer-Konvention; siehe `ARCHITECTURE_VERSIONING.md` „Offene Punkte" #2 „Service-Schicht-Standard".)

**Rules-Layer precision on "Version ändert Verhalten, nie API".** A Rule (`{BC}/Rule/{Name}.php`, `platform-implementation` §1) is ClassVersion-fähig the same way any generated class is (`Rule/v{N}/{Name}.php`), but the Leitsatz needs a Rule-specific reading: **API** = the `__invoke({Cmd}DTO): RuleResult` signature **plus** the rejection payload shape (`{rule, messageKey, context}`, `messageKey` stable for i18n) — this must not change across versions. **Behaviour** = the accepted set — a `v2` Rule is exactly the place to *tighten* what passes (e.g. add a new precondition), never to change what a rejection looks like to the caller. The Command the Rule guards (its "endpunkt-Identität") is versionless; only the Rule class itself is versioned.

### Anchors

- `platform-implementation` (hermetic aggregate layout, the customization surfaces incl. the Rule catalog, prohibitions).
- `platform-cookbook` (Phase-3 recipes, event transport, troubleshooting).
- `support-classversion` (the `LoadClassFromProxy` → `LoadClassFromSubDirectory` resolver implementations themselves).
- `rules-architecture` (Pillar 4 — Data-Behavior-Separation drives the Service-Schicht-Hinweis above).
