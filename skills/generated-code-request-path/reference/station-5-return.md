# Station 5 — Return to the handler: projection, failure form, envelope, events

Navigation: back to the index [`SKILL.md`](../SKILL.md) · the other stations are listed there.

The node, query or capsule has produced its result. Station 5 turns it into the answer of the request. Everything that shapes or formats the answer is done by the packages `workflow` and `response` and by the generated door and facade; your code hands over a result and nothing else.

## Guarantees

**Process answer**

- On success `AnswerOutcome` builds `@type` (the process name), `@outcome` and the `gives` of the end node that ran; on a rejection it builds the failure form. The handler places the events of a successful run on the result with `EventScope::Domain`.
- `@outcome` is the `gives.name` of the end node that ran, on every 2xx answer, also for an end that gives nothing. An end node gives `nothing`, `identifier` or `value` with `fields`; `aggregate` and a scalar `value` are blockers (`V-PROC-ANSWER-5`). Nothing is passed through: what an earlier node delivered reaches the answer only if the end node names it in its `gives`; an earlier step stays readable through the getter `{P}Context::{node}()`.
- The caller tells outcomes apart by `@outcome`, never by the presence of fields. An unknown `@outcome` is an error branch for the client; a new outcome is additive, renaming or removing one breaks the contract.
- The outer cut depends on the world. The facade `{BC}Process::{name}()` cuts the answer to the form of the outcome (`ProjectOutcome` with one `OutcomeForm` per outcome; time fields are reshaped first). A missing `@outcome` or a missing form is a 500 (`ConfigurationException`). Inside (handler, `{P}Context`, nodes) every field is available; outside, a `ref` field that points to an aggregate without a read door in the reader's world is dropped (a neighbour BC from stance `domain`, HTTP from `outside`). The facade may deliver a field that the route does not.

**Query answer**

- A list passes its rows through `ProjectWire` into `ReadResult::ofList`; time leaves as an ISO string in UTC (`date` as `Y-m-d`, `date-time` as ATOM with `+00:00`), a decimal as a string, a bound value list as a closed enum. A point read maps through the FieldMap `fromAggregate`. The route builds `items`, `total`, `limit` and `offset`. A tenant-marker column stands in no read output.
- The kernel read path of a list returns a `ReadResult` object, not an array.

**Command answer**

- A write answers with reference values, never with objects: the public key of the root plus the references of created children. Inside the BC the answer carries the root `id` in addition when the root has a public key; outside, `ProjectAnswer` (for a process `ProjectOutcome`) drops the `id` as long as a key exists. A token answer is no exception.

**Failure form and envelope**

- The failure forms for 400, 404, 409 and 422 come from one table, identical for aggregate command, route and process: `validation` with `fields[]`, `notFound` with `context`, `concurrencyConflict` with `reason`, `aggregate`, `context`, `violated`, `ruleViolation` with `rule`, `messageKey`, `context`. A 4xx with a table status carries exactly `@type` and the fields of its form; a missing field stays empty and nothing is filled from `errors` or a node name. 401 and 403 come from the middleware without `@type`.
- A 5xx becomes a 500 only at the owner of the transaction; a nested run rethrows.
- The envelope is `{status, data, errors, meta}`. `data` is flat and names itself with `@type`; `errors` is an object `{<context>: [message]}`, reliably filled only for a 500, and for a 4xx the cause stands in `data`. Events never stand in the envelope.
- The caller publishes the events after the commit, which means the application (`App/**`: event listener, transport). There is no internal event system; the empty listener in the `{Agg}EventRouter` is an intended hook, not a gap. An application without a consumer publishes nothing, and that is as intended.

## Never

You hand back what your node gives per its `gives`; form, outer cut, time format, failure form and envelope are Jardis's. So you never:

- convert a row, a foreign-BC result or a command answer by hand into the `gives` type, or copy a field of the answer into a second field. Give the value the node declares; the projection does the rest.
- reformat time or decimal values for the wire; `ProjectWire` does it.
- build the envelope, an HTTP status, a `DomainResponse` or a transport response in a node. A node returns a `NodeResult`.
- build a 4xx payload outside the closed table, give a field error a `reason` or `messageKey` of your own, or put a status, an error or an event into `data`.
- read `getData()['<handler>']`, `data.request.fields` or any handler or root level inside `data`; the payload is flat. Do not read a list from the kernel path as an array; it is a `ReadResult`.
- return an object where a reference belongs, or pass the `id` through where the public key stands.
- decide the exits by the presence of a field; the exit is `@outcome`.
- dispatch, publish or listen to an event in a node, and never build an internal event system. An event is attached to the result (`NodeResult::…->withEvents(...)`); publishing it after the commit is delivery code in `App/**`.

## Variants

All paths are relative to the generated tree; `{P}` is a process, `{Agg}` an aggregate, `{Cmd}` a command, `{Name}` a declared name, `{Key}` a public key.

| Form | Where | Differs |
|---|---|---|
| Process with one outcome | `{BC}/Process/{BC}Process.php` | one `OutcomeForm` |
| Process with several outcomes | `{BC}/Process/{BC}Process.php` | one `OutcomeForm` per outcome; OpenAPI `oneOf` with `discriminator` on `@outcome` |
| Second cut at the route | `Api/{Domain}/routes.php` | the outer world has fewer fields than the facade, `ref` fields without a read door are dropped |
| Query list | `{BC}/Model/{Agg}/Query/Handler/Get{Name}Handler.php`, route in `Api/{Domain}/routes.php` | `ReadResult` inside, envelope with `items`, `total`, `limit`, `offset` outside |
| Point read | `{BC}/Model/{Agg}/Query/Handler/Get{Agg}By{Key}Handler.php` | maps through `fromAggregate` |
| Command reference | `{BC}/Model/{Agg}/Command/Handler/{Cmd}.php` | reference values, root `id` only inside |
| Failure form from a node | the node body `Action/{Node}.php` returns `reject(status, messageKey, context)` | the package forms it through the failure table |
