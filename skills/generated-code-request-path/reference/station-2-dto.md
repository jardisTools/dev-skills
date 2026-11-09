### Station 2 — DTO

Navigation: back to the index [`SKILL.md`](../SKILL.md) · the other stations are listed there.

The input of a command, a process or a query filter. First check: form and type. Your code receives well-formed, typed values only.

## Guarantees

- **The DTO is a `readonly` carrier and checks nothing.** Data structures hold data; behaviour lives elsewhere. What it guarantees is the PHP types of its properties. In a patch, `Absent` means "not supplied", `null` clears a nullable field (on a non-nullable field it is 400). A `Set` command stays a full command, not a patch.
- **The form check happens before the DTO exists** (HTTP path). The request mapper in `Api/{Domain}/routes.php` reads each field on its own and collects every finding: a missing mandatory field is `missing`, a wrong type is `type`, a key that is not part of the input is `unknown`. It answers 400 `validation` with `fields[]` (`field`, `reason`, `message`, `messageKey`). `reason` is one of the closed vocabulary `missing · invalid · type · unknown`; `messageKey` is always `validation.<reason>`.
- **Lists and objects are checked per element** and reported with their path, for example `stamp[0].weight`. A wrong shape (object where a list is expected and the reverse) is 400 `type` at any depth; no door reinterprets a value silently. The path form is flat at an aggregate door (`customer.customerName`) and prefixed with the input property at a process door (`registration.serviceName`).
- **Closed input vocabulary.** A process input is a scalar, a scalar list, an object (`fields[]`, up to two levels), an object list, or directly in `input.fields` an `accepts:` command field. `[]` is a valid value. For a flat field the obligation is presence (400 `missing`); a minimum length or a business bound on it is a Rule (422).
- **Unknown keys are refused.** Every request schema with body fields is closed (`additionalProperties: false`); a body key the input does not name is `unknown`, including `tenant` / `user`.
- **Date, datetime and time values** in a body, a query string or a path pass the same form check (400 `type`).
- **Paging range.** `limit` runs from 1 to the declared upper bound of the query, `offset` from 0. A value outside is 400 with `field` and a text naming the bound, never 500 and never silently clamped. A declared query checks it in the route guard; a read process (`kind: query`) checks it in its input gate `{Name}Validator`.
- **Without a request mapper** (call through a facade, from a neighbour BC, from a sub-process) the DTO carries only its PHP types. The content check follows at the door (station 3) and at the capsule (station 4); it is never skipped.

## Never

- Check mandatory, empty, type or unknown-key in a node, a Service or a Closure; the mapper and the gates already answered 400.
- Build a 400 field error in a node body. A node refuses with `NodeResult::reject(...)`; for a field content there is no node 400, and a `reason` or `messageKey` outside the closed vocabulary does not exist.
- Give a patch field a default of your own (`?? Absent::Value`, a made-up neutral value). `Absent` means unchanged, `null` means cleared.
- Convert an input or claim value by hand (casts, `trim`, date parsing); the DTO holds it in its final type.
- Range-check `limit` / `offset` by hand or clamp them.
- Put checks or logic into a DTO, a filter or a patch class. They are hermetic data classes.
- Express a minimum length or a business bound of a flat input field as a field error; that is a Rule with status 422.

## Variants

| Variant | Where it shows |
|---|---|
| Process input (POST body) | `Api/{Domain}/routes.php` mapper, then `{BC}/Process/{Name}/Command/{Name}.php` |
| Read process `kind: query` (GET): `limit` / `offset` without a bound in the route | route as above; the bound sits in `{BC}/Process/{Name}/Command/Handler/{Name}Validator.php` |
| Declared query (GET): `limit` / `offset` with bounds in the route | `Api/{Domain}/routes.php`, filter `{BC}/Model/{Agg}/Query/{Query}Filter.php` |
| Command door directly, object list checked per element | `Api/{Domain}/routes.php`, command `{BC}/Model/{Agg}/Command/{Cmd}.php` |
| Patch (update) | `{BC}/Model/{Agg}/Command/{Cmd}Patch.php` |
| Call through a facade, from a neighbour BC or a sub-process: no mapper, PHP types of the DTO only | `{BC}/Service/{Name}.php`, `{BC}/Process/{Name}/Command/Handler/Action/{Node}.php` |
