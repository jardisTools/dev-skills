#### Continuation §2 — confirm gates and Schema to SQL export

**Other confirm gates:** `rename_value_list` (like `rename_closure`) needs `confirm=true` for a real
rename, while `dryRun=true` runs without `confirm`. `set_stack_selection` needs `confirm=true` when
the selection switches the project's database (read the current one from the `runtime-stack`
resource); every other change, and re-sending the same database, needs none.

**Schema→SQL export:** `export_schema_sql` returns one dialect's DDL as
text (read-only preview, four dialects available — the same `appsvc.SchemaExportService.ExportSQL`
the UI's preview-sql route calls); `export_schema_sql_files` writes all four `Schema.{dialect}.sql`
files into the BC directory (mutating). Neither tool exists to author a `Schema.json` from —
that direction is `design-draft-schema` / `import_schema`; these are the reverse, DB-migration-facing
export.

