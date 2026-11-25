#### Continuation §2 — studio build control

**Studio build control.** When a studio (Vorhaben) of the board drives the build,
the AI reports to the human through cards, not chat:

- **Cards you post** (`post_studio_card` with `studio`): kinds `question`, `acceptance`,
  `release`, `addendum`, `sight` (a surface to look at), `red` (a check stays red after the one
  fix run), `merge` (a stage is green and committed) and `delivery`. A card needs a
  recommendation `{lead, reason}` and two to three answers `{id, label}` with `recommended`;
  without them the call is refused and the question goes as ordinary chat. Keep the `STOPP:`
  line of the progress file as the trace and remove it once the answer is recorded. At most one
  card is open per studio, more queue behind it. Without `studio` only the kinds `start`
  and `notice` exist (they belong to the board); `conflict` is Jardis's own and refused.
- **Fixed answer ids** (Jardis sets answers and labels; answers you pass are discarded, you only
  give `recommended` as one of the fixed ids): acceptance card in the room Acceptance
  `approve` / `change`; `delivery` `pullRequest` / `push`; `red` `tryAgain` / `defer` /
  `discard`. The acceptance card is refused until every criterion is met or deferred, every open
  point is triaged and the knowledge lines are complete.
- **`run_qa` preconditions:** Docker available, the target present in the project's `make help`,
  and for `make integration-test` the stack running. A missing one is a 409 with
  `details.code` `dockerMissing`, `stackDown` or `targetMissing`; while a studio builds,
  Jardis then puts the card "QA nicht ausführbar" in front of the human. `run_qa` is
  long-running and cancellable; read the full output through its `logResourceUri`.
- **`run_make_target`:** the live allow-list is `jardis://project/make-targets`; kinds `plain`,
  `copyOnly` (refused, copy the command), `stoppable` (until `stop_make_target`), `param`
  (`params` with exactly the declared names), `confirm` (needs `confirm=true`); a target's
  `stackRequirement` can refuse with `STACK_STATE_MISMATCH`; one target per project root at a
  time (`CONFLICT` otherwise).

