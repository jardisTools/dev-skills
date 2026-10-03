---
name: process-check-existing
description: Use before proposing anything new — a new type, class, second track or mechanism, a claim resting on "does not exist", a requirement phrased as a rule — to learn what the environment already does, via a blind checker.
zone: process
persona: O
profile: core
prerequisites: [foundation-working-principles]
next: []
---

## Scope

A proposal that ignores what already exists is a guess with a blueprint. Before the proposal is written, ask what the target environment does today for the requirement: not what is missing, but what is there. One blind round costs one sub-agent; a wrong proposal costs a whole session and trust.

### 1. When the check is due

It is not always due, but it is due when the form of your own proposal shows one of three triggers:

1. The proposal puts something new next to the old: a type, a class, a second track, a mechanism.
2. The reasoning rests on an absence: "does not exist", "no hits".
3. The requirement is phrased as a rule ("outwards, X holds"): the first question is where X already holds today.

If none of the three applies, skip the check.

### 2. The checker is blind

Start the checker from `../process-review-board/reviewers/existing-capability-check.md`, with a fresh context. Give it exactly two inputs:

- the requirement in its original wording, unchanged;
- the target area: paths, packages or the part of the system to look in.

Never hand it your proposal, your design or your hunch. If the proposal reaches the checker anyway, it ignores it and reports that. Where the tool cannot start a separate agent, work through the same source yourself in a fresh context, before you write the proposal down.

### 3. The return

The checker returns one of three verdicts, then the evidence:

| Verdict | Meaning | Next step |
|---|---|---|
| `MET` | The environment already does what the requirement asks | Do not build. Show the finding to the human and use what exists. |
| `PARTIAL` | Something exists, and something breaks | The proposal is scoped to the gap the checker named. Partial cover is the most common and most valuable result. |
| `NOT FOUND` | Nothing found, after the synonyms of the target term were searched | Proposal may go ahead; state which terms were searched. |

### 4. Weighing the result

- An absence counts only after the synonyms of the target term were searched: a search for `field` misses a rule that says `data columns`.
- For generators, renderers and emitters the generating code proves no capability, only the generated output does, and only from two instances that can differ in the property in question.
- Comments, requirement text and commit messages prove intent. Code, output and a running test prove behaviour. If they diverge, behaviour holds, and the divergence is itself a finding.
- Before any judgement on location, structure or responsibility: read the responsible skill first, then the source, then the artefacts.

### 5. Reference

- Checker source: `../process-review-board/reviewers/existing-capability-check.md`
- Choosing how much process a task needs: `process-choose-tier`
