---
name: rules-testing
description: Jardis testing rules — Integration over Unit, mock only at port boundaries, mandatory process for failing tests (no assertion weakening), Phase-3 test patterns for generated Domain code.
zone: crosscut
prerequisites: []
next: []
---

### 1. Principle

Tests assert behaviour at the package boundary, not internal implementation. A refactor that preserves behaviour must not require test changes.

- **Integration** = default. Real dependencies via Docker.
- **Unit** = fallback when the unit has no outside world (VO, pure formatter, parser without I/O).
- Unit test needing 3+ Mocks = bad Integration test in disguise.

### 2. Rules

| Concern | Rule |
|---|---|
| Mapping | `src/Cache/RedisCache.php` → `tests/Integration/Cache/RedisCacheTest.php` |
| Naming | Class `RedisCacheTest`, method `test{Action}{Condition}{ExpectedResult}` |
| Structure | AAA separated, one concept per test |
| Independence | No `setUpBeforeClass` side effects, no static cache, no `@depends` |
| Behaviour | Assert outputs and side effects, never SQL strings or private calls |
| Mocking | Only Contracts (interfaces). Prefer Fakes in `tests/Support/` |

```php
// tests/Support/InMemoryCache.php
final class InMemoryCache implements CacheInterface
{
    private array $data = [];
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set(string $key, mixed $value, int|null $ttl = null): bool { $this->data[$key] = $value; return true; }
}
```

### 3. Failing-test process

Before changing test or code:

1. What SHOULD happen? Derive from architecture, PRD, Contract.
2. What ACTUALLY happens? Debug output, read involved code.
3. Decide:
   - Behaviour correct → adapt test, comment why old expectation was wrong.
   - Behaviour wrong → fix bug, test stays.

**Forbidden:**

- Weakening assertions (`assertSame` → `assertInstanceOf`, etc.) to go green.
- Removing inconvenient assertions.
- Adapting test to observed behaviour without understanding why.
- `markTestSkipped` / `markTestIncomplete` as permanent fix (only temporary with ticket reference).

Green test asserting wrong behaviour is worse than red test.

### 4. Docker services

External dependencies are part of the package. Canonical templates → `docker-compose.yml`; ENV vars → `.env.example`. `make start` brings up everything; `make phpunit` runs with no manual prep. A test green only because Redis happens to run on the developer's laptop is broken.

### 5. Checklist

- [ ] Integration (preferred) or Unit (with docblock justification)?
- [ ] Mirror path?
- [ ] Name `test{Action}{Condition}{ExpectedResult}`?
- [ ] AAA separated? No shared state? No `@depends`?
- [ ] Mocked only the interface — Fake preferred?
- [ ] Docker service for every external dep?
- [ ] Green via `make phpunit` after `make start`, no manual prep?
- [ ] Failing test: §3 followed, assertions not weakened?

### 6. Testing generated Domain code (Phase 3)

Assume `MyApp extends JardisApp|DomainApp`, bootstrapped per test.

**A. Full 4-hop chain (integration)** — real Domain Facade, real DB from `make start`, schema reset in `setUp()`:

```php
final class CreateCounterTest extends TestCase
{
    protected function setUp(): void { $this->app = new MeterDevice(); /* + schema reset */ }

    public function testCreateCounterWithValidDataReturnsCreated(): void
    {
        $response = $this->app->counter()->counter()->command()
            ->createCounter(new CommandCounter(name: 'M-1', obis: '1-0:1.8.0*255'));

        self::assertSame(201, $response->getStatus());
        self::assertArrayHasKey('counterIdentifier', $response->getData());
    }
}
```

Never assert SQL / PDO calls — assert the response and persisted state via a second query / `Get…` handler.

**B. v2 override** — register v2 in the test `DomainApp` via a dedicated `ClassVersionConfig`. Assert only the behaviour the override adds:

```php
public function testHydrateRejectsInvalidObis(): void
{
    $response = $this->appWithV2()->counter()->counter()->command()
        ->createCounter(new CommandCounter(name: 'M-1', obis: 'not-an-obis'));

    self::assertSame(400, $response->getStatus());
    self::assertStringContainsString('Invalid OBIS', $response->getErrors()[0] ?? '');
}
```

Also test without v2 to prove the baseline is unchanged.

**C. Event emission** — do not mock `EventDispatcherInterface`. Register `EventCollector` (`jardisadapter/eventdispatcher`), run the command, assert:

```php
public function testCreateCounterEmitsCounterCreated(): void
{
    $collector = new EventCollector();
    $this->app->registerListener(CounterCreated::class, $collector);

    $this->app->counter()->counter()->command()->createCounter($dto);

    self::assertCount(1, $collector->eventsOf(CounterCreated::class));
}
```

For listener-side tests (EventRouter → Kafka), substitute a `MessagingService` fake via test `ClassVersionConfig` and assert `publish()` was called.

**D. Domain Service with external port** — provide a Fake implementing the Contract in `tests/Support/`, bind via test container. Service IPO test, no HTTP/DB:

```php
final class InMemoryHttpClient implements HttpClientInterface
{
    public function __construct(public readonly array $responses) {}
    public function get(string $url): array { return $this->responses[$url] ?? []; }
}
```

**Do not:**

- Mock `DomainKernelInterface` / `BoundedContext` — too broad, leaks everywhere.
- Assert event dispatch by peeking private listener properties — use `EventCollector`.
- Test generated files directly — covered by the Builder. Test your overrides, custom Commands/Queries, Services.
