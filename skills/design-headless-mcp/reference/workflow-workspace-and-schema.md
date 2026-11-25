### 2. End-to-end workflow

One walk from an empty workspace to readable generated code. Each step is a Tool unless noted:

1. **Workspace** — point the running `jardis mcp` process at a project root (passed at process
   start). If the domain structure does not exist yet, build it up with the structural tools
   (create a domain, add a bounded context, add an aggregate) before importing schema.
   The domain's **class versions** the HTTP layer may serve (`X-Jardis-Version: v2`) are declared
   with `update_domain_manifest` field `versions` (`["v2", …]`, each `^v[1-9][0-9]*$`, whole-list
   overwrite, `[]` clears) and read back through the resource `jardis://domain/{domain}/manifest`;
   a build writes them to `src/Api/{Domain}/versions.php` and into the OpenAPI contract
   (`generated-code-versioning` §3). The build warns `V-VERSION-DRIFT` when a declared version has
   no `v{N}/` class or a `v{N}/` directory is not declared.
   **Blueprint import — a ready-made bounded context instead of `add_bc` plus hand-modelling.**
   Read the static resource `jardis://blueprints` first (readable without a license): a list of
   `{name, description, category, variants[]}`. `import_blueprint {domain, subdomain, bc, blueprint,
   variant}` (all five required; a mutating tool, blocked without a license) writes one variant as a
   NEW bounded context into an existing domain and subdomain: schema, aggregates, queries, closures
   and processes, plus the hand-written bodies, which go into the BC's folder under the configured
   `outputDir`. Every reference to the blueprint's own domain and BC name is rewritten to yours;
   `bc` is PascalCase-normalized. The call is atomic - any failure leaves workspace and `outputDir`
   untouched. Answer: `{bcPath, outputPath, artifacts[], bodies[], rewritten[], mentions[]}`;
   `mentions[]` lists free-text occurrences of a source name that were NOT rewritten (reported,
   never changed silently). Refusals: **404** (domain, subdomain, blueprint or variant unknown) ·
   **412** `DOMAIN_NOT_CONFIGURED` (no `outputDir`: set it with `update_project_settings` first) ·
   **409** `CONFLICT` (the BC name exists in this or another subdomain of the domain, or its output
   folder exists - **here `CONFLICT` does NOT mean "already imported, treat as done"**: nothing was
   written; choose another `bc`, never retry unchanged) · **409** `CONFLICT` with
   `details.collisions[]` `{kind: table|aggregate, name, subdomain, bc}` (a table or aggregate name
   of the blueprint already exists in another BC of the domain; the list names each). Afterwards the
   imported BC is a normal BC with no marker and no protection: change it with the usual tools
   (`save_aggregate`, `save_process`, `save_closures`, ...) and `build` as for any BC. The import is
   a first import only (no re-import) and declares no Context-Map edge.
2. **`import_schema`** — write the bounded context's schema, either from a database
   introspection or from a schema drafted for a domain idea (see `design-draft-schema`), whose
   JSON you pass in the `yaml` argument (wire-key kept for contract stability — it carries JSON
   content). This Tool **is** the authoring door: a `Schema.json` is never placed into the
   workspace as a hand-written file. It normalises `tables` the same way `analyze_schema` and the
   UI do (columns, indexes, foreign keys per table; a column-level `unique` becomes an index)
   and, when `reportMd` is omitted, writes `SchemaReport.md` from that analysis. `analyze_schema`
   answers lean: the enriched `schema` once plus `reportMd`; the hint-free persistable form for this
   `yaml` argument comes back in `yaml` only with `persistable: true` (the enriched `schema` still
   carries frontend hints and is not an import input). `apply_schema_recommendations` answers with
   `schema` only — already hint-free, so its JSON-serialised form is directly the `yaml` argument.
