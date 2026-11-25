#### Continuation §3 — envelope and examples

Envelope from `getStatus()` / `getData()` / `getErrors()` / `getMetadata()` (plus `isSuccess()` shortcut). **`getData()` is flat:** it is the business object itself, typed by its own `@type` field — there is no handler-name or aggregate-root level in between. **`getErrors()` is context-keyed** (`array<string, list<string>>`, e.g. `['counter' => ['message']]`); on the wire `errors` is an object `{<context>: [message, …]}`, `{}` when no component put a message there (never `[]`). **Where the cause of a failure lives:** only a **500** reliably fills `errors`; a **4xx** (400 field/guard, 403 level too low, 404, 409, 422) carries its cause in the typed `data` (`fields[]` / `context` / `rule`+`messageKey`), and `errors` may be `{}` there — the route guard's 400 and a node's 404 leave it empty, the aggregate CommandHandler's 400 fills it with the validator message. So a transport that needs the reason of a 4xx reads `getData()`, never only `getErrors()`. `meta` carries `duration`, `contexts`, `timestamp`, `version`. The generator emits a minimal payload (`generated-code-recipes` Recipe 6) — **Command** echoes only the reference values (root business key, plus the affected child key), **Query** returns the projected aggregate: the root fields flat in the `data` array, children as typed `{Child}Response` objects (in PHP read them with `->`; `json_encode` writes the same nested JSON as below). `@type` per answer kind (full table: `generated-code-recipes` Recipe 6): the aggregate's root key (`counter`) for a single read and a Command, `<agg>List` for list and bulk reads, for a Process the process name (lcfirst), always, with `@outcome` behind it (the `gives.name` of the end node that ran), `validation` / `notFound` / `concurrencyConflict` / `ruleViolation` / `forbidden` for 400 / 404 / 409 / 422 / 403 (a Process builds the form from the `reject(...)` of a dev body:
`data` is `@type` plus the form's fields; a 4xx without a form, e.g. 401/403/405, is passed through unchanged; the `messageKey` the node declares with `rejects(status, key)` is set on `data` after forming, for every 4xx status — also when it is not a field of the form). Examples:

```json
// Command via a Process (e.g. createCounter) → 200, reference value only
{
  "status": 200,
  "data":   { "@type": "createCounter", "@outcome": "counterCreated", "counterIdentifier": "018e..." },
  "errors": {},
  "meta":   { "duration": 12.4, "contexts": ["counter"], "timestamp": "2026-10-07T10:00:00+00:00", "version": "" }
}

// Query (e.g. getCounterByIdentifier; getCounterById on an aggregate without a public unique key) → 200, flat projected record
{
  "status": 200,
  "data":   { "@type": "counter", "identifier": "018e...", "counterNumber": "M-1", "activeFrom": "2026-01-01", "recordedAt": "2026-10-07T10:00:00+00:00", "price": "19.90" },
  "errors": {},
  "meta":   { "duration": 8.1, "contexts": ["counter"], "timestamp": "2026-10-07T10:00:00+00:00", "version": "" }
}

// Bulk read (getCounterByIds / getCounterByIdentifiers) → 200
{ "status": 200, "data": { "@type": "counterList", "items": [ { "identifier": "018e..." } ] }, "errors": {}, "meta": { … } }

// Validation failure → 400
{
  "status": 400,
  "data":   { "@type": "validation", "fields": [ { "field": "counterNumber", "reason": "missing", "message": "Field is required.", "messageKey": "validation.missing" } ] },
  "errors": { "<context>": [ "…" ] },   // filled by the aggregate CommandHandler; {} from the route guard — the cause is always in data.fields
  "meta":   { … }
}

// Not found on write (e.g. update of a missing counter) → 404, never 500
{ "status": 404, "data": { "@type": "notFound", "context": { "counterIdentifier": "018e..." } }, "errors": {}, "meta": { … } }

// Rule rejection (or own-BC dev-body 422 at a Process door) → 422
{ "status": 422, "data": { "@type": "ruleViolation", "rule": "…", "messageKey": "counter.must_be_active", "context": { "identifier": "018e..." } }, "errors": {}, "meta": { … } }
```

Time leaves the door as an ISO string in UTC (`date` `Y-m-d`, `date-time` ATOM with `+00:00`; never a `DateTime` object), a decimal as a string (`format: decimal`, pattern `^-?\d+(\.\d+)?$`), a column bound to a value list as a member of a closed `enum`. A single read or a loading write that finds nothing answers 404 `{"@type":"notFound","context":{<route parameter or load identifier>}}` at the outer door (inside the family, through the kernel seam, the payload stays empty). A list read through the aggregate read facade (`{agg}List(...)`) returns a `ReadResult` (`->items()`, `->total()`), not a `DomainResponse`; the generated route wraps it as `data = {"@type":"<agg>List", items, total, limit, offset}` (`limit`/`offset` come from the filter) — a hand-written transport that wants the same outer shape does the same.

CQRS: the Command response carries only identity — to get full state after a write, issue the matching read-base query (`get<Agg>By<UniqueKey>` with the echoed business key, or `get<Agg>ById`) via `$app->{bc}()->{agg}()`. The read facade (`{Agg}Read`) of an aggregate carries the read base `get<Agg>ById` / `get<Agg>ByIds` (aggregate WITHOUT a public unique key) or `get<Agg>By<UniqueKey>` / `get<Agg>By<PluralKey>` (WITH one; then no ById/ByIds on the facade) plus `<agg>List`, each as a door at `domain` or wider — there is no suffix-less `get<Agg>` (catalog + bulk-read recipe: `generated-code-extend` §1). An aggregate with a public unique key also carries `get<Agg>By<PluralKey>` (bulk read on that key), and its list items lead with that key — not with `id` — as the public surface's canonical handle and the projected single-aggregate record of such an aggregate has no root `id` property at all; only an aggregate **without** a unique key leads list items with the root `id` and carries it (normalized as `id`) in the record. Family-internally `id` remains the reachable handle either way (`getById`/`getByIds` keep being emitted); whether ids are exposed outward at all is this transport layer's decision. Events (`getEvents()`) are collected on the response, not dispatched by the handler; publication after commit is the caller's job (`App/**` delivery code for an event an Event ◇ node announced — see `generated-code-recipes` §1). Include them in the transport response only for debug / fire-hose APIs.

