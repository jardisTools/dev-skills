---
name: jardis-mcp-consumer
description: Driving a Jardis workspace headless through `jardis mcp` — Tools as actions vs Resources as read-only, the Workspace to Schema to Aggregate to Process to Build to Code-read workflow, the strategic-design surface (glossary, Steckbrief, planned BCs, Context-Map edges with the eight canonical DDD patterns, and the read-only drift check declared-vs-real coupling), the Sorte-A GUI-replacement pattern (OutputDir via update_domain_manifest, code via code-file/code-tree resources, a new workspace means a new process), documented workspace-registry limits, and structured error envelopes (confirm flags, BUILD_RUNNING/DRAFT_EXISTS). Use when an AI must design, build, or inspect a Jardis domain without a browser.
zone: post-active
persona: C
prerequisites: []
next: [platform-implementation]
---

## Scope

This skill is the headless twin of driving the Jardis Designer by hand. There is deliberately no
"Designer-Companion" persona in this bundle (`docs/SKILL-FORMAT.md` §3a) — an AI that drives
`jardis mcp` end to end is doing that companion's job through a different transport. It keeps the `post-active`/`C` label deliberately: the moment `build`
succeeds, this same AI is already the Persona-C implementer that `platform-implementation`
addresses next. The stretch is the transport (MCP calls instead of UI clicks), not the phase.

### 1. The surface: Tools act, Resources read

`jardis mcp` exposes two primitive kinds, both stateless per call (no server-side session):

- **Tools** perform an action (create, save, validate, build) and return a result or a
  structured error envelope (§6).
- **Resources** (plain or templated, e.g. `jardis://tree`, `jardis://schema/{domain}/{bc}`)
  are read-only projections of the current on-disk state — call them again after a write to
  see the effect, there is no push/subscribe.

The full catalogue (on the order of 60 tools plus 50+ resources/templates; the exact numbers
are pinned by the live test `TestToolBudget_FinalCount` and mirrored in `INVENTAR.md`'s
Budget-Tracking section — do not hardcode them from memory, they grow with every strategic-design
increment) is a lived artefact, not something to memorise here — consult it before guessing a
name (see Reference).

### 2. End-to-end workflow

One walk from an empty workspace to readable generated code. Each step is a Tool unless noted:

1. **Workspace** — point the running `jardis mcp` process at a project root (passed at process
   start). If the domain structure does not exist yet, build it up with the structural tools
   (create a domain, add a bounded context, add an aggregate) before importing schema.
2. **`import_schema`** — introspect a database (or a hand-authored `Schema.yaml`, see
   `schema-authoring`) into the bounded context's schema.
3. **`save_aggregate`** — persist the aggregate's designer graph (entities, relations, keys).
   Returns a mtime `CONFLICT` if the on-disk graph moved under you — reload and retry, or pass
   the force flag once you have confirmed the overwrite is intended.
4. **`save_naming`** — apply/confirm the field-mapping naming conventions for the bounded
   context before building.
5. **`create_process`** / **`save_process`** / **`validate_process`** — model a BC-level
   process graph (if the change is process-level behaviour rather than aggregate structure),
   iterate, and check it for structural findings before building.
6. **`build`** — a long-running Tool: generates the aggregate (and/or process) code tree onto
   disk. Reports progress notifications; a concurrent build on the same scope refuses with
   `BUILD_RUNNING`, an unsaved designer draft with `DRAFT_EXISTS` (§6).
7. **Read the generated code** — via the `code-tree` and `code-file` Resource templates
   (chunked reads with an offset/limit window; large files stay well inside the response cap).
   This replaces "open it in the editor" from the browser flow.

Any step can be re-run idempotently against its own Resource first (e.g. read `jardis://tree`
or the aggregate Resource before `save_aggregate`) to confirm the current state before writing.

**Strategic-design tools (optional, additive to the workflow above):** `save_glossary` (a BC's
Ubiquitous-Language glossary), `save_steckbrief` (a BC's canvas — purpose, classification
read-through, collaborators), `create_planned_bc` / `update_planned_bc` / `delete_planned_bc`
(a not-yet-built BC's canvas, at Domain/Subdomain level) and `promote_bc` (turn a planned BC
into a real one). None of these are required to generate code — they capture organisational
metadata (subdomain classification, terminology, context boundaries) a human or AI can set
independently of the Schema→Aggregate→Process→Build chain. Full field shapes: `INVENTAR.md`.

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

**Ist-Abgleich (drift) is a read-only Resource, not a tool:** `jardis://context-map/{domain}/drift`
compares the declared (Soll) edges against the real, synchronous `consumedCalls` the domain's
processes actually make (Ist) — six finding categories (undeclared, unused, direction
contradiction, pattern contradiction, both-ways-vs-directed, and the quiet in-agreement state);
each Ist-Kante additionally carries its call evidence (`Calls` — `processName`/`nodeID`/`facade`/`processFile`),
computed on demand, never persisted. Resolving a finding goes through the ordinary edge CRUD
tools above — there is deliberately no bulk "align everything" tool; each link/delete is a
human- or agent-confirmed single step.

**Rules-Layer — full MCP parity:** a BC's `Rules.yaml`
(the Rule catalog + per-Command bindings that guard writes between the aggregate and its process,
`platform-implementation`) is fully MCP-reachable, same as everything above — no browser-only
capability here. `save_rules` persists the whole catalog+bindings document (LockedSave — a
`CONFLICT` means the on-disk file moved under you, same mtime/force pattern as `save_aggregate`);
`validate_rules` checks a not-yet-saved catalog/bindings set against the V-RULE-* rules
(read-only, no write). Read side: the `rules` Resource template returns the catalog+bindings as-is;
`rules-drift` is a read-only finding set — `policy_without_rule` / `rule_without_policy` /
`empty_chain` — mirroring the Context-Map Ist-Abgleich pattern (computed on demand, never
persisted, no bulk-align tool here either).

### 3. Sorte-A pattern — GUI affordance replaced by a data path

Some browser-UI affordances have no MCP button; they become a plain data operation instead:

- **Choosing an output directory** — the UI opens a native file dialog; an MCP client instead
  calls `update_domain_manifest` with the `outputDir` field directly.
- **"Open in editor"** — the UI opens the generated file in an IDE; an MCP client reads it via
  the `code-file` Resource template instead (chunked, offset/limit).
- **Switching projects** — the UI has a workspace switcher; an MCP client instead starts a
  second `jardis mcp` process pointed at the other workspace root. There is no in-process
  workspace switch.

### 4. Documented limits

The **host-wide workspace registry** (the list of known projects a human uses to jump between
workspaces from the UI's start screen) has no Tool surface — adding, forgetting, or
re-labelling an entry in that registry is not exposed via MCP. This is a deliberate, bounded
gap, not an oversight: an MCP client that wants a different workspace starts a new `jardis mcp`
process against that workspace's root, for which registry membership is irrelevant. Do not
invent a tool call for this — there isn't one.

### 5. Freshness/Drift at startup

Every MCP session start (`New`) carries a live self-check into the server's `initialize`
Instructions: the running `jardis` binary's embedded VCS stamp (commit, commit time) is compared
against the git repository the binary's own executable sits in, checked live — not cached, not
computed once at build time. Two independent, complementary signals: **staleCommit** (the
embedded revision is not the repo's current HEAD — the binary is behind the source it claims to
run) and **dirtyNow** (the repo's working tree has uncommitted changes right now — a binary can
never fully reflect an as-yet-uncommitted edit, no matter when it was built). A clean, current
binary prints one line — `jardis <version> -- Repo-Stand aktuell (Commit <short>, sauberer
Arbeitsbaum).`; drift instead prints a multi-line `WARNUNG` block naming the embedded vs. live
commit and/or the uncommitted-change count. This check never blocks or refuses to start (an
outage would be a heavier intervention than the risk it guards against) — it only reports. A
client should read this banner before trusting a reported finding: a stale binary can silently
still be missing capabilities or fixes (including ones documented in this very skill set) that
only exist in the newer source it has fallen behind.

### 6. Error ergonomics

Every Tool/Resource failure comes back as one structured envelope — `code`, an optional
`ruleId` + `location` for validator findings, a human `message`, and an actionable `hint` —
never a bare error string. Recurring codes worth recognising by name: `CONFLICT` ("already
exists" — safe to retry with a different name or treat as confirmation), `CONFIRM_REQUIRED`
(a destructive call — delete/rename with real impact — needs an explicit confirm flag; inspect
the matching preview Resource first), `BUILD_RUNNING` / `DRAFT_EXISTS` (build-time guards, §2
step 6), `NOT_FOUND`, `VALIDATION` (inspect `details` for field-level findings). Never retry a
`VALIDATION` or `CONFIRM_REQUIRED` failure unchanged — read the envelope, fix the cause or
supply the confirmation, then retry.

### 7. Reference

- Full Tool/Resource catalogue + per-capability test evidence: `INVENTAR.md`
  (`/Users/Rolf/Development/headgent/jardis/tools/builder/docs/mcp-server/INVENTAR.md`).
- Source of the surface itself (read-only, for disambiguating a name you cannot find in
  `INVENTAR.md`): `/Users/Rolf/Development/headgent/jardis/tools/builder/internal/mcpserver/`.
- Once generated code exists and you are implementing behaviour inside it: `platform-implementation`.
- Authoring a `Schema.yaml` by hand instead of introspecting a live database: `schema-authoring`.
