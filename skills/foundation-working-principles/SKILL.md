---
name: foundation-working-principles
description: Working principles for every task in a Jardis project — skill first, then source code, then ask; verify values instead of guessing; check design choices against the five pillars. Consult when starting work, hitting uncertainty or a runtime error.
zone: crosscut
persona: C
profile: core
prerequisites: []
next: [foundation-architecture]
---

## Scope

Three rules that apply to every task, before any code is written and while it is debugged. They govern how facts are established; the content rules live in the sibling skills.

### 1. Skill first, then source code, then ask

Unknown API, unclear behaviour or an open choice: resolve it in this order.

1. Consult the matching skill. Before using a Jardis package API, load that package's skill — the APIs are documented there.
2. Read the source code of the package or file involved.
3. Ask the user.

Never guess an API, a signature or a behaviour, and never fill a gap with an assumption. An answer recalled from memory is an assumption until a skill or the source confirms it.

### 2. Verify values instead of guessing

A runtime error or an unexpected result is a question about a value, not about a theory.

- Print the actual values (`var_export`, a log line, a debugger) at each link of the chain.
- Check every link on its own: input, each intermediate result, output. Read the packages involved.
- Act only once the evidence shows where the value goes wrong. A fix that rests on a hunch is a second guess.

### 3. Check design choices against the five pillars

Modelling and design decisions are not free: where data lives versus behaviour, which pattern, which interface, how a type is shaped. Test each against the five pillars in `foundation-architecture` before any code exists, not only when the code is generated or reviewed.

- The first idiom that comes to mind is an assumption until it passes that check.
- A pure data container carries no behaviour or marker interface (data-behavior separation).
- Prefer composition over inheritance; state dependencies explicitly.
- When the strict reading produces clearly higher complexity, a conscious, stated compromise is allowed — never a silent one.

### 4. Reference

- Pillars, hexagonal direction, Closure-Orchestrator: `foundation-architecture`
- Pattern catalogue: `foundation-patterns`
- PHP conventions: `foundation-php`
