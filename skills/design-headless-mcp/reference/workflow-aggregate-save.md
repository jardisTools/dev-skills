#### Continuation §2 — step 3: `save_aggregate`

3. **`save_aggregate`** — persist the aggregate's designer graph (entities, relations, keys).
   `graph.adopts` (ADOPT columns per node): omit or `null` = the stored adopts of every node stay, `[]` = delete them all, a sent list REPLACES the stored adopts of ALL nodes (a node missing from the list loses its adopts). The result carries the blockers of the aggregate as stored: `valid` (false = at least one blocking finding), `errors`, `warnings`, `suggestions` — the aggregate is written even with blockers, so the caller must check `valid`; a parked draft (`draft=true`) carries no `valid`/`errors`, and `notValidated` (with the reason) replaces them when the stored artefacts cannot be loaded. `set_aggregate_tables` answers with `valid`/`errors` (or `notValidated`) the same way, written either way; `write_definitions` answers with `valid`/`errors`/`warnings` or `notValidated`, an aggregate with a blocker is still written (a schema with a blocker is refused 422 and nothing is written).
   Returns a mtime `CONFLICT` if the on-disk graph moved under you — reload and retry, or pass
   the force flag once you have confirmed the overwrite is intended. A node's optional `identifier {stage, column}` declares its public Identifier (omit = keep the stored declaration, `{}` = delete it after the merge, invalid = 422 `VALIDATION_FAILED` before anything is written). Node positions and the
   viewport are optional: when absent, the value in `Layout.json` is kept. A node's optional
   `path` (as returned by the aggregate load) is accepted, so a table placed at more than one
   position survives load then save. `suggest_aggregate_edges` (read-only) proposes edges, node
   positions and `rootCandidates` for the chosen tables; a root recognisable only through declared
   FKs (no name-prefix hit, e.g. an irregular plural) is still returned, with reason `declared_fk`,
   when it is the unique FK sink of the tables (chain A←B←C yields A) — two sinks or no FK signal
   leave `rootCandidates` empty, so decide the root yourself then. A node's optional `computed`
   (`["net_total", …]`, omit = keep the stored marker, `[]` = delete it) marks the columns the
   **process** derives (sums, counters): they leave every Create/Set/Add/Update command and the
   OpenAPI request (a body naming one is `400 unknown`), Read/list/Akte keep them, and the process
   writes them through the family-internal `Set{Entity}Computed` command. A computed column must be
   nullable or carry a DB default (`V-CALC-1`) and cannot be the PK, the public identifier, an FK or
   an `adopt` column (`V-CALC-4`); the aggregate load echoes `computedBlocked` (column → reason) for
   the columns that may not carry the marker — send it back or omit it.
   **Format markers on a node** (neutral example: table `orders`, key `order_no` unique per region):
   ```json
   {"id": "n1", "table": "orders", "entityKey": "order",
    "identifier": {"stage": "uniqueColumn", "column": "order_no"},
    "tenant": "region_code", "user": {"created_by": "onCreate"},
    "writeOnly": ["api_token_hash"]}
   ```
   (An aggregate without any outer door has every read row of its block `closed` in `Doors.json`: write it with `save_doors` (gesture `closeReads`); `graph.visibility` is retired, see below.)
