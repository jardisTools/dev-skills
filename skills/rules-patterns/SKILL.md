---
name: rules-patterns
description: Reference catalogue of the ten design patterns used in Jardis (Facade, Strategy, Adapter, Value Object, Factory, Repository, Decorator, Priority-Based Layers, Chain of Responsibility, Lazy Initialization). Consult before introducing a pattern.
zone: crosscut
prerequisites: []
next: []
---

## Scope

Applies to Jardis packages (`JardisAdapter/*`, `JardisSupport/*`, `JardisTools/*`). Generated Domain code already applies most patterns for you (Facade on `<Agg>.php`, Repository for persistence, Factory/Strategy inside `handle()`). Phase-3 extensions most often use **Value Object**, **Decorator** (via `v2` overrides), **Adapter** (via Domain Services). The gate in §3 applies to all.

### 1. Pattern catalogue

| Pattern | Use for | Core rule |
|---|---|---|
| **Facade** | Simplifying complex subsystems | Thin, delegates, no business logic |
| **Strategy** | Interchangeable implementations | Clear interface, stateless, Constructor Injection |
| **Priority-Based Layers** | Fallback / broadcast | Lower number = consulted first |
| **Chain of Responsibility** | Sequential pipeline | Handler decides: pass on or terminate |
| **Lazy Initialization** | Expensive resources | `$this->connection ??= $this->create()` |
| **Adapter** | Wrapping third-party | Own interface, Adapter implements |
| **Value Object** | Immutable data by value | Readonly, equality by value, no ID |
| **Factory** | Complex object creation | `match($type)` dispatch, never `new` in business logic |
| **Repository** | Data access abstraction | Domain defines interface, Infra implements |
| **Decorator** | Dynamically adding behaviour | Same signature, wraps inner |

No problem, no pattern.

### 2. Per-pattern specifics

- **Facade** — root-namespace Orchestrators are the typical shape.
- **Strategy** — stateless, Constructor-Injected. One implementation = no Strategy yet.
- **Priority-Based Layers** — cache layers (L1 Memory, L2 Redis, L3 DB). Lower number = first.
- **Chain of Responsibility** — link **may** stop the chain; that is the difference from Decorator.
- **Lazy Initialization** — forbidden inside per-call Context subclasses (state must be transient).

```php
private ?PDO $connection = null;
private function connection(): PDO { return $this->connection ??= $this->createConnection(); }
```

- **Adapter** — `JardisAdapter\*` implements a Contract from `JardisSupport\Contract\*`.
- **Value Object** — `readonly` properties, self-validation in constructor (throw on invalid), `equals()` by value, no dependencies. Named constructors (`Email::from(...)`) are the only legitimate `static`.
- **Factory** — `match($type)` over `if/elseif`.

```php
return match ($type) {
    'smtp'  => new SmtpTransport($config),
    'null'  => new NullTransport(),
    default => throw new InvalidArgumentException("Unknown transport: {$type}"),
};
```

- **Repository** — Domain defines the interface, Infra implements. No SQL in domain. Methods return domain objects, never PDO statements.
- **Decorator** — same interface, wraps inner, **always calls** the inner (that is the difference from CoR).

### 3. Pattern gate — all four must be yes

1. Concrete problem solved?
2. ≥2 real or planned variants today?
3. Orchestrator stays logic-free?
4. Testable via Constructor Injection?

### 4. Anti-pattern alarms

- Pattern for its own sake → remove.
- Single implementation behind Strategy/Factory → overkill until variant #2.
- Decorator chain of 5+ layers → cut a second Orchestrator.
- Repository assertions on SQL strings → testing implementation, not behaviour.
- Factory without `match` → `match` is mandatory for type dispatch.
