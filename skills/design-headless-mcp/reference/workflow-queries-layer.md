#### Continuation §2 — Queries-Layer

**Queries-Layer — full MCP parity:** a BC's
`Queries.json` (declarative read queries — `root:` entity, no stance key (the stance of a query is its row in `Doors.json`, default `closed`; a `visibility` key is refused with `V-DOOR-0`),
condition tree, parameters, joins; every query is a paginated list answering
`{items,total,limit,offset}`, so there is no output-form key — a document still carrying `form:`
is rejected by the Blocker V-QDEF-24) is fully MCP-reachable, same
pattern as Rules above. `save_queries` persists the whole artefact (LockedSave — mtime `CONFLICT`
like `save_aggregate`/`save_closures`; a draft with findings still writes, `valid`/`errors`/
`warnings`/`suggestions` travel in the response; the saved query set is echoed only with `echo: true`, otherwise read it through the queries Resource); `validate_queries` checks a not-yet-saved query
set against V-QDEF-1..34 (without the retired 17, 22, 28, 29; 25 = `limit` above 500, warning; 26 = the query's class stem equals a derived read way `{Agg}ById`/`ByIds`/`By<Key>` of its aggregate, blocker) plus RB1 (name collision with the read base, Blocker) —
read-only, no write. `preview_queries` is read-only and
returns the **generated PHP code** a build would write for the query set (never SQL — the Builder
never emits SQL, only PHP) plus artefact-wide findings; pass `compareWithStored: true` for a
consequences preview (`consequences`) of confirming this draft — a query appearing or disappearing, a
stance change of a query (its `Doors.json` row), the BC facade's public surface base path `GET …/{agg}` moving with the query
named `{agg}List` (fileAdded/fileRemoved/fileChanged, facadeMethodAdded/facadeMethodRemoved,
basePathAdded/basePathRemoved) — before committing to `save_queries`. The comparison state is
always what lies on disk, never a set the caller supplies, and `consequences` stays empty for a
draft the rules reject (the rule id then travels alone, in `errors`). Lifecycle tools mirror `rename_process`/
`delete_process`'s pattern: `rename_query` (cascades into every BC-local reference — a Closures.json
catalog entry's `reads:[]` and process nodes — `confirm=true` required), `delete_query`
(`force=true` overrides an `IN_USE` 409 from >=1 declared reader), `duplicate_query` (auto-suggests
a free `{name}Copy`/`{name}CopyN` name, the copy has no `Doors.json` row and so stands at the default `closed`). Read side: the
`queries` Resource template returns the artefact as-is (a BC without one reads as an empty set,
never an error); `queries-usage` lists which Rules/process nodes reference a given query — the
same computation `rename_query`/`delete_query` use for their `consequences`, not a second one.

**Chain and join pairs in `Queries.json` (builder-side; no new tool, both travel in the `QueryDef` of
`save_queries`/`validate_queries`/`preview_queries`/`probe_query`).** `chain` makes a query answer only the root rows a
bounded recursive walk reaches (a tree inside one table through a parent reference, or an edge table): keys `via`,
`from`, `to`, `start` (condition tree on the root) and `maxDepth` (1..999) are mandatory, `where` (condition tree on
the `via` entity), `key` (default: the root's single-column primary key) and `depthParam` (a declared `int`
parameter that narrows the depth at run time) are optional. It is a pure filter: the answer stays the normal paginated
list of the root (no depth column, no ordering by depth), `joins`, `fields`, sorting and the count stay as they are.
A cycle in the data ends (`union`, not `unionAll`); `maxDepth` measures the shortest path; the tenant marker filters
start and step, the sight only the outer root. A malformed chain is `V-QDEF-33` (form, e.g. a missing mandatory key,
`maxDepth` outside 1..999), a reference in the wrong tree or a type mismatch of `from`/`to`/`key` is `V-QDEF-34` — both
are findings in the answer, never a transport error; `preview_queries` shows the generated recursive code,
`probe_query` runs it. The generated code needs `jardissupport/dbquery` >= 1.3.1 for `chain` and >= 1.4.0 for every join condition (the build warns below that). `joins[].on` is the first field pair (`left`, `right`, both `alias.field`); further pairs go
into `on.and`, a list of `{left, right}` pairs that are AND-connected in the written order and each checked like the
first (a finding names `joins[i].on.and[j].left`); an unknown key next to `and` or inside a pair is refused by the
MCP schema (`additionalProperties:false`). `duplicate_query`, `rename_query`, `delete_query` and `save_query_layout`
leave both untouched.
