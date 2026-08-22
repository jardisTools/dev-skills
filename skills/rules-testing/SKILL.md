---
name: rules-testing
description: Jardis testing rules — Integration over Unit, mock only at port boundaries, mandatory process for failing tests (no assertion weakening), Phase-3 test patterns for generated Domain code.
zone: crosscut
persona: C
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

The generated Domain facade (e.g. `MeterDevice`) is `final` and
JardisCore-free — it holds only the DomainKernel (`DomainKernelInterface $kernel`)
via constructor; `JardisApp`/`DomainApp` do not exist. It cannot be
subclassed — a "`TestMeterDevice extends MeterDevice`" idiom breaks at
compile time. Use **composition, not inheritance**: a plain test wrapper
class holds a real Domain-facade instance and delegates:

```php
// tests/Support/TestMeterDevice.php (or inline in the TestCase file) —
// NOT a subclass (MeterDevice is final) — holds the DomainKernel + a real
// MeterDevice instance, delegates its public accessors 1:1.
final class TestMeterDevice
{
    private DomainKernelInterface $kernel;
    private MeterDevice $domain;

    public function __construct(DomainKernelInterface $kernel)
    {
        $this->kernel = $kernel;
        $this->domain = new MeterDevice($kernel);
    }

    public function counter(): Counter { return $this->domain->counter(); }          // read chain, unchanged

    public function counterWrite(): CounterWriteHarness { return new CounterWriteHarness($this->kernel); }
}
```

**Write access needs a family-internal harness.** The BC accessor (`$app->counter()->counter()`) returns the read-only `{Agg}Read` facade — aggregate **commands are not reachable from a TestCase** (outside the Context-Familie). Tests that drive a command (arrange/seed or under test) go through a `{Bc}WriteHarness` in `tests/Support/` — a genuine subclass of the generated **BC** facade (BC facades are plain `class {BC} extends {Domain}Context`, **not** `final` — only the top-level Domain facade is), reaching the write facade over the inherited `protected` Kernel-Naht (no Reflection tricks; a subclass can call an inherited `protected` method). The harness is built directly from the DomainKernel (`new CounterWriteHarness($this->kernel)`), **not** via the test wrapper's `$this->handle(...)` — the wrapper above is not part of the Context-Familie (it extends nothing generated), so it has no `handle()` of its own to delegate through.

**A. Full 4-hop chain (integration)** — real Domain Facade, real DB from `make start`, schema reset in `setUp()`:

```php
// tests/Support/CounterWriteHarness.php — write facade via family-internal Kernel-Naht
// (genuine subclass of the generated, non-final BC facade — unaffected by the
// Domain facade's final-ification)
final class CounterWriteHarness extends Counter {   // the generated Counter BC class
    public function counterWrite(): CounterAggregate { return $this->handle(CounterAggregate::class); }
}

final class CreateCounterTest extends TestCase
{
    protected function createKernel(): DomainKernelInterface
    {
        // Build the DomainKernel manually in tests (no .env cascade in this context) —
        // see core-kernel for DomainKernel/Bootstrap-Packer details.
        return new DomainKernel(domainRoot: __DIR__, connection: self::$pdo);
    }

    protected function createDomain(): TestMeterDevice { return new TestMeterDevice($this->createKernel()); }

    public function testCreateCounterWithValidDataReturnsCreated(): void
    {
        $domain   = $this->createDomain();
        $response = $domain->counterWrite()->counterWrite()
            ->createCounter(new CommandCounter(name: 'M-1', obis: '1-0:1.8.0*255'));

        self::assertSame(201, $response->getStatus());
        self::assertArrayHasKey('identifier', $response->getData());
    }
}
```

Never assert SQL / PDO calls — assert the response and persisted state via a second query / `Get…` handler. Reads stay on the public chain: `$domain->counter()->getCounterById(...)` — the harness is for writes only.

**B. v2 override** — register v2 via a dedicated `ClassVersionConfig` on the DomainKernel's container (see `support-classversion`). Assert only the behaviour the override adds:

```php
public function testHydrateRejectsInvalidObis(): void
{
    $response = $this->appWithV2()->counterWrite()->counterWrite()
        ->createCounter(new CommandCounter(name: 'M-1', obis: 'not-an-obis'));

    self::assertSame(400, $response->getStatus());
    self::assertStringContainsString('Invalid OBIS', $response->getErrors()[0] ?? '');
}
```

Also test without v2 to prove the baseline is unchanged.

**C. Event emission** — a command handler **collects** its event, it does not dispatch. Assert on the response, not on a dispatcher listener:

```php
public function testCreateCounterCollectsCounterCreated(): void
{
    $response = $this->app->counterWrite()->counterWrite()->createCounter($dto);

    $events = array_merge(...array_values($response->getEvents(EventScope::Internal)));
    self::assertCount(1, $events);
    self::assertInstanceOf(CounterCreated::class, $events[0]);
    self::assertSame([], $response->getEvents(EventScope::Domain));   // Domain events arrive in Phase B
}
```

For **listener-side / transport** tests (a Process node publishing `$response->getEvents()` to Kafka etc.), register `EventCollector` (`jardisadapter/eventdispatcher` — see `adapter-eventdispatcher`) on the dispatcher the node publishes through, or substitute a `MessagingService` fake (test `ClassVersionConfig`) and assert `publish()` was called.

**D. Domain Service with external port** — provide a Fake implementing the Contract in `tests/Support/`, bind via test container. Service IPO test, no HTTP/DB:

```php
final class InMemoryHttpClient implements HttpClientInterface
{
    public function __construct(public readonly array $responses) {}
    public function get(string $url): array { return $this->responses[$url] ?? []; }
}
```

**E. Rule (Rules-Layer) — pure predicate PLUS endpoint integration, both mandatory.** A Rule (`{BC}/Rule/{Name}.php`) is a legitimate rare case for a Unit test — its `__invoke({Cmd}DTO): RuleResult` signature is a pure Ja/Nein predicate, and faking the read facade it calls is the whole point:

```php
final class CounterMustBeActiveTest extends TestCase
{
    public function testRejectsInactiveCounter(): void
    {
        $rule = new CounterMustBeActive(/* constructed against a Fake read facade returning an inactive Akte */);

        $result = $rule(new UpdateCounter(identifier: 'M-1'));

        self::assertFalse($result->passed());
        self::assertSame('counter.must_be_active', $result->messageKey());
    }
}
```

This Unit test does **not** replace an integration test — PRD A16 makes both mandatory. The **endpoint integration test** proves the whole chain: the Guard closure actually runs (short-circuit on the first rejection — assert an invocation count on a second Rule in the chain to prove it), a rejection surfaces as `RuleViolation` (422) with `{rule, messageKey, context}`, and — if the Command is exposed — that the exposed BC-facade method runs through the identical chain as the internal call. Never assert only the Unit test and call the Rule "covered" — the Guard wiring, the 422-response shape, and the exposed-door path are exactly what an isolated predicate test cannot see.

**Do not:**

- Mock `DomainKernelInterface` / the generated `{Domain}Context` (the former `BoundedContext`, now generated per domain — see `core-kernel`/`platform-implementation`) — too broad, leaks everywhere.
- Assert a command's events via a dispatcher listener — the handler collects, it doesn't dispatch; assert `$response->getEvents(EventScope::…)` instead. (`EventCollector` is for listener / transport-side tests only.)
- Test generated files directly — they are covered at the generator level. Test your overrides, custom Commands/Queries, Services.
- Test only a Rule's pure predicate and call it done — the endpoint-chain integration test (Guard wiring, 422 shape, short-circuit) is a separate, mandatory assertion surface (A16).
