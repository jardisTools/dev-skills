# Progress — <name>

## Kopf
- **Phase:** concept
- **Stage:** —
- **Next step:** Run the concept interview with the human and draft UNDERSTANDING.md and KONZEPT.html
- **Open decisions:** —
- **Title:** <display name>
- **Type:** feature
- **Ticket:** <free text>
- **BC:** <comma-separated>
- **Skipped:** <comma-separated: concept, prd, plan, stage, acceptance>
- **Verdict:** <optional, free: `green E<n> <date>` or `red E<n> <date>`; written after the verifier, deleted at the merge; delete this line when unused>

## Goal

One sentence: what must be true at the end.

## Target picture

Path of the understanding sheet (`UNDERSTANDING.md`) and of the picture (`KONZEPT.html`, `KONZEPT.png` after approval) or of the executable example.

## Guard rails

2 to 5 limits: scope, prohibitions, rulings that hold.

## Stages

- none yet; the plan lists them. A finished stage shrinks to `E<n> done <commit>` (the `Verdict` line goes with it).

## Decisions delegated

- none yet. One line each: date, question, decision, source.

## Acceptance check
<!-- Main session, from the acceptance gate: one line per criterion; no line or no evidence (line 4) = "not checked", not acceptable.
- <n>: met|gap|deferred — <evidence>
- 1: met — QA gates exit 0, log tmp/qa.log
- 2: gap — report export is missing, `src/Export/` has no handler
- 3: deferred — the human put it back, ticket T-12
- 4: met
-->

## Open points
<!-- At the triage, before the acceptance; a bare line without a status is not triaged yet.
- <text> — resolved|backlog|rejected — <due sentence>
- Export without paging — resolved — paged in E2, QA gates exit 0
- Cache for the list query — backlog — due when the list passes 10k rows, else it is slow
- Second report format — rejected — not in the PRD
- Wrong stop at E2 noted during the run
-->

## Knowledge
<!-- Before the acceptance; one line per lesson, `no — nothing to propose` counts as done
- yes|no — <entry>
- yes — Entscheide: report export is paged, topic page `export`
- no — nothing to propose
-->
