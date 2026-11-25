#### 2.8 Strategic layer

| Element | Door (MCP / UI) | What it gives |
|---|---|---|
| Domain, subdomain, BC manifests | `create_domain`, `update_domain_manifest`, `create_subdomain`, `update_subdomain_manifest`, `add_bc`, `rename_*`, `delete_*` / domain map, master data | names (PascalCase, BC names unique per domain), classification of a subdomain `core`/`supporting`/`generic`/`unclassified` (a closed set: another value is refused, an update without the key keeps it, `null` clears it), owner, output directory (`update_project_settings`), client block |
| Planned BC | `create/update/delete_planned_bc`, `promote_bc` / BC page | a BC drafted before it exists (BC canvas ("Steckbrief"), glossary terms); promote turns it into a real BC |
| Steckbrief | `save_steckbrief` / BC page card | description, business model, Wardley evolution, business policies (ids referenced by closures), assumptions, owner |
| Glossary | `save_glossary` / BC page card | ubiquitous language: term, description, code identifier |
| Naming | `save_naming` | §2.7 |
| Context map | `create/update/delete/rewire_context_map_edge`, `create/update/delete_context_map_external_node`, `save_context_map_*`, resource `context-map/{d}/drift` / domain map | edges with the 8 patterns (partnership, shared kernel, customer-supplier, conformist, anticorruption layer, open host service, published language, separate ways); direction required for directed patterns; drift check declared vs. real coupling (5 alarm kinds); external systems with service names |

#### 2.9 Tooling surfaces and hand-code surfaces

| Surface | Door | Use |
|---|---|---|
| Project and stack | `provision_project`, `set_stack_selection`, `save_stack_settings`, `save_runtime_env`, `save_bc_env`, `repair_stack_drift`, `run_stack_command`, `update_project_settings`, `configure_logging`, connections (`create/update/delete_connection`, `introspect_db`), sources (`create/rename/delete_source`) | provision from the app template and run the stack by tool, not by hand-made compose files or env edits. A BC that reads its own ENV key declares it with `save_bc_env` (`BC.json` `env[]`: key, optional closed `values`, `default`, `description`), the value goes into the one project `.env` through `save_runtime_env` block `domain`, the body reads `$this->resource()->env('<KEY>')`; never cast-shaped values (numbers, bool words, `[...]`, `${…}`), one line per value, no platform key (V-RT-10..13) |
| Build and checks | `validate_definitions_all`, `validate_*`, `build`, `build_domain`, `build_process`, `run_qa`, `run_make_target`, `stop_make_target`; resources `build-result`, `findings/{domain}`, `project/make-targets` | validate before building; count warnings and suggestions after; goal 0 |
| Reading | resources `catalog/{d}/{s}/{bc}`, `api/{domain}/routes`, `api/{domain}/openapi`, `code-tree`, `code-file`, `code-graph`, `interface-board`, `doors/{domain}` | contract and routes for tests and frontend; `catalog` lists every command name including child commands |
| Studios | resource `studios/{name}`, tool `post_studio_card` | the AI posts a decision card; answering is the human's handle |
| Human-only handles (no MCP door) | licence actions, studio create/remove/pause/answer, project create/restart, new domain, chat | ask the human |
| Hand-code places (the corridor) | node bodies `{BC}/Process/{Name}/Command/Handler/Action/`, closure bodies `{BC}/Closure/`, Domain Services `{BC}/Service/`; `App/**` only for delivery, without domain logic | the only places for written code; a body uses Jardis artefacts only (kernel seam, packages, queries, commands, value lists). The table of places and allowed dependencies is `generated-code-extend` §10; never inside `{BC}/Model/{Agg}/` (rebuilt on every build). Reusable building blocks: `packages-find-existing` |

