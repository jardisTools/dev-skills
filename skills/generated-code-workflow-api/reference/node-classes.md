### 4. Node classes

Every node is a class `final class <Node> extends {Domain}Context` with NO constructor of its own and one entry point:

```php
public function __invoke(LendBookContext $ctx): NodeResult
```

The examples of §4 to §6 use these package imports (the generated classes import them the same way):

```php
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\Handler\Node\MapResponse;
use JardisSupport\Workflow\Handler\Node\MapResponseInto;
```

`{Domain}Context` is the generated kernel seam; a node reaches the rest of the world through it:

| In a node body | Use |
|---|---|
| `$ctx->input()` | the typed process input |
| `$ctx->input()->tenant` / `->user` | tenant and bare user id of the signed-in user, filled by the door from the request attributes (fields of the process input, only in a world with login; `tenant` required (403) only when the process reaches a tenant-marked aggregate, `user` never); sub-process and call nodes hand them on unchanged (the session field of a sub-process input has this one source, never an open place, even beside a same-named field of the accepted command; a column the model fills from `user` holds that bare id, whatever its name; another value there is a finding on the model, not a task of the node); read them, never take them from a client field |
| `$ctx->input()->groups` | only when the process carries the statement `access`: the group ids of the session, a non-contract field of the process input (no property in the contract, no request field) that the route fills and the door uses for its one access question. Never read it, never fill it, never pass it on: a node, a sub-process node and a call into another BC are asked nothing |
| `$ctx->input()->accessEntries` | only when the process reaches a Create of a root whose root table is protected (no `access` statement in a world that protects; the table decides, not the creating aggregate; directly or through a sub-process): the access entries per information kind (one per root table), `array<int, list<Entry>>` (`Entry` of `jardissupport/acl`), a non-contract field of the process input — in no request schema, no header at the process door. The route fills it with the entry of the caller (personal group in level `all`, without one the fallback group of the kind); the command node hands the list of its root's kind to the Create, a sub-process node hands the whole map on. A node body only passes it along, never builds or changes entries; a run that fails after the Create leaves neither record nor entries |
| `$ctx->input()->shareTargets` | only when the process calls `Share{Agg}` of its own BC (a generated node, you never write it): the sharing targets (`groups`, `person`, `level`) a non-contract field of the process input that the route fills from the body behind `RequireRight`; the generated node passes it to `{BC}::share{Agg}()` inside the bracket, without a sight question. A refusal (404 unknown target, 422 last entry with `all`) rolls the whole run back. Never read, fill or name an input field `shareTargets`/`groups`/`person`/`level` yourself (`V-PROC-ACCESS-6`) |
| `$ctx-><node>()` | typed getter per node, generated in `{Name}Context` over `latest()`: the type is what the node `gives` (a read aggregate: `{Agg}Response`; an identifier: the `{Command}Response` of the call; a scalar value: `bool`/`int`/`string`…; an object value: its `{Name}{Value}` class; always nullable, `null` until the node ran); the raw access stays (`latest($node)`, `all($node)`, `previous()`, `iteration($node)`, `chain()`, `depth()`) |
| `$this->read(Handler::class, $filter): ReadResult` | read through an own-BC query handler: `->first()`, `->items()`, `->total()`, `->isEmpty()`; a handler of another BC throws `LogicException` (M9: reads stay in the own BC) |
| `$this->context(Get{Agg}By{Key}Handler::class, $query)(): DomainResponseInterface` | the read base of an own-BC aggregate (`ById`, `ByIds`, `By{Key}`, `By{Key}s`): the whole aggregate with its children; a key without a record answers a success with empty data (`getData() === []`), never a 404 (§5, read node) |
| `$this->handle(Class::class)` | any class through the kernel seam (aggregate write facade, closure, `Identity`, `SystemClock`); this is also how the factory builds nodes |

```php
$onLoan = $this->read(GetLoanListHandler::class, $this->handle(LoanListFilter::class, isbn: $isbn, limit: 1))->first();

return $onLoan === null
    ? NodeResult::route(Route::OnTrue)
    : NodeResult::reject(ResponseStatus::Conflict, 'lending.bookOnLoan', ['isbn' => $isbn]);
```

The body returns exactly one `NodeResult`:

| Factory | Use |
|---|---|
| `NodeResult::ok($data)` | success on `OnTrue`, `$data` is an array or object |
| `NodeResult::route(Route $route, $data = null)` | take a named route; `null` data means a result without data — nothing is handed on from the previous step |
| `NodeResult::reject(ResponseStatus $status, $messageKey, $context = [], $errors = [])` | end the run with a 4xx |
| `->withEvents(object ...$events)` | events travel with the result (replaces earlier events of the same result) |

There are no free slots; results sit in the chain and nothing is handed on from node to node: a node that needs an earlier answer reads it explicitly through the typed getter (or `latest()` / `all()`). `{Name}Context` extends `ProcessContext` (the documented exception to composition over inheritance); the chain stays immutable.

**Object value.** A node whose `gives` is `{kind: value, fields: [...]}` returns the generated `final readonly` class `{Name}{Value}` (next to the door) as its data: `NodeResult::ok($this->handle(EndsOnFieldsSummary::class, count: 1, note: null))`. The node declares no `response` (that key fails the load, CUT1); what it hands on is named by `gives`, and a later node reads it through the typed getter of its `{Name}Context`.

