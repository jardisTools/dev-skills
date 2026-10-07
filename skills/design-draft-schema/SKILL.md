---
name: design-draft-schema
description: Design the CONTENT of a Schema.json for the Jardis Designer — from a plain-text domain idea, draft tables (snake_case plural), columns with realistic types, primary keys, indexes (primary/unique/index), optional foreign keys. The finished draft enters the workspace through an authoring door — MCP `import_schema` or the schema import in `jardis ui` — never as a file placed into the workspace by hand. Output matches the DB-export format the Designer's importer parses.
zone: pre
persona: A
profile: jardis
prerequisites: []
next: [generated-code-extend]
---

## Driving rules

1. Ask for the domain idea (one paragraph). If vague: **one** sharp clarifying question, not an interrogation.
2. Tables = plural snake_case. Implicit collections → own tables.
3. Every table: PK (`int` autoincrement default; UUID only if domain dictates). Business identifier → `unique` index. Lookup/filter column → non-unique `index`.
4. FKs **optional** — only include when the relation is unambiguous. Otherwise `foreignKeys: {}` and let the Designer model relations interactively.
5. Output = one complete `Schema.json` block + explicit assumption list. This block is a **draft handed to an authoring door**, not a file you write into the workspace yourself — Jardis definition files are maintained exclusively by the app.

Handing the draft over — two doors, same result:

- **MCP** (`jardis mcp`): call `import_schema` with the drafted JSON in its `yaml` argument (wire-key kept for contract stability — it carries JSON content), plus `domain`/`subdomain`/`bc`. It writes `{domain}/{subdomain}/{bc}/Schema.json` for you. The import runs the same normalisation as `analyze_schema` and the UI (columns, indexes, foreign keys per table; a column-level `unique` becomes an index) and writes the analysis report, so you need not pre-normalise the draft.
- **Browser UI** (`jardis ui`): upload the draft as a `.json` schema source in the bounded context's schema-import surface, then pick the tables the BC governs.

Never place the block into the workspace as a hand-written file — that is not a supported input path.

### 1. Schema.json structure

The table name is the map key (no separate `name` field). `foreignKeys` is an empty map/array, or a list of entries with `column`/`referencedTable`/`referencedColumn` (see the populated form below).

```json
{
  "tables": {
    "<table_name_snake_case>": {
      "columns": [
        {
          "name": "<column_name>",
          "type": "<SQL type, see catalogue>",
          "length": "<optional integer, for varchar/char>",
          "enumValues": ["<only for type enum>"],
          "nullable": "<true | false>",
          "primary": "<true | false>",
          "autoincrement": "<true | false>",
          "auto": "<optional: created | modified — only on datetime/timestamp>"
        }
      ],
      "indexes": [
        {
          "name": "<index_name | PRIMARY>",
          "columns": ["<col>", "<col>", "…"],
          "type": "<primary | unique | index>"
        }
      ],
      "foreignKeys": [
        {
          "column": "<local_column>",
          "referencedTable": "<other_table>",
          "referencedColumn": "<other_column>"
        }
      ]
    }
  }
}
```

**Column required:** `name`, `type`, `nullable`. `length` required for `varchar`. `enumValues` (non-empty, no duplicates) for `type: enum`. `primary`/`autoincrement` only on PK. **Never write `phpType`** — the importer rejects a schema carrying it (hard load error, rule CUT3); the PHP type is derived from `type`.
**`auto` marker (rule S23, Blocker):** optional, value `created` or `modified`, only on a `datetime`/`timestamp` column, each value at most once per table, never on a primary key, a unique column or an FK. The generated persist then stamps the column server-side in UTC (`created` on insert; `modified` on insert and on every change, also a child change of the root) — the column is name-independent and absent from the Command DTO. Mark the creation and last-change columns instead of relying on a database default; a datetime column named `updated_at` without the marker draws a suggestion.
**Index required:** `name`, `columns`, `type`. PK index = `PRIMARY`.
**FKs empty:** use `{}` (matches real DB exports) or `[]`.

### 2. Types

The `type` vocabulary is closed (`column_type_vocabulary.go`); a token outside it is a warning (S22) and would render as PHP `mixed`:

| Family | Allowed `type` tokens | Notes |
|---|---|---|
| integer / numeric | `int`, `integer`, `tinyint`, `smallint`, `mediumint`, `bigint`, `decimal`, `numeric`, `float`, `double`, `real` | `autoincrement: true` on PKs; `bigint` for row count > 2^31; `float`/`double`/`real` imprecise, avoid for currency; `decimal`/`numeric` take `precision: <int>` (total digits) + `scale: <int>`, **not** `length` |
| string | `varchar`, `char`, `text`, `tinytext`, `mediumtext`, `longtext` | `varchar` needs `length` (common 36/50/100/255); `text` family no `length` |
| bool | `bool`, `boolean` | flags |
| date / time | `date`, `time`, `datetime`, `timestamp` | `date` = calendar date without time/TZ; `time` = time of day without date/TZ; `datetime` = point in time without TZ binding; `timestamp` = TZ-bound instant |
| json | `json` | semi-structured payloads, use sparingly |
| binary | `blob`, `binary`, `varbinary` | |
| other | `uuid`, `enum` | `enum` requires non-empty `enumValues`; a ValueList bound to a column requires that column to be `type: enum` (V-VL-6, Blocker) |

### 3. Modelling heuristics

- **Identifier columns:** every business object typically has an internal `int` PK (`id`) **and** a public business identifier (`identifier`, often `varchar(36)` UUID7). Unique index on the business identifier.
- **Active period:** "active period" in the idea → `activeFrom` (`date` or `datetime`, per whether a time of day matters; not nullable) + `activeUntil` (same type, nullable = open period).
- **Lookup tables:** categories/types/statuses get their own table even when described as enums.
- **Single PK only:** exactly **one** PK column per table. A composite (multi-column) PK is a hard build error (S7). Junction/N:M tables therefore get a surrogate PK + two FKs, never a composite PK.
- **Junctions:** many-to-many → explicit junction table with surrogate PK + two FK columns.
- **No relations in Schema.json unless obvious.** Relations are modelled later in the Designer — leave them out here.

### 4. Example

Idea: *"Track meter readings. A counter has a number and an active period, lives at a meter location, can link to multiple registers via a gateway."*

```json
{
  "tables": {
    "counters": {
      "columns": [
        { "name": "id", "type": "int", "nullable": false, "primary": true, "autoincrement": true },
        { "name": "identifier", "type": "varchar", "length": 36, "nullable": false },
        { "name": "meterLocationIdentifier", "type": "varchar", "length": 50, "nullable": false },
        { "name": "counterNumber", "type": "varchar", "length": 50, "nullable": false },
        { "name": "activeFrom", "type": "date", "nullable": false },
        { "name": "activeUntil", "type": "date", "nullable": true }
      ],
      "indexes": [
        { "name": "PRIMARY", "columns": ["id"], "type": "primary" },
        { "name": "identifier", "columns": ["identifier"], "type": "unique" },
        { "name": "meterLocationIdentifier", "columns": ["meterLocationIdentifier"], "type": "index" }
      ],
      "foreignKeys": {}
    },
    "registers": {
      "columns": [
        { "name": "id", "type": "int", "nullable": false, "primary": true, "autoincrement": true },
        { "name": "identifier", "type": "varchar", "length": 36, "nullable": false }
      ],
      "indexes": [
        { "name": "PRIMARY", "columns": ["id"], "type": "primary" },
        { "name": "identifier", "columns": ["identifier"], "type": "unique" }
      ],
      "foreignKeys": {}
    }
  }
}
```

Assumptions to state:

- Business identifier = `identifier` (UUID). If actual key is `counterNumber`, move unique index there.
- FKs empty — counter ↔ register link goes into the Designer via `relates`/`depend`.

### 5. Reference

- Full working example with FKs at `examples/Schema.json` (alongside this skill — MeterDevice domain: counters, registers, gateways, meter locations).
