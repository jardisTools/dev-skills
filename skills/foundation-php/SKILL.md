---
name: foundation-php
description: Reference for PHP 8.3 code in Jardis projects — strict types, PHPStan level 8, PSR-4, no traits, Closure-Orchestrator PHP form, src/ layout, pattern syntax, test naming. Consult before writing or reviewing any PHP class or composer.json.
zone: crosscut
persona: C
profile: core
prerequisites: [foundation-architecture]
next: [foundation-patterns, foundation-testing]
---

## Scope

The PHP realisation of the language-neutral rules in `foundation-architecture`. Every finding is argued against both: the pillars and the Closure-Orchestrator concept come from `foundation-architecture`, the syntax, tooling and directory conventions come from here.

### 1. Tooling and pillar 3 in PHP

```
PHP 8.3+ | declare(strict_types=1) | PHPStan Level 8 | PSR-4 / PSR-12 | Coverage ≥ 80%
```

- Every file starts with `declare(strict_types=1);`.
- Static analysis runs at PHPStan level 8.
- **No traits.** Reuse goes through composition.
- `abstract` only for exception hierarchies.
- `static` only for value object named constructors (`Email::from()`).

### 2. Closure: one class, one `__invoke()`

A closure is a class with `__invoke()` as its only public entry point. The class name says what it does. No `run()`, `execute()` or `handle()`.

```php
final class BuildKey
{
    public function __invoke(string $prefix, string $path): string
    {
        return $this->normalize($prefix) . '/' . ltrim($path, '/');
    }

    private function normalize(string $prefix): string
    {
        return rtrim($prefix, '/');
    }
}
```

Private helpers are fine. A second public method means the class is two closures.

### 3. Orchestrator: bind closures in the constructor

The orchestrator binds its closures with first-class callable syntax and only chains them — output of one is input of the next. It holds no business logic.

```php
final class Filesystem
{
    private readonly Closure $buildPath;
    private readonly Closure $validatePath;

    public function __construct(string $root)
    {
        $this->buildPath    = (new BuildFullPath($root))->__invoke(...);
        $this->validatePath = (new ValidatePath())->__invoke(...);
    }

    public function read(string $path): string
    {
        $fullPath  = ($this->buildPath)($path);
        $validated = ($this->validatePath)($fullPath);

        return file_get_contents($validated);
    }
}
```

- Store the closure once (`(new Handler())->__invoke(...)`); never call `new Handler()` inline in a method body.
- A closure may wrap another closure (decorator): an authorisation closure takes the permission check as a closure argument, a retry closure wraps the transport closure.
- When an interface prescribes several public methods, the orchestrator implements each one by delegating to its own closure.

### 4. Directory layout

```
src/
├── Orchestrator1.php         orchestrators at the root, the entry points
├── Orchestrator2.php
├── Handler/                  all closures, grouped by category
│   ├── Feature1/
│   │   ├── DoSomething.php
│   │   └── DoSomethingElse.php
│   └── Feature2/
│       └── ProcessData.php
├── Data/                     all value objects, enums, builders
├── Config/                   optional: cohesive configuration value objects and enums
└── Exception/                exceptions
tests/
├── Integration/              mirrors src/
└── Support/                  test fakes
```

- Orchestrators sit directly in `src/`. No handler outside `Handler/`.
- Data classes live in `Data/`. A cohesive sub-package such as `Config/` is allowed when it bundles one self-contained concern; scattering data classes across feature directories is not.
- Exceptions live in `Exception/`. Test fakes (for example `InMemoryTokenStore`) live in `tests/Support/`, never in `src/`.
- Package skills (`adapter-http`, `adapter-mailer`, `support-auth`) show this layout in shipped code.

### 5. Anti-patterns

| Anti-pattern | Fix |
|---|---|
| Private method identical in three or more classes | Extract a closure |
| Closure with two or more public methods | Split it |
| Orchestrator with its own business logic | Move the logic into a closure |
| Closure longer than 150 lines | Extract sub-closures |
| Handlers scattered in feature directories | Central `Handler/` with category subdirectories |
| Value objects or enums scattered in feature directories | Central `Data/` or a cohesive `Config/` |
| Test fakes in `src/` | Move to `tests/Support/` |
| `new Handler()` called inline | Bind `(new Handler())->__invoke(...)` once |

### 6. Pattern syntax

The pattern rules live in `foundation-patterns`. Their PHP forms:

- **Lazy initialisation:** `$this->connection ??= $this->create();`
- **Factory:** `match ($type) { ... }` dispatch in one place — no `new` in business logic.
- **Value object:** `readonly` properties.

### 7. Tests in PHP

The test rules live in `foundation-testing`. The PHP conventions:

- Mapping: `src/Cache/RedisCache.php` is tested by `tests/Integration/Cache/RedisCacheTest.php`.
- Class name `{ClassName}Test`; method name `test{Action}{Condition}{ExpectedResult}`.
- Mock only ports (interfaces); prefer a fake such as `InMemoryCache implements CacheInterface`.

### 8. Generated entities are nullable

Generated entity properties are typed `?type = null` with nullable getters, independent of the schema's NOT NULL. The entity exists before it is filled (empty construction, hydration by reflection, database-assigned ids, partial loads), so the type itself carries no NOT NULL guarantee. NOT NULL is enforced by the generated validators and the database.

- Handle a nullable getter at every call site, also for NOT NULL columns.
- Treat a value as guaranteed only after hydration and validation.
- Never edit a generated entity to tighten its types — see `generated-code-extend`.

### 9. Reference

- Architecture pillars, hexagonal direction, Closure-Orchestrator concept: `foundation-architecture`
- Pattern catalogue: `foundation-patterns`
- Test rules: `foundation-testing`
- Generated code boundaries: `generated-code-extend`
