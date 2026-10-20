---
name: generated-code-versioning
description: ClassVersion resolution and versioning model for Designer-generated code — the generated `classVersion()` override in the `<Domain>Context` base class wires `LoadClassFromSubDirectory` (injects `v{N}` before the last namespace segment, per class; baseline = the generated class itself), optional `ClassVersionConfig` fallback chains, per-call `$version` argument (no domain-wide default), the HTTP front door (`X-Jardis-Version` header → `jardisVersion` request attribute → `$version` on every facade door → `meta.version`; baseline fallback; 400 `request.versionUnknown`; known versions declared as `versions` in the domain manifest, `knownVersions`, `versions.php`, V-VERSION-DRIFT) with a step-by-step recipe, five guiding principles (additive before version, version changes behaviour never the API, data break = new aggregate, code rescue via service layer, one API surface per aggregate).
zone: post-active
persona: C
profile: jardis
prerequisites: [generated-code-extend]
next: []
---

### 1. ClassVersion resolution — Platform-free, per-class `v{N}`

The aggregate tree is hermetic and **Platform-free** (no `Platform/` segment in path or namespace). Resolution does not walk a `['', 'Platform']` two-segment chain — the **Generator emits a `classVersion()` override** in the generated `<Domain>Context.php` base class that wires the reader `LoadClassFromSubDirectory`.

**No domain-wide version default:** the Domain facade (`<Domain>.php`) is `final` and JardisCore-free (holds only a `DomainKernelInterface` DomainKernel) — it offers no override surface, and `<Domain>Context` (which hosts `classVersion()`/`classVersionConfig()`, `generated-code-extend` §1) is hermetic (never hand-edited). A domain-wide default `version()` hook does not exist — the only lever is the per-call `$version` argument threaded through every facade method (see below; over HTTP the generated route fills it from the `X-Jardis-Version` header, §3).

> **Proxy first, but a no-op here.** The wired `ClassVersion` consults the proxy cache before the
> SubDirectory reader — a generated Domain has no proxy config, so in practice the SubDirectory resolution below
> is what runs.

**How `LoadClassFromSubDirectory` resolves** `$this->context(<Class>::class, $dto, $version)` / `$this->handle(<Class>::class)`. What matters for placing your override:

1. The version is **injected before the last namespace segment** (the class name), per class:
   `…\Command\Handler\Action\ValidateNewValue` + `v2` → `…\Command\Handler\Action\v2\ValidateNewValue`.
   The `v2/` subdir is the class's **immediate neighbour** — not a root `v2/` tree. Only a stub class (node, Closure/Rule, Domain Service) has such a variant; a hermetic class has none (`generated-code-extend` §10).
2. The version comes from the `$version` argument threaded through the facade method (`string $version = ''`, verified on `{Agg}/{Agg}.php`). Empty version → **no version subdir is tried**; resolution falls straight to the baseline.
3. With a non-empty version and a `ClassVersionConfig` present, the fallback chain is tried in order (e.g. `v3 → [v3, v2, v1]`); without config only `[$version]`.
4. First match wins; missing versioned class → falls back to the **baseline** (the generated class itself); missing even that → `InvalidArgumentException`.

```
Baseline (the generated stub class — always present):
  MeterDevice\Counter\Process\CounterChange\Command\Handler\Action\ValidateNewValue

Versioned variant (you author it; Generator neither emits nor deletes v{N}/):
  MeterDevice\Counter\Process\CounterChange\Command\Handler\Action\v2\ValidateNewValue
  (file: {BC}/Process/CounterChange/Command/Handler/Action/v2/ValidateNewValue.php)
```

There is **no `Platform` segment** and **no dev-baseline-shadow** stage: the baseline *is* the generated class. A per-class `v{N}/` subdir exists only beside a stub class (node, Closure/Rule, Domain Service); the aggregate tree and every other generated class are hermetic and take no variant (`generated-code-extend` §1–§2, §10).

**Two resolution modes:**

- **No version** (`$version = ''`, the 99 % case): the generated baseline runs. Nothing to configure. The aggregate is hermetic, so to *change* the baseline you re-model in the Designer or author a **Process** — not an in-place override (`generated-code-extend` §2).
- **Versioned** (`v1`, `v2`, …) for tenant / feature-flag variants of a stub class: author the variant in the stub's `v{N}/` subdir (`Action/v{N}/<Node>.php`, `Closure/v{N}/<Name>.php`, `Service/v{N}/<Name>.php`) and select it **per call** through the `$version` argument of the facade door that starts the run (a process: `$bc->process()->counterChange($dto, 'v2')`). There is **no domain-wide default** (see the note above) — every caller threads its own `$version` argument. The version of a process call applies to every node and every sub-process of the run: a node variant is selected by the one `$version` of the process-facade call; a class without a variant stays baseline. The variant survives rebuilds — the Generator does not emit or clean `v{N}/` directories.

> **Scope:** version **resolution** is wired, and since the versioned front door (§3) the HTTP caller can select a version and the set of selectable versions is declared in the domain manifest. No generator emits a `v{N}/` class — authoring the variant of a stub is hand code at row 11 of the table in `generated-code-extend` §10 (the recipe in §4). Declarative design of *what* a version changes (affected Closure, behaviour change) does not exist in the definition format.

Optional `ClassVersionConfig` (only when you need fallback chains):

```php
$config = new ClassVersionConfig(
    version:   ['v1' => ['v1'], 'v2' => ['v2'], 'v3' => ['v3']],
    fallbacks: ['v3' => ['v2', 'v1'], 'v2' => ['v1']]
);
```

Versioned variant skeleton (the `v2/` subdir sits next to the baseline stub; the stub is `final`, so the variant is a full class of its own with the same entry point):

```php
declare(strict_types=1);
namespace MeterDevice\Counter\Process\CounterChange\Command\Handler\Action\v2;

final class ValidateNewValue extends MeterDeviceContext
{
    public function __invoke(CounterChangeContext $ctx): NodeResult
    {
        // version-specific body — same signature, same declared routes and rejects as the baseline stub
    }
}
```

### 2. Versioning model

Versions in Jardis are about behaviour, not data shape. Five guiding principles govern when to reach for `v{N}`, when to extend the schema, and when to spin up a new aggregate.

**Guiding principles:**

- **Additive comes before version.** New nullable fields, new enum members, new optional tables go into the base schema without bumping a version.
- **Version changes behaviour, never the API.** `v1` and `v2` of the same aggregate share identical Commands / Queries / Events / Payloads. Only the implementation differs. There is exactly one API surface per aggregate.
- **Data break = new aggregate.** A removed field, flipped type, or shifted semantic of a load-bearing field is honest enough to warrant `<Agg>V4` — its own name, its own tree, its own spec, its own entities.
- **Code rescue runs through abstraction, not through mechanics.** When data breaks, behaviour is rescued by Domain Services (`{BC}/Service/`), not by version-merge tricks. Entity-agnostic logic survives any data break; entity-bound logic on the broken fields does not.
- **One API surface per aggregate — full stop.** No v{N}-API matrices, no delta-merge variants.

**Rule of thumb — when to use what:**

| Change | Path |
|---|---|
| New nullable column / new enum member / new optional relation | Extend the schema additively, no version. Goes into base definition; FieldMap learns the new key; rebuild. |
| New business rule, different calculation, tenant variant, tightened validation | A **Process** (`{BC}/Process/`) for new behaviour, or a **`v{N}` variant** of a stub class (node, Closure/Rule, Domain Service) in the stub's `v{N}/` subdir for a tenant/feature-flag variant, selected via `$version`. Spec stays invariant. |
| Field removed, type flips, semantic of load-bearing field changes | **New aggregate `<Agg>V4`**, its own tree, its own spec. Generator regenerates separately. |

**Service-layer note.** Version-free, entity-agnostic code belongs in a Domain Service (`{BC}/Service/`) — the one location for shared logic (`generated-code-extend` §10), because the aggregate tree is hermetic. This service layer is used by `v1` / `v2` / `v3` AND a later `<Agg>V4`. The consequence of pillar 4 (`foundation-architecture` — Data-Behavior-Separation): logic that need not depend on the entity type should be extracted now as a Domain Service — it survives every data break.

**Rules-Layer precision on "Version changes behaviour, never the API".** A Rule (`{BC}/Closure/{Name}.php`, `generated-code-extend` §1) is ClassVersion-capable the same way any stub class is (`Closure/v{N}/{Name}.php`), but the guiding principle needs a Rule-specific reading: **API** = the `__invoke({Cmd}DTO): RuleResult` signature **plus** the rejection payload shape (`{rule, messageKey, context}`, `messageKey` stable for i18n — guaranteed by the catalog when the entry declares a `messageKey`, since the 422 carries that key whatever the versioned body passes to `reject()`) — this must not change across versions. **Behaviour** = the accepted set — a `v2` Rule is exactly the place to *tighten* what passes (e.g. add a new precondition), never to change what a rejection looks like to the caller. The Command the Rule guards (its "endpoint identity") is versionless; only the Rule class itself is versioned.

### 3. HTTP front door — `X-Jardis-Version`

For a domain served through `jardiscore/app` the caller selects the class version with one request header. The chain is the same whether the call is a read, a list, a declared query, an exposed Command or a Process:

```
X-Jardis-Version: v2
  → jardiscore/app  ResolveVersion          reads the header ONCE (src/Handler/Request/ResolveVersion.php)
  → request attribute  jardisVersion        'v2' — or '' (baseline) when the header is absent
  → generated route (Api/{Domain}/routes.php) reads the attribute, passes it as $version to its facade door
  → {Agg}Read / {BC}Process / exposed Command method   ($version → every node and sub-process of the run)
  → envelope  meta.version = 'v2'
```

| Case | Result |
|---|---|
| No header | `jardisVersion = ''` → baseline (the generated classes); `meta.version` is the empty baseline |
| Declared version, `v{N}/` class exists for the class that runs | the `v{N}/` variant runs, `meta.version = 'v2'` |
| Declared version, **no** `v{N}/` class for a given class | **Baseline fallback** — that class runs as generated; `meta.version` still names the **requested** version (`v2`) |
| Value not in the known set, empty value, or the header repeated | `400` in the validation form — `data = {"@type":"validation","fields":[{"field":"X-Jardis-Version","reason":"invalid","message":…,"messageKey":"request.versionUnknown"}]}` — answered in the gate before any route or handler runs; 404/405 unaffected |

Comparison is exact and case-sensitive; a version has the form `^v[1-9][0-9]*$`.

**Where the set of known versions comes from.** It is declared where the domain is modelled: the list `versions: ["v2", …]` in `Domain.json` (MCP `update_domain_manifest` field `versions`, whole-list overwrite, `[]` clears; read via resource `jardis://domain/{domain}/manifest`; UI: tab *Stammdaten*, "Bekannte Versionen"). Not a directory scan of `v{N}/`. Each build writes `src/Api/{Domain}/versions.php` next to `routes.php` (`<?php return ['v2'];`, generated, hermetic, empty list allowed); the app template's `public/index.php` unites all `src/Api/*/versions.php` and hands the result to `AppConfig(knownVersions: …)`. Without a declaration the app serves the baseline only, and the generated OpenAPI contract carries no header.

**Contract.** With declared versions the OpenAPI document has `components.parameters.XJardisVersion` (header, optional, `enum` = declaration); every operation references it and lists the `400` above (`request.versionUnknown` is a member of the `ValidationError` enum).

**Drift warning V-VERSION-DRIFT.** The Builder compares the declaration with the `v{N}/` directories found in the generated output and warns in both directions (a declared version without any `v{N}/` class; a `v{N}/` directory that is not declared). It stays silent without an output directory or an unbuilt domain. It is a warning, never a source of the list.

> **Minimum package version:** `jardiscore/app` >= 1.2.0 (the gate and `AppConfig::$knownVersions`).

### 4. Recipe — serve a `v2` variant over HTTP

A neutral example: domain `MeterDevice`, BC `Counter`, a Process `CounterChange` with the node `ValidateNewValue`.

1. **Declare the version** in the domain manifest: MCP `update_domain_manifest` with `versions: ["v2"]` (or UI *Stammdaten* → "Bekannte Versionen"). Format `v{N}`, no duplicates.
2. **Author the variant** next to the baseline stub, as hand code (the Generator never emits or deletes `v{N}/`):
   `{BC}/Process/CounterChange/Command/Handler/Action/v2/ValidateNewValue.php`, namespace `MeterDevice\Counter\Process\CounterChange\Command\Handler\Action\v2` (skeleton in §1). A Rule variant lives at `{BC}/Closure/v2/{Name}.php`, a Domain Service variant at `{BC}/Service/v2/{Name}.php`; a hermetic class has none.
3. **Build** (`build` / `build_domain`). This rewrites `routes.php`, `versions.php` and the OpenAPI contract; V-VERSION-DRIFT is quiet once step 1 and 2 agree.
4. **Call it:**
   `curl -H 'X-Jardis-Version: v2' -X POST …/counter/counterChange -d '{…}'` — the answer carries `"meta": {"version": "v2", …}`. The same call without the header runs the baseline and carries the empty baseline version; `-H 'X-Jardis-Version: v9'` (not declared) answers `400` with `request.versionUnknown`.

Version changes behaviour, never the API (§2): the route, the DTO and the envelope are identical for every version.

### Anchors

- `generated-code-extend` (hermetic aggregate layout, the one place-table §10, the Rule catalog, prohibitions).
- `generated-code-recipes` (Phase-3 recipes, event transport, troubleshooting).
- `core-app` (`ResolveVersion`, request attribute `jardisVersion`, `AppConfig(knownVersions:)`, the 400 form of the front door).
- `generated-code-wire-transport` (how the version reaches a facade door from a hand-written transport).
- `support-classversion` (the `LoadClassFromProxy` → `LoadClassFromSubDirectory` resolver implementations themselves).
- `foundation-architecture` (Pillar 4 — Data-Behavior-Separation drives the service-layer note above).
