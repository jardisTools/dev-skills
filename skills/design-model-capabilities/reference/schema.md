#### 2.1 Schema (column and index settings)

Door: MCP `import_schema` (whole document; refine with `analyze_schema`, `apply_schema_recommendations`, `introspect_db`, `export_schema_sql(_files)`); UI schema import on the BC page. Single columns are not editable one by one — change the document and re-import.

| Setting | Allowed values | Effect in the generated code |
|---|---|---|
| `type` | closed vocabulary (numeric, string, bool, date/time/datetime/timestamp, json, binary, uuid, enum, …); `date`, `time`, `datetime`, `timestamp` are four distinct types | DTO and entity type; `uuid` → `Uuid` validator; `json` → `Json`; `date` → `DateTime` format `Y-m-d`, `time` → `H:i:s`; `decimal` → string field with numeric `Format` check |
| `nullable: false` | bool | create input required: `NotEmpty` (strict) for strings, `NotBlank` otherwise |
| `length` (varchar) | int | `LengthMax` validator |
| `unsigned` / `precision` (int) | bool / int | `unsigned` ⇒ lower bound 0 (`Positive::allowZero()`, 0 is valid); `precision` p ⇒ ±(10^p−1), unsigned 0…10^p−1 (`Range::between`) — the same bound at the entity validator, the command validator and the OpenAPI request `minimum`/`maximum` |
| `unique` (column) or unique index, single column | bool / index `type: unique` | public business key (identifier stage `uniqueColumn`): outer-door key, lookups `get{Agg}By{Key}` and `By{Key}s`, bulk read, HTTP 409 `duplicate` on a second create. Unique on a nullable column is rejected (S17). A key unique per tenant needs ONE unique index over exactly the scope columns + the key column, all NOT NULL (`identifier.scope`, §2.2) |
| `auto: created` / `auto: modified` | datetime/timestamp only, once per table, never PK/unique/FK | stamped server-side in UTC, absent from every command DTO |
| `enum` + `enumValues` | list | seeds a value list (§2.3); closed `enum` in the API contract |
| `default` | value | lands as `DefaultValue` on the entity property and snapshot; a column with a default counts as database-generated, so a NULL value is omitted from the `isNew` INSERT and the database default applies; no validator is derived; for a bound enum column it must be one of the list values (V-VL-10) |
| Column-name convention | `email`/`*_email` → `Email`; `url`/`*_url`/`website` → `Url`; `phone`/`phone_*`/`*_phone` → `Phone`; `uuid`/`*_uuid` → `Uuid`; `iban`/`*_iban` → `Iban` | validator on entity and command DTO, no code; 400 `invalid` |
| Primary key | exactly one column per table (composite PK rejected) | root PK is the internal technical handle; with no public unique key it appears as `id` |
| Foreign keys / indexes | `foreignKeys[]`, `indexes[]`; FK `onDelete`/`onUpdate` | DDL only; an FK without an index is a suggestion (S8); `{x}_id` without FK is suspected (S10) |
| Validation override | `add_schema_validation_override` | silences one warning with a reason; blockers are never overridable. Goal is zero overrides |

