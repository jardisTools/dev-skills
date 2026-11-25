#### Continuation §2 — strategic design, subdomain classification, value lists from the rights catalog, BC-declared ENV keys, Context Map and drift check

Any step can be re-run idempotently against its own Resource first (e.g. read `jardis://tree`
or the aggregate Resource before `save_aggregate`) to confirm the current state before writing.

**Strategic-design tools (optional, additive to the workflow of the `workflow-*` step leaves):** `save_glossary` (a BC's
Ubiquitous-Language glossary), `save_steckbrief` (a BC's canvas — purpose, classification
read-through, collaborators), `create_planned_bc` / `update_planned_bc` / `delete_planned_bc`
(a not-yet-built BC's canvas, at Domain/Subdomain level) and `promote_bc` (turn a planned BC
into a real one). None of these are required to generate code — they capture organisational
metadata (subdomain classification — one of `core`, `supporting`, `generic`, `unclassified`, terminology, context boundaries) a human or AI can set
independently of the Schema→Aggregate→Process→Build chain. Full field shapes: the tools' JSON input schemas (`tools/list`).
**Subdomain classification is a closed set.** `create_subdomain` and `update_subdomain_manifest` carry `classification` as an enum in their input schema (`core`, `supporting`, `generic`, `unclassified`); any other value, also a different spelling, is refused and nothing is written. `create_subdomain` without the key (or with `""`) stores `unclassified`. `update_subdomain_manifest` keeps the stored value when the key is omitted and clears it to `unclassified` with `null`; `""` is refused. A stored value outside the set (an old file) still reads as `unclassified`, with a warning in the log.
All save tools answer lean: `save_closures`, `save_queries`, `save_glossary`, `save_steckbrief`,
`save_value_lists` and `create_planned_bc` / `update_planned_bc` return findings/status and the new
`newMtime` — the saved content (terms, Canvas fields, catalog entry, planned-BC fields) comes back
only with `echo: true`; otherwise read it through the matching Resource (`glossary`, `steckbrief` —
also for a planned BC —, `valuelists`, `queries`, `closures`). The HTTP/UI answer stays full.

**Value list from the rights catalog (`origin: "rights"`).** `save_value_lists` takes an entry with `origin: "rights"` for a list of the rights of the domain: it holds no `values` and no `boundTo` (the values are not stored), and it is allowed only in the bounded context with `loginIdentity` (`V-VL-11`). The build fills it with every right of the rights catalog in catalog order, then `{bc}.{resource}:*` per resource, never `*`; an empty catalog is the blocker `V-VL-12`. `preview_value_lists` renders the class with these values (take `origin: "rights"` for a new draft), the resource `valuelists` shows the entry as stored, without values.

**BC-declared ENV keys (`save_bc_env`).** A BC whose body reads its own setting declares the key in `BC.json` `env[]` with `save_bc_env` (`env` = the complete list, full replace, `[]` clears): `key` (UPPER_SNAKE), optional closed `values`, optional `default` (one of `values`), `description`. The value lives in the ONE project `.env`, dynamic block `domain`: write it with `save_runtime_env` (block `domain`, key-addressed: line `-1` + `key`); read the block with `jardis://runtime/env` (a declared key without a value is `present: false`). The body reads `$this->resource()->env('<KEY>')`. Blockers, nothing written: `V-RT-10` key form, declared once, no platform key (runtime catalogue, `DB_READER{n}_*`, `APP_SECRET_KEY`, any `*_FILE`) and no key another BC declares; `V-RT-11` no `values`/`default` entry that dotenv would load as non-string (number, bool word incl. empty, `[...]`, `{...}`), interpolate (`${X}`), expand (leading `~`) or trim — use words like `net`/`gross`; `V-RT-12` the same for the written value, plus membership in a closed `values` set; `V-RT-13` a value is ONE line (no CR/LF/VT/FF/NUL), for every block and tool. An undeclared key of the block stays `V-RT-7`. A blueprint import bringing an already-declared key is reported afterwards (`V-RT-10` on both BCs), not refused at the import.

**Context Map (per Domain, full MCP parity with the UI board):** declared BC-to-BC
relationships are edges carrying one of the eight canonical DDD patterns (Partnership, Shared
Kernel, Customer/Supplier, Conformist, ACL, Open Host Service, Published Language, Separate
Ways) — authored via `create_context_map_edge` / `update_context_map_edge` /
`delete_context_map_edge` and `rewire_context_map_edge`; freely named external systems (the
only deletable node kind — real and planned BC nodes are derived from the workspace, never
created here) via `create_context_map_external_node` / `update_context_map_external_node` /
`delete_context_map_external_node`; node positions via `save_context_map_node_position`
(presentation-only, an MCP client may skip positions entirely). Read side: the
`context-map-patterns` Resource is the closed pattern catalogue (fetch it instead of hardcoding
the eight names), plus the `context-map` and `context-map-declared-targets` Resource templates.

**Drift check is a read-only Resource, not a tool:** `jardis://context-map/{domain}/drift`
compares the declared edges against the real, synchronous `consumedCalls` the domain's
processes actually make (Ist) — six finding categories (undeclared, unused, direction
contradiction, pattern contradiction, both-ways-vs-directed, and the quiet in-agreement state);
each real edge additionally carries its call evidence (`Calls` — `processName`/`nodeID`/`facade`/`processFile`),
computed on demand, never persisted. Resolving a finding goes through the ordinary edge CRUD
tools above — there is deliberately no bulk "align everything" tool; each link/delete is a
human- or agent-confirmed single step.
