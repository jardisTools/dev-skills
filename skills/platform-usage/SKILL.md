---
name: platform-usage
description: Wiring Designer-generated Commands/Queries into a transport layer — bootstrap lifetime, 4-hop Api-Registry call chain, DomainResponse→transport mapping, error handling for HTTP / CLI / queue / worker.
zone: post-active
prerequisites: [platform-implementation]
next: []
---

### 1. Call chain

```
new MyApp()                       ← DomainApp subclass, one per request/run
    ↓ $app->counter()             ← BC facade
    ↓ ->counter()                 ← Aggregate facade
    ↓ ->command()                 ← CounterCommands  (or ->query() / ->event())
    ↓ ->createCounter($dto)       ← DomainResponseInterface
```

Four hops: Domain → BC → Aggregate → Registry. The BC facade exposes only aggregate accessors — it has no `command()` / `query()` / `event()`. Registries live per aggregate under `<BC>/<Agg>/Api/` and are the only public entry points. Direct `new CreateCounterHandler()` violates V3.

Matching BC/aggregate names (`MeterDevice\Counter\Counter`) → two `->counter()` hops are correct. Non-matching (`Ecommerce\Sales\Order`) → `$app->sales()->order()->command()->createOrder($dto)`.

### 2. Bootstrap lifetime

| Transport | `MyApp` lifetime | Note |
|---|---|---|
| HTTP (PSR-15) | One per request | Put in DI as request-scoped |
| Long-running worker (RoadRunner, Swoole) | One per process | `$sharedRegistry` survives between messages — reset per tenant |
| Queue consumer | One per process | Same caveat |
| CLI / Cron | One per process | Trivial |

Never leak per-tenant data via `$sharedRegistry`. Fresh `MyApp` per tenant message when tenancy matters.

### 3. DomainResponse → transport

| Status | HTTP | CLI exit | Meaning |
|---|---|---|---|
| 200 | 200 OK | 0 | Success with data |
| 201 | 201 Created | 0 | Resource created |
| 204 | 204 No Content | 0 | Success, empty body |
| 400 | 400 Bad Request | 2 | Validation / business error |
| 401 | 401 Unauthorized | 2 | Auth missing |
| 403 | 403 Forbidden | 2 | Auth insufficient |
| 404 | 404 Not Found | 2 | Target absent |
| 409 | 409 Conflict | 2 | State conflict |
| 500 | 500 Internal | 1 | Exception escaped the pipeline |

Envelope from `getStatus()` / `getData()` / `getErrors()` / `getMetadata()`:

```json
{
  "status": 200,
  "data":   { "CounterContext": { "counterId": "018e..." } },
  "errors": { "CounterContext": [] },
  "meta":   { "duration_ms": 12, "version": "v1.0" }
}
```

Events (`getEvents()`) are dispatched server-side; include in the response only for debug/fire-hose APIs.

### 4. Transport patterns

**HTTP (PSR-15):**

```php
final class CreateCounterController implements RequestHandlerInterface
{
    public function __construct(private readonly MyApp $app) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body     = (array) json_decode((string) $request->getBody(), true);
        $dto      = new CommandCounter(name: $body['name'], obis: $body['obis']);
        $response = $this->app->counter()->counter()->command()->createCounter($dto);

        return (new JsonResponse($this->toEnvelope($response)))->withStatus($response->getStatus());
    }
}
```

**CLI (Symfony Console):**

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $dto      = new CommandCounter(name: $input->getArgument('name'));
    $response = $this->app->counter()->counter()->command()->createCounter($dto);
    $output->writeln(json_encode($response->getData(), JSON_PRETTY_PRINT));
    return match (true) {
        $response->isSuccess()        => 0,
        $response->getStatus() >= 500 => 1,
        default                       => 2,
    };
}
```

**Queue / Worker:**

```php
public function __invoke(CreateCounterMessage $msg): void
{
    $response = $this->app->counter()->counter()->command()->createCounter($msg->toDto());
    if ($response->getStatus() >= 500) throw new RecoverableException('infra error, retry');
    if (!$response->isSuccess())      throw new UnrecoverableException($response->getErrors());
}
```

### 5. Error handling

- 4xx business/validation errors → already in `DomainResponse::getErrors()`. Serialise, do not rethrow.
- Infrastructure exceptions → let them escape to framework middleware / CLI default handler. No bespoke envelope.
- Never `catch (\Throwable)` in the controller to build a custom error body.

### 6. Not in the transport layer

- Business validation → Command Steps
- DB transactions → Repository pipeline
- Event dispatch → handler
- Auth → framework middleware, before the Registry call
- Field mapping → FieldMap + `Hydrate*` Step

### 7. Anchors

- Api-Registry / V-rules: `platform-implementation` §1, §4
- `DomainResponse` / `ResponseStatus` / response pipeline: `core-kernel`
- ENV-driven `JardisApp` base: `core-foundation`
