### 1. The surface: Tools act, Resources read

`jardis mcp` exposes two primitive kinds, both stateless per call (no server-side session):

- **Tools** perform an action (create, save, validate, build) and return a result or a
  structured error envelope (§6).
- **Resources** (plain or templated, e.g. `jardis://tree`, `jardis://schema/{domain}/{bc}`)
  are read-only projections of the current on-disk state — call them again after a write to
  see the effect, there is no push/subscribe.

Build-control surface of the board (studios = the project's undertakings under
`docs/vorhaben`), see §2 "Studio build control":

- `run_qa` (Tool) — runs one allow-listed QA command (`make qa`, `make phpunit`, `make phpstan`,
  `make phpcs`, `make integration-test`) in the project root; returns exit code, a bounded tail
  and a `logResourceUri` for the full output.
- `run_make_target` (Tool) — runs any target the project Makefile currently offers; read the
  `project-make-targets` Resource (`jardis://project/make-targets`) first for the live list and
  each target's kind.
- `post_studio_card` (Tool) — puts ONE decision card (question, recommendation, answers) into
  the current room of a studio; answering it is the human's gate, no tool.
- `jardis://studios/{name}` (Resource template) — one studio: its record, rooms, documents
  and the cards with their state; `jardis://studios` lists all open studios.
- `jardis://closed-studios` (Resource) — the closed and discarded studios, newest first;
  read-only.

The full catalogue (on the order of 80 tools plus 70+ resources/templates — do not hardcode them
from memory, they grow with every strategic-design increment) is a lived artefact, not something to memorise here — consult it before
guessing a name (see Reference).

