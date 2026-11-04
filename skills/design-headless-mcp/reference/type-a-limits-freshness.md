### 3. Type-A pattern — GUI affordance replaced by a data path

Some browser-UI affordances have no MCP button; they become a plain data operation instead:

- **Choosing an output directory** — the UI opens a native file dialog; an MCP client instead
  calls `update_project_settings` with `outputDir`. It is a workspace-level setting (not per
  domain) and, for a jardis-app-template clone, is `<root>/src`. Changing an already-set value
  needs `confirm=true` (nothing is moved; the old tree stays on disk).
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
Instructions, comparing the running binary's embedded revision against the repository it sits
in. A client should read this banner before
trusting a reported finding: a stale binary can silently still be missing capabilities or fixes
(including ones documented in this very skill set) that only exist in the newer source it has
fallen behind.

