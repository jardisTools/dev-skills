### 1. Event transport — authored in a Process node

The generator emits the base router at `{Agg}/Event/<Agg>EventRouter.php` (namespace `<Domain>\<BC>\Model\<Agg>\Event`, ForceOverwrite). It is a plain `class <Agg>EventRouter` carrying one **empty** `protected function on<Event>(EventListenerRegistryInterface $registry): void` stub per Domain Event (body `// configure transport`), each preceded by a channel-key comment (`{ChannelPrefix}.{ChannelSuffix}`) as a topic / routing-key suggestion. The Domain facade wires it directly into `eventDispatcher()`:

```php
protected function eventDispatcher(): EventDispatcherInterface|false|null
{
    return (new EventDispatcherHandler())(
        new CounterEventRouter()
    );
}
```

**The router is hermetic** (ForceOverwrite) — you never fill its bodies; the next build truncates them (V1). Events therefore leave a Process only through an **Event ◇ node**, and the transport of an announced event is delivery code in `App/**` (row 10 of the table in `generated-code-extend` §10), never an edit of the router. The aggregate command records its events on its response (`$this->result()->addEvent($event, EventScope::Internal)` in the generated handler) — the handler **only collects, it does not dispatch**. Note `getEvents(?EventScope)` is **context-keyed** (`array<string, array<int, object>>`), so flatten it before iterating: `array_merge(...array_values($response->getEvents()))` (or a nested `foreach`). (Aggregate life-cycle events are `Internal`; a **Domain event** is announced by an **Event ◇ node** of the Process.)

**What a node sees of the earlier nodes (workflow 5.2).** Nothing is handed on from node to node: a node that needs an earlier answer asks for it explicitly through the typed getter `$ctx-><node>()` of the generated `{Name}Context` (a nullable object over `latest()`: the `{Command}Response` of a call node, the `{Agg}Response` of a read, the object-value class or the scalar the node `gives`; `null` until the node ran) — the raw `$ctx->latest(NodeFqcn::class)?->data()` / `$ctx->all(NodeFqcn::class)` stay available, `$ctx->previous()` is only the node run last, which can be a node without data. The value of a call node is the command's typed reference value, e.g. `CreateCounterResponse` with the property `counterIdentifier` — read it by property: `$ctx->persistCounter()?->counterIdentifier ?? throw …`. A missing value must fail loud (`?? throw`), never read as a silent `null`. The node's `DomainResponse` and its `Internal` events are **not** forwarded by the default body (`MapResponseInto`: 2xx becomes `ok(<typed object>)`, 4xx a `reject`).

**How events leave a Process.** Only an **Event ◇ node** announces a Domain event (generated, no body to write). The node ends `NodeResult::route(Route::OnTrue)->withEvents(new <Event>(...))`: the events travel with the result, not in the data. The runner collects the events of all steps in execution order and reports them on the `ProcessOutcome` for a successful run only (a rejected result and a failed run report none); the generated handler adds each with `addEvent($event, EventScope::Domain)` and the **caller** publishes after the commit, from `$response->getEvents(EventScope::Domain)`. The identity of the event is read from the earlier node explicitly through the typed context — `$ctx->persistHeartbeat()?->heartbeatSignalId ?? throw …` — because an event node routes without data (`gives: nothing`); what the process answers is the `gives` of the end node that ran (an end node with `gives: {kind: nothing, name}` answers with the process `@type` and `@outcome` only, `{"@type":"<processName>","@outcome":"<gives.name>"}`). **Event class name and FQCN:** the event-data class is `{Domain}\{BC}\Process\{Process}\Event\<ucfirst(node name)>` (the node class beside it is `<ucfirst(node name)>Node`) — a node named `invoicePaid` emits `…\Event\InvoicePaid`. Listeners and hand-written transport import exactly that FQCN. A project built before the PascalCase derivation carried the raw lowercase name (`…\Event\invoicePaid`): the next build rewrites the event under the new name, reports "FQCN geändert: Listener-Importe prüfen" as a build Hinweis, and **your `use …\Event\invoicePaid;` imports must be updated by hand** (grep the project for the old FQCN after that build).

A node's `data` holds business fields only: status, rejects and events are carried by the `NodeResult` itself (`reject(...)`, `withEvents(...)`), never by a key inside `data` — do not put events or a status into `data`. A node does not publish: announcing is the Event ◇ node's job, publishing is the delivery code's.

**Event payload identity (X-3).** `<Agg>{ChildEntity}Added` events carry the affected child's business identifier (`<childIdentifier>`) when G4 is satisfied — otherwise the internal PK (same rule as the Command response, see Recipe 6). Wire your listener against the business key whenever you can; the internal PK is meaningful only inside the aggregate and changes on rebuild scenarios where data is reseeded.

**Publish from delivery code (`App/**`).** After the commit the caller holds the answer of the door and publishes its Domain events. That code is delivery code — row 10 of the table in `generated-code-extend` §10: it takes the events from the answer, hands them to the transport package, and carries no business decision. It reaches the BC only through its doors — `{agg}()` (reads, `{Agg}Read`), `process()` and the direct command method `{BC}::{command}()` of a command door at stance `domain` or wider (HTTP route from `outside`) — and the transport package through the kernel's accessors (`$kernel->messaging()`, `$kernel->httpClient()`); never `Model/**`, a repository or `DbQuery`.

**Kafka / RabbitMQ / Redis** via `jardisadapter/messaging`:

```php
// App/** — delivery code, no business logic
$response = $app->counter()->process()->createCounter($dto);   // the public door of the BC

if ($response->isSuccess()) {
    foreach (array_merge(...array_values($response->getEvents(EventScope::Domain))) as $event) {
        $kernel->messaging()?->publish('meterdevice.counter.counter.created', $event);   // MessagingServiceInterface of the DomainKernel
    }
}
```

- A throwing node ends the run as a technical failure: rollback, answer 500, the cause is logged once by the package (`generated-code-workflow-api` §7). A node does not catch an exception to route it; wrap `try/catch` inside the node only to turn a failure into a `reject` or a route.

