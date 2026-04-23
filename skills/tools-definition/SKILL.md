---
name: tools-definition
description: YAML definition vocabulary the Jardis Designer emits — `erm`/`relates`/`depend`/`adopt` in Aggregate.yaml, parameter binding in Source.yaml / Lists.yaml, FieldMap conventions.
zone: post-reference
prerequisites: []
next: [platform-implementation]
---

### 1. Aggregate.yaml — ERM hierarchy with hints

Aggregate root + nested entities. Hints drive join / persist / validation in the generated code.

```yaml
counter:
  source: counters
  erm: one

  counterGateway:
    source: counter_gateways
    erm: many
    relates: 'counterId = counter.id'
    adopt:
      counterId: counter.id

    counterGatewayRegister:
      source: counter_gateway_registers
      erm: many
      depend:
        registerId: register.id
      adopt:
        identifier: '"__UUID7__"'
        counterGatewayId: counterGateway.id
      relates: 'counterGatewayId = counterGateway.id'

      register:
        source: registers
        erm: one
        relates: 'id = counterGatewayRegister.registerId'
```

| Hint | Meaning |
|---|---|
| `source: <table>` | DB table (must exist in Schema.yaml) |
| `erm: one` \| `many` | Cardinality. `one` = singular reference, `many` = collection |
| `relates: 'fk = parent.pk'` | Join between this entity and its parent |
| `depend: {field: ancestor.path}` | Field must resolve to an existing ancestor before persist |
| `adopt: {field: value}` | Default on CREATE. Literal, ancestor ref, or `"__UUID7__"`. Must be a `field: value` map — bare scalars rejected by validator. |

Top-level key = aggregate root. Nested keys = child entities, recursive.

### 2. Source.yaml — read-side joins and named queries

Mirrors Aggregate.yaml shape on the read side. Each entity: `source`, `relates`, optional `orderBy`. `queries:` block defines named queries with parameter binding:

```yaml
queries:
  default:
    parameters:
      - 'string $identifier'
    counter:
      source: counters
      relates: 'identifier = $identifier'
```

- `parameters:` — typed PHP-style list → handler arguments.
- `$paramName` in `relates:` → prepared-statement binding.

### 3. FieldMap.yaml

Flat 1:1 `dbColumn: entityProperty` per entity. Indirection allows DB renames without touching entity code.

### 4. Lists.yaml

Same parameter binding as §2. `field:` = comma-separated projection columns. Each top-level key → `Get<Name>ListHandler`.

```yaml
counterList:
  parameters:
    - '?string $activeFrom'
  counter:
    source: counters
    field: 'identifier, clientIdentifier, activeFrom, activeUntil'
  filter: 'counter.activeFrom >= $activeFrom'
  orderBy: 'counter.activeFrom DESC'
```

### 5. Reference

- Full working example (all four artefacts): `examples/Counter/Aggregate.yaml`, `Source.yaml`, `FieldMap.yaml`, `Lists.yaml` — MeterDevice Counter aggregate with ERM, relates, adopt, and a list query.
- Schema input format: `schema-authoring`
- Extending the generated PHP: `platform-implementation`
