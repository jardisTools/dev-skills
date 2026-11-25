#### 2.3 Value lists

Door: MCP `save_value_lists`, `validate_value_lists`, `preview_value_lists`, `adopt_value_list_values`, `rename/delete/duplicate_value_list`; UI value-list editor (create modal, Werte tab). One entry per `enum` column, seeded by the schema import (`origin: schema`).

| Setting | Allowed values | Effect |
|---|---|---|
| `name` | PascalCase, unique case-insensitively | PHP enum `{BC}/Data/{Name}.php` |
| `values[]` | non-empty, no duplicates, no two values with the same case identifier | enum cases; order is the list order |
| `boundTo {table, column}` | an existing `enum` column, at most one list per column | `ContainOneOf` validator at entity and command (400 `invalid` on the field path, also for child fields); closed `enum` in the OpenAPI request and response |
| values vs. schema `enumValues` | should match in set and order | otherwise warning V-VL-8; `adopt_value_list_values` takes over the schema values |

A list can be a Closure input of kind `valuelist` (own BC only).

#### 2.7 Field map

Door: MCP `save_naming`, resource `naming/{d}/{s}/{bc}`; UI mapping tab. Sparse overrides `table → column → businessName` (valid PHP identifier, camelCase, unique per table); everything else is derived. The only way to rename a property; plural exceptions are not declarable.

