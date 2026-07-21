---
name: schema-authoring
description: Author a Schema.yaml from scratch for the Jardis Designer — from a plain-text domain idea, draft tables (snake_case plural), columns with realistic types, primary keys, indexes (primary/unique/index), optional foreign keys. Output matches the DB-export format the Designer's importer parses.
zone: pre
persona: A
prerequisites: []
next: [platform-implementation]
---

## Driving rules

1. Ask for the domain idea (one paragraph). If vague: **one** sharp clarifying question, not an interrogation.
2. Tables = plural snake_case. Implicit collections → own tables.
3. Every table: PK (`int` autoincrement default; UUID only if domain dictates). Business identifier → `unique` index. Lookup/filter column → non-unique `index`.
4. FKs **optional** — only include when the relation is unambiguous. Otherwise `foreignKeys: {}` and let the Designer model relations interactively.
5. Output = one complete `Schema.yaml` block + explicit assumption list.

Import instruction: `Schema → Import → File` in the Designer.

### 1. Schema.yaml structure

```yaml
tables:
  <table_name_snake_case>:                 # table name = the map key (no separate `name:` field)
    columns:
      -
        name: <column_name>
        type: <SQL type, see catalogue>
        phpType: <int | string | datetime | float | bool>
        length: <optional integer, for varchar/char>
        nullable: <true | false>
        primary: <true | false>           # PK column only
        autoincrement: <true | false>     # PK int only
    indexes:
      -
        name: <index_name | PRIMARY>
        columns: [<col>, <col>, …]
        type: <primary | unique | index>
    foreignKeys: {}                       # empty map OR list of:
      -
        column: <local_column>
        referencedTable: <other_table>
        referencedColumn: <other_column>
```

**Column required:** `name`, `type`, `phpType`, `nullable`. `length` required for `varchar`. `primary`/`autoincrement` only on PK.
**Index required:** `name`, `columns`, `type`. PK index = `PRIMARY`.
**FKs empty:** use `{}` (matches real DB exports) or `[]`.

### 2. Types

| `type` | `phpType` | Use for | Notes |
|---|---|---|---|
| `int` | `int` | numeric IDs, counts | `autoincrement: true` on PKs |
| `bigint` | `int` | large IDs, counts | Row count > 2^31 |
| `varchar` | `string` | identifiers, names, short text | `length` mandatory; common 36/50/100/255 |
| `text` | `string` | long-form text | No `length` |
| `date` | `datetime` | dates, timestamps | `datetime` covers both date-only and timestamp values in PHP |
| `decimal` | `string` | money, precise numerics | Own column keys `precision: <int>` (total digits) + `scale: <int>` (decimal places), **not** `length` |
| `float` | `float` | imprecise numerics | Avoid for currency |
| `bool` | `bool` | flags | |
| `json` | `string` | semi-structured payloads | Use sparingly |

### 3. Modelling heuristics

- **Identifier columns:** every business object typically has an internal `int` PK (`id`) **and** a public business identifier (`identifier`, often `varchar(36)` UUID7). Unique index on the business identifier.
- **Active period:** "active period" in the idea → `activeFrom` (`date`, not nullable) + `activeUntil` (`date`, nullable = open period).
- **Lookup tables:** categories/types/statuses get their own table even when described as enums.
- **Single PK only:** exactly **one** PK column per table. A composite (multi-column) PK is a hard build error (S7). Junction/N:M tables therefore get a surrogate PK + two FKs, never a composite PK.
- **Junctions:** many-to-many → explicit junction table with surrogate PK + two FK columns.
- **No relations in Schema.yaml unless obvious.** Relations are modelled later in the Designer — leave them out here.

### 4. Example

Idea: *"Track meter readings. A counter has a number and an active period, lives at a meter location, can link to multiple registers via a gateway."*

```yaml
tables:
  counters:
    columns:
      - { name: id, type: int, phpType: int, nullable: false, primary: true, autoincrement: true }
      - { name: identifier, type: varchar, phpType: string, length: 36, nullable: false }
      - { name: meterLocationIdentifier, type: varchar, phpType: string, length: 50, nullable: false }
      - { name: counterNumber, type: varchar, phpType: string, length: 50, nullable: false }
      - { name: activeFrom, type: date, phpType: datetime, nullable: false }
      - { name: activeUntil, type: date, phpType: datetime, nullable: true }
    indexes:
      - { name: PRIMARY, columns: [id], type: primary }
      - { name: identifier, columns: [identifier], type: unique }
      - { name: meterLocationIdentifier, columns: [meterLocationIdentifier], type: index }
    foreignKeys: {}

  registers:
    columns:
      - { name: id, type: int, phpType: int, nullable: false, primary: true, autoincrement: true }
      - { name: identifier, type: varchar, phpType: string, length: 36, nullable: false }
    indexes:
      - { name: PRIMARY, columns: [id], type: primary }
      - { name: identifier, columns: [identifier], type: unique }
    foreignKeys: {}
```

Assumptions to state:

- Business identifier = `identifier` (UUID). If actual key is `counterNumber`, move unique index there.
- FKs empty — counter ↔ register link goes into the Designer via `relates`/`depend`.

### 5. Reference

- Full working example with FKs at `examples/Schema.yaml` (alongside this skill — MeterDevice domain: counters, registers, gateways, meter locations).
