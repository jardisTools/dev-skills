#### Continuation §4 — transport examples

> **Process input DTO name:** the examples below assume a Process `CreateCounter` was modelled around the `Counter` aggregate's `createCounter` command (`generated-code-extend` §2/§4) — its input DTO lives at `{BC}/Process/CreateCounter/Command/CreateCounter.php` (namespace `…\Process\CreateCounter\Command`), distinct from the aggregate's own Command DTO of the same simple name. Constructor fields like `name`/`obis` are **illustrative**, domain-specific to this example — not a fixed API; the real fields come from your process's own `input.fields`.

Imports of the examples (Domain `MeterDevice`, BC `Counter`; `JsonResponse` stands for any PSR-7 JSON response of your framework, e.g. `Laminas\Diactoros\Response\JsonResponse`):

```php
use MeterDevice\MeterDevice as MyApp;                                   // the final Domain facade
use MeterDevice\Counter\Process\CreateCounter\Command\CreateCounter;     // process input DTO
use MeterDevice\Counter\Model\Counter\Query\CounterById;                 // read DTO (real class name, no alias)
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Console\Input\InputInterface;                    // CLI example
use Symfony\Component\Console\Output\OutputInterface;
```

**HTTP (PSR-15) — write via process():**

```php
final class CreateCounterController implements RequestHandlerInterface
{
    public function __construct(private readonly MyApp $app) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body     = (array) json_decode((string) $request->getBody(), true);
        $dto      = new CreateCounter(name: $body['name'], obis: $body['obis']);
        $response = $this->app->counter()->process()->createCounter($dto);   // write — the aggregate write facade is not reachable from here (the BC facade has no write accessor)

        // Envelope is your app's mapper — build it inline from the DomainResponse getters:
        return (new JsonResponse([
            'status' => $response->getStatus(),
            'data'   => $response->getData() ?: new \stdClass(),      // flat business object, typed by `@type`
            'errors' => $response->getErrors() ?: new \stdClass(),    // object {<context>: [message]}, never []
            'meta'   => $response->getMetadata(),
        ]))->withStatus($response->getStatus());
    }
}
```

**HTTP (PSR-15) — read via the aggregate's read facade:**

```php
final class GetCounterController implements RequestHandlerInterface
{
    public function __construct(private readonly MyApp $app) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id       = (int) $request->getAttribute('id');
        // aggregate WITHOUT a public unique key: getCounterById; WITH one: getCounterByIdentifier (no ById on the read facade)
        $response = $this->app->counter()->counter()->getCounterById(new CounterById(id: $id));

        return (new JsonResponse([
            'status' => $response->getStatus(),
            'data'   => $response->getData() ?: new \stdClass(),      // flat business object, typed by `@type`
            'errors' => $response->getErrors() ?: new \stdClass(),    // object {<context>: [message]}, never []
            'meta'   => $response->getMetadata(),
        ]))->withStatus($response->getStatus());
    }
}
```

**CLI (Symfony Console):**

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $dto      = new CreateCounter(name: $input->getArgument('name'));
    $response = $this->app->counter()->process()->createCounter($dto);   // write via process()
    $output->writeln(json_encode($response->getData(), JSON_PRETTY_PRINT));
    return match (true) {
        $response->isSuccess()        => 0,
        $response->getStatus() >= 500 => 1,
        default                       => 2,
    };
}
```

**Queue / Worker** (`RecoverableException` / `UnrecoverableException` stand for the retry / no-retry exceptions of your queue library, e.g. Symfony Messenger's `RecoverableMessageHandlingException` / `UnrecoverableMessageHandlingException`):

```php
public function __invoke(CreateCounterMessage $msg): void
{
    $response = $this->app->counter()->process()->createCounter($msg->toDto());   // write via process()
    if ($response->getStatus() >= 500) throw new RecoverableException('infra error, retry');
    if (!$response->isSuccess())      throw new UnrecoverableException(json_encode($response->getData()));   // a 4xx cause lives in data (fields[] / messageKey / context); errors may be empty
}
```

