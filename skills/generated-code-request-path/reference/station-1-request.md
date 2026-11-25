### Station 1 — Request

Navigation: back to the index [`SKILL.md`](../SKILL.md) · the other stations are listed there.

Transport, frame, middleware, session claims `tenant` / `user`. Everything here has happened before any generated handler or hand-written body runs.

## Guarantees

- **Fixed frame before every route.** The outermost layer turns any throwable into a 500 envelope (no stack trace). An unknown path or method is 404 / 405, and an invalid, empty or repeated `X-Jardis-Version` header is 400 `validation` with `messageKey: request.versionUnknown`. Both are answered before any middleware runs.
- **Session.** In a world with login, `RequireSession` runs globally. Without a valid token it answers 401. The access token is signed and short-lived; an expired or invalid token fails authentication. The middleware first removes every session attribute a client may have sent, then sets `SUBJECT`, `TENANT`, `RIGHTS` and `SUBJECT_ID` from the verified token.
- **Right per route.** A door at position `outside` carries `RequireRight('{bc}.{agg}:{row}')` (processes: `{bc}.process:{row}`). A missing right answers 403 `access.rightMissing` in package form (no `@type`). A right matches as `resource:action`, `resource:*` or `*`.
- **Required tenant.** If the process reaches an aggregate with a tenant scope, `RequireRequestAttribute(TENANT)` answers 403 `request.attributeMissing` when the session carries no tenant. A process that reaches no tenant-scoped aggregate has no tenant gate.
- **Claims become input.** `tenant` and `user` are filled from the session attributes into the input DTO, never from the body. A body key `tenant` or `user` on the door of a process or an aggregate command is answered 400 `unknown`; inside the command object of an `accepts:` entry the key is tolerated and dropped — the value always comes from the session. The input field has the type of the marker column (`int` or `string`); the door converts once (a session value that is not an integer reads as `0`), so the body reads the typed field as it is.
- **Groups of the session.** In a world with per-record access (login AND groups AND a protected root table; protection is the rule, no statement needed) three more middleware run behind `RequireSession`, all asking ONE directory, the project class of the setting `groupLookup` or `GroupDirectoryFromDoors` over the read doors of `groupDoors`: `ResolveGroups` sets `GROUPS` (`list<int>`) and `PERSONAL_GROUP` from the signed-in person, `ResolveFallbackGroups` sets `FALLBACK_GROUPS` (kind => group) for a request without a personal group, and, at a create route only, `ResolveShareTargets` sets `SHARE_TARGETS` from the header `X-Jardis-Share-With: <group>=<view|edit|all>, …`. Like the other session attributes they are removed first (a client cannot send them) and are no body, query or contract field. The read route turns `GROUPS` into the `groups` filter (absent = `[]` = no records; owner `*` or a `seeAll:` entry the rule of the door accepts = `null` = no filter); the create route turns them into the access entries of the new record (a bad level word is 400 under the header name, an unresolvable group 404). The session never carries entries or levels, only group ids. The write routes use the same `GROUPS`: a command route of a protected root and a process route whose process carries the statement `access` turn them into the carrier `groups` of the DTO (same rule as the read route: absent = `[]`, owner `*` or a `seeAll:` entry = `null`), and the generated handler or process door asks the access level once before anything is written or any node runs: no sight is 404 (the same as an unknown key), a level too low is 403 `forbidden` (`edit` for changing, `all` for deleting a root; a dependent record is asked at its owner).
  One exception to "once": a change that moves a dependent record (the update delivers `via` with another value than the stored one) asks a second time, at the new owner, with `edit` - the same 404/403 answers, nothing written when either refuses. A call from a node, the facade or a Rule carries `null` and is not asked.
- **No per-user key claim yet.** A per-user key claim is not yet issued by Jardis; until then the session carries `tenant` and `user` only.
- **Session guard at start.** A route that is neither protected nor declared anonymous prevents the app from starting. In a world without login (`middleware: []`) every route is anonymous and no session, right or tenant guard exists.
- **Rights and session are checked exactly once, at the HTTP outer door.** A neighbour BC calling through a `domain` door, the kernel seam and a sub-process check no right and no session; the domain knows neither right nor actor. Whether a door is a route at all is its position in `Doors.json` (`closed · domain · outside · open`); only a process row can be `open`.

## Never

- Check a right, a role or a session in a node body, a Service, a Closure or `App/**` delivery code — rights hang on doors, never on Rules or aggregates.
- Read the token, the `Authorization` header, request attributes or the session in a body. The identity arrives as `$ctx->input()->tenant` / `->user`.
- Accept `tenant` or `user` from a body field, resolve them from a lookup, or cast them by hand (`(int)`); the field is already typed.
- Rebuild a rights resolution (actor to role to right) outside the access blueprint.
- Register a route, an anonymous route or a middleware by hand to expose behaviour that has no door row. Exposure is modelled (`save_doors`), not coded.

## Variants

| Variant | Where it shows |
|---|---|
| HTTP route to a process (position `outside`) | `Api/{Domain}/routes.php`: right, required-attribute guard, input mapping, process door |
| HTTP route to a declared query (read door) | `Api/{Domain}/routes.php`: page-bound guard, then `Get{Query}Handler` |
| HTTP route to a command door directly (position `outside`, create included) | `Api/{Domain}/routes.php`: mapper, then the BC facade method |
| Open route (process row `open`) | `$routes->anonymous()->post(…)`: no session, no right, optional marker field has the empty value of its type |
| World without login | `App/middleware.php` returns an empty pipeline; every route is anonymous |
| Neighbour BC (position `domain`) | no route, no middleware; the caller passes `tenant` / `user` on from its own input; `{BC}/Service/{Name}.php` |
| Sub-process | door reached through the kernel seam with `depth + 1`; no session, no right; `{BC}/Process/{Name}/Command/Handler/Action/{Node}.php` |
