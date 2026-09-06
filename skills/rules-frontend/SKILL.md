---
name: rules-frontend
description: Non-negotiable, stack-agnostic Jardis frontend review rules — component boundaries, state discipline, an e2e-heavy test pyramid, an accessibility minimum bar, and type-safety at the data boundary; the measuring stick a frontend architect or reviewer holds a UI plan or component against. Consult before reviewing any frontend plan or authoring a frontend component. The concrete UI framework arrives via the assignment, never from this constitution.
zone: crosscut
persona: C
prerequisites: []
next: []
---

## Positioning

This is the **frontend counterpart** to `rules-architecture`: a terse measuring stick, not a tutorial. It states *what* a Jardis frontend must hold true across **every** UI stack (the same five concerns recur whatever the framework). The concrete stack — its idioms, state-update primitives, router, test runner — is **not** here; it arrives via the review assignment. Argue a finding against these rules **plus** the injected stack, never from framework lore. Mirrors the backend stance: behaviour and boundaries are constitutional; the implementation vehicle is replaceable.

## Scope

Applies to any frontend built on or alongside Jardis — the internal Builder UI and customer apps alike. A reviewer measures a **plan** or a **component** against §1–§5; §6 points outward. The five concerns are the whole surface — if a finding fits none of them, question whether it is a frontend-architecture finding at all.

### 1. Component boundaries

- One component = **one responsibility**. "And" in its description → split.
- **Presentational vs. container** kept distinct: view components render props + emit events; data-fetching, navigation, and domain logic live above them, not inside a leaf.
- **Data flows down, events flow up.** No component reaches into a parent's or sibling's internals; no shared mutable global as a back-channel.
- **No business/domain logic in the view layer.** A presentational component must be reusable in isolation, with no hidden dependency on app state.
- Shared UI primitives are **extracted once**, not copy-pasted across screens. "Once" is measurable: a shared mechanic has a **denominator** (N surfaces use it, 0 private copies remain); a new surface consumes or extends the shared piece — it never re-implements it alongside.
- **Every derivation lives in a DOM-free module next to the component**, individually unit-testable; the component renders and emits. Logic inside a component body is the split candidate before size is.
- A component past a sane size/complexity ceiling is a split candidate — review flags it, like an over-long Closure.

### 2. State discipline

- **Single source of truth** per piece of state. Two stores that can disagree about the same fact is a defect.
- **Derived state is computed, never stored.** Caching a value that can be recomputed invites desync.
- **Server state and client/UI state are separated.** Remote data has explicit ownership, caching, and invalidation; it is not smeared into ephemeral view state.
- **Locality:** state lives at the lowest scope that needs it. Lift only when genuinely shared; do not park transient UI state in a global store.
- **Side effects are isolated and declared** — not scattered through render paths or triggered as a hidden consequence of a getter.
- The DOM is **not** a state channel: do not read/write element state to carry application data.
- **URL and query parameters are an external contract.** Reading and narrowing them happens in one place; a refactor may unify the reading, never silently change what a parameter does on a given screen.

### 3. Test pyramid (e2e-heavy)

- Frontend **inverts** the classic pyramid: user-facing behaviour is proven through real rendering — **e2e and component tests dominate**; unit tests are the fallback for pure logic (formatters, reducers, pure transforms) with no DOM or I/O.
- **Test observable behaviour** — what the user sees and does — **never** internal component state, private methods, or implementation detail. A refactor that preserves behaviour must not break tests.
- **Critical user flows have e2e coverage.** Interactive widgets have component tests. A flow with no e2e is an untested flow.
- Queries target the **accessible surface** (role, label, visible text) over brittle CSS/structural selectors — a test that breaks on restyling tested the wrong thing.
- **Refactoring needs a net first.** Before behaviour-preserving restructuring, pin the current behaviour with characterisation tests and rendered-output snapshots taken **on the pre-change code** — never reconstructed from the new code — and diff against them afterwards.
- **Failing-test discipline** (per `rules-testing` §3): establish what *should* happen first; fix the bug or fix the test with reason — **never** weaken or delete an assertion to go green.

### 4. A11y minimum bar

- **Semantic elements** for semantic meaning: real `button`/`link`/`nav`/heading structure, not styled `div`s carrying click handlers.
- **Keyboard-operable end to end:** every interactive control is reachable and operable by keyboard, with a **visible focus** indicator and a logical focus order. No keyboard traps.
- **Accessible names** for every control: labelled form fields, named icon-only buttons, meaningful `alt` text; decorative imagery marked as such.
- **Contrast meets WCAG AA**; meaning is **never carried by colour alone** (pair with text/icon/shape).
- **Async state is announced** (live regions / status messaging); error messages are programmatically associated with their field.
- Honour user preferences (e.g. reduced motion). A11y is a **gate**, not a nice-to-have — a plan silent on it is incomplete.

### 5. Type safety

- **Strict typing on.** No implicit `any`/untyped escape hatches, especially at boundaries.
- **The data boundary is validated, not assumed.** External/untrusted payloads (API responses, URL/query params, user input) are parsed and narrowed at the edge — inner code trusts only typed, validated shapes.
- **No casts/assertions to silence the type-checker.** Narrow with real checks instead of asserting the compiler into submission.
- **Contract types are single-sourced.** FE and backend share one definition of a payload shape; divergent hand-maintained copies drift and are a defect.
- **Every async/data state is handled exhaustively** — loading, error, empty, success — and null/undefined is handled explicitly, not by optimistic access.

### 6. Reference

- Backend constitution & shared spirit (SoC, SRP, explicit dependencies): `rules-architecture`.
- Failing-test process and behaviour-over-implementation testing: `rules-testing`.
- Pattern catalogue (Strategy, Adapter, Decorator, …) when a UI problem genuinely calls for one: `rules-patterns`.
- Stack-specific idioms (state-update primitives, router, component model, test runner) are **out of scope here** — they enter through the review assignment, never this file.
