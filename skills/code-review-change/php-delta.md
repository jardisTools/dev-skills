# code-review-change — PHP delta

The PHP realisation of the review in `SKILL.md`. It adds to the core catalogue and never contradicts it. Read it first when a changed file is `.php` or `composer.json`. It holds two kinds of content: the checks that exist only in PHP, and the concrete PHP form of core checks whose concept is language-neutral.

## 1. Checks that exist only in PHP

| Check | Severity |
|---|---|
| `declare(strict_types=1)` in every PHP file | Blocker |
| Constructor promotion where possible | Minor |

Another language enforces typing in the compiler, without a pragma, and has no construct like constructor promotion; that is why these two checks live here and not in the core.

## 2. The PHP form of core checks

The checks themselves are in `SKILL.md`; this is the form in which they are verified on PHP code.

- **Core section 2, nil and zero-value semantics:** `?->`, `?? []`, nullable returns stated explicitly (`@return T|null` or the union type `T|null`). One documented pattern is not a finding: properties of Designer-generated entities are nullable on purpose across the hydration lifecycle, and NOT NULL is enforced by validators.
- **Core section 2, immutable data containers:** `readonly` properties on DTOs, value objects and commands.
- **Core section 2, no untyped type:** no `mixed` and no untyped parameter or return value where a concrete type is possible.
- **Core section 3, parameterised access:** `DbQuery` with `prepared: true`, no string concatenation in SQL strings. The check holds for every value that can be tainted, including fields read from the database and configuration (second-order injection).
- **Core section 4, all five checks:** `catch` blocks are specific (never a blanket `\Exception` or `\RuntimeException`); a `catch` is never empty, at least log or rethrow; the exception type is semantically correct (not a decryption exception for an encryption failure); resources (connections, handles) are closed in `finally` or in `tearDown`.
- **Core section 7, dead code:** unused code and commented-out blocks. No PHP tooling of the project enforces this automatically, so it stays a fully manual criterion.

## 3. Tool boundary

Static analysis and coding standards run as gates of the project, not as part of this review. The review does not repeat what a gate reports; it covers what no gate can see: meaning, naming, honesty of return values, security reasoning, resource handling on the error path. A green gate is no reason to drop a check above.
