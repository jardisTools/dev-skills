---
name: foundation-testing
description: Jardis testing rules — Integration over Unit, mock only at port boundaries, mandatory process for failing tests (no assertion weakening), Phase-3 test patterns for generated Domain code.
zone: crosscut
persona: C
profile: core
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
- [ ] Green via `make phpunit` after `make start`, no manual prep? (Generated app: unit tests via `make phpunit`, door tests in `tests/Integration/` via `make integration-test`.)
- [ ] Door test written by hand, `Schema.sqlite.sql` exported (`export_schema_sql_files`) before the first run?
- [ ] Failing test: §3 followed, assertions not weakened?

### 6. Testing generated Domain code (Phase 3)

Every build generates the test scaffold under `tests/Support/{Domain}/` (namespace `Tests\Support\{Domain}`). Jardis owns these files — ForceOverwrite, never hand-edited; hand-written files in the same folder (Fakes) stay untouched. Three kinds:

| File | Per | Purpose |
|---|---|---|
| `{Domain}TestCase.php` | domain | Abstract base: `createKernel()`, `createDomain()`, a fresh in-memory SQLite per `setUp()` |
| `Test{Domain}.php` | domain | Wrapper around the `final` Domain facade: `{bc}()` (read chain) and `{bc}Write()` (harness) per BC with an aggregate |
| `{BC}WriteHarness.php` | built BC with an aggregate | `{agg}Write()` per aggregate, over the inherited `protected` kernel seam |

A BC without an aggregate and a planned BC get nothing. Jardis generates no tests and no test data.

The generated Domain facade (e.g. `MeterDevice`) is `final` and JardisCore-free — it holds only the DomainKernel (`DomainKernelInterface $kernel`) via constructor; `JardisApp`/`DomainApp` do not exist. It cannot be subclassed — a "`TestMeterDevice extends MeterDevice`" idiom breaks at compile time. The scaffold uses **composition, not inheritance**: the wrapper holds a real Domain-facade instance and delegates.

```php
// tests/Support/MeterDevice/TestMeterDevice.php — generated shape. NOT a subclass
// (MeterDevice is final): holds the DomainKernel + a real MeterDevice, delegates 1:1.
final class TestMeterDevice
{
    private DomainKernelInterface $kernel;
    private MeterDevice $domain;

    public function __construct(DomainKernelInterface $kernel)
    {
        $this->kernel = $kernel;
        $this->domain = new MeterDevice($kernel);
    }

    public function counter(): Counter { return $this->domain->counter(); }          // read chain, one per BC

    public function counterWrite(): CounterWriteHarness { return new CounterWriteHarness($this->kernel); }
}
```

**Write access needs a family-internal harness.** The BC accessor (`$domain->counter()->counter()`) returns the read-only `{Agg}Read` facade — aggregate **commands are not reachable from a TestCase** (outside the Context family). Hence the generated `{BC}WriteHarness`: a genuine subclass of the generated **BC** facade (BC facades are plain `class {BC} extends {Domain}Context`, **not** `final` — only the top-level Domain facade is), reaching the write facade over the inherited `protected` kernel seam (no Reflection tricks). The wrapper builds it directly from the DomainKernel (`new CounterWriteHarness($this->kernel)`); it has no `handle()` of its own — it extends nothing generated.

```php
// tests/Support/MeterDevice/CounterWriteHarness.php — generated shape
final class CounterWriteHarness extends Counter {   // the generated Counter BC class
    public function counterWrite(): CounterAggregate { return $this->handle(CounterAggregate::class); }
}
```

**Door tests are hand-written.** The AI writes the tests at the door — reads via `{Agg}Read` over `Test{Domain}`, commands via `{BC}WriteHarness`, persistence proven by a second read — under `tests/Integration/{Domain}/{BC}/`. In the app template `make phpunit` runs only `tests/Unit`; door tests run with `make integration-test`.

**A. Full 4-hop chain (integration)** — real Domain facade on a real database. The generated base opens a fresh `new PDO('sqlite::memory:')` in every `setUp()`, imports `<projectRoot>/.jardis/{Domain}/*/{BC}/Schema.sqlite.sql` for each built BC, and builds `new DomainKernel(projectRoot: …, connection: $pdo)`. `projectRoot` is the project root (three levels above `tests/Support/{Domain}/`), not the test's `__DIR__`. No static `$pdo`, no `setUpBeforeClass`; the project's `.env` is never read. Call MCP tool `export_schema_sql_files` (dialect `sqlite`) once before the first test; a missing file aborts the test loudly and names that tool — no skip. Green on SQLite is not green on the production engine (`FULL JOIN`, `unsigned`, `SQLITE_BUSY`).

```php
// tests/Integration/MeterDevice/Counter/CreateCounterTest.php — hand-written door test
final class CreateCounterTest extends MeterDeviceTestCase   // generated base
{
    public function testCreateCounterWithValidDataReturnsCreated(): void
    {
        $domain   = $this->createDomain();
        $response = $domain->counterWrite()->counterWrite()
            ->createCounter(new CommandCounter(name: 'M-1', obis: '1-0:1.8.0*255'));

        // family-internal Create handler: 201; `data` is flat, typed by `@type`
        self::assertSame(201, $response->getStatus());
        self::assertSame('counter', $response->getData()['@type']);
        self::assertArrayHasKey('counterIdentifier', $response->getData());
    }

    public function testCreateCounterProcessAnswers200WithReferenceValue(): void
    {
        $response = $this->createDomain()->counter()->process()->createCounter(new CreateCounter(/* … */));

        self::assertSame(200, $response->getStatus());   // a Process answers 200, never 201
        self::assertSame('counter', $response->getData()['@type']);
        self::assertArrayHasKey('counterIdentifier', $response->getData());
    }
}
```

`getData()` is the flat business object — read `getData()['<field>']` directly; the 400 / 409 / 422 answers carry `@type` `validation` (`fields[]={field, reason, message}`) / `concurrencyConflict` / `ruleViolation`. `getErrors()` is context-keyed (`array<string, list<string>>`); on the wire it is an object, `{}` when empty.

Never assert SQL / PDO calls — assert the response and the persisted state via a second read: `$domain->counter()->counter()->getCounterById(...)`. Reads stay on the public chain; the harness is for writes only.

**B. v2 override** — register v2 via a dedicated `ClassVersionConfig` on the DomainKernel's container (see `support-classversion`). Assert only the behaviour the override adds:

```php
public function testHydrateRejectsInvalidObis(): void
{
    $response = $this->appWithV2()->counterWrite()->counterWrite()
        ->createCounter(new CommandCounter(name: 'M-1', obis: 'not-an-obis'));

    self::assertSame(400, $response->getStatus());
    $messages = array_merge(...array_values($response->getErrors()));   // errors are context-keyed
    self::assertStringContainsString('Invalid OBIS', $messages[0] ?? '');
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
    self::assertSame([], $response->getEvents(EventScope::Domain));   // an aggregate command collects Internal events only; a Domain event is announced by an Event ◇ node of a Process
}
```

For a Process with an **Event ◇ node**, assert the announced event on the process response: `array_merge(...array_values($response->getEvents(EventScope::Domain)))` — the node carries it in the reserved channel `data['__jardis']['events']`, the orchestrator collects it as a Domain event, and the identity inside the event is read flat from the preceding node's `getData()`. For **listener-side / transport** tests (a Process node publishing the events of the command it called to Kafka etc.), register `EventCollector` (`jardisadapter/eventdispatcher` — see `adapter-eventdispatcher`) on the dispatcher the node publishes through, or substitute a `MessagingService` fake (test `ClassVersionConfig`) and assert `publish()` was called.

**D. Domain Service with external port** — provide a Fake implementing the Contract in `tests/Support/`, bind via test container. Service IPO test, no HTTP/DB:

```php
final class InMemoryHttpClient implements HttpClientInterface
{
    public function __construct(public readonly array $responses) {}
    public function get(string $url): array { return $this->responses[$url] ?? []; }
}
```

**E. Rule (Rules-Layer) — pure predicate PLUS endpoint integration, both mandatory.** A Rule (`{BC}/Closure/{Name}.php`) is a legitimate rare case for a Unit test — its `__invoke({Cmd}DTO): RuleResult` signature is a pure yes/no predicate, and faking the read facade it calls is the whole point:

```php
final class CounterMustBeActiveTest extends TestCase
{
    public function testRejectsInactiveCounter(): void
    {
        $rule = new CounterMustBeActive(/* constructed against a Fake read facade returning an inactive aggregate record */);

        $result = $rule(new UpdateCounter(identifier: 'M-1'));

        self::assertFalse($result->passed());
        self::assertSame('counter.must_be_active', $result->messageKey());
    }
}
```

This Unit test does **not** replace an integration test — PRD A16 makes both mandatory. The **endpoint integration test** proves the whole chain: the Guard closure actually runs (short-circuit on the first rejection — assert an invocation count on a second Rule in the chain to prove it), a rejection surfaces as `RuleViolation` (422) with `data` = `{"@type":"ruleViolation","rule","messageKey","context"}`, and — if the Command is exposed — that the exposed BC-facade method runs through the identical chain as the internal call. Never assert only the Unit test and call the Rule "covered" — the Guard wiring, the 422-response shape, and the exposed-door path are exactly what an isolated predicate test cannot see.

**Do not:**

- Mock `DomainKernelInterface` / the generated `{Domain}Context` (the former `BoundedContext`, now generated per domain — see `core-kernel`/`generated-code-extend`) — too broad, leaks everywhere.
- Assert a command's events via a dispatcher listener — the handler collects, it doesn't dispatch; assert `$response->getEvents(EventScope::…)` instead. (`EventCollector` is for listener / transport-side tests only.)
- Hand-edit `tests/Support/{Domain}/{Domain}TestCase.php`, `Test{Domain}.php` or `{BC}WriteHarness.php` — every build overwrites them. Put Fakes and helpers in their own files next to them.
- Test generated files directly — they are covered at the generator level. Test your overrides, custom Commands/Queries, Services.
- Test only a Rule's pure predicate and call it done — the endpoint-chain integration test (Guard wiring, 422 shape, short-circuit) is a separate, mandatory assertion surface (A16).
