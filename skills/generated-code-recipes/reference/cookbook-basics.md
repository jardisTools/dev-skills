### 2. Phase-3 cookbook

Contents: Recipe 1 — validating a domain concept · Recipe 2 — call to another system (External-Call node) · Recipe 2a — mail from a hand body · Recipe 3 — new operation: aggregate command/query vs. Process · Recipe 4 — event to Kafka · Recipe 5 — a Process input is self-contained.

Paths assume BC `MeterDevice\Counter`, aggregate `Counter`. Aggregate code lives under `{BC}/Model/{Agg}/` (hermetic, never edited); **hand code stands only at the places of the table in `generated-code-extend` §10** — node bodies in `{BC}/Process/<Name>/Command/Handler/Action/` (`@node-id` body-preserve), Closure/Rule bodies in `{BC}/Closure/`, Domain Services in `{BC}/Service/`, delivery code in `App/**`.

**Recipe 1 — Validating a domain concept (in a Process)**

The aggregate's generated Hydrate/Build pipeline is hermetic. A value is checked by the model first: column type, value list and length in the Schema, the process input type. A numeric bound beyond `unsigned` and `precision` (a minimum, a maximum, a range) is not a model setting either. A pattern the model has no setting for (an OBIS code, an IBAN) is a **Closure**: declare a catalog entry with a scalar input and the verdict output (Closure editor or MCP `save_closures`), drop it into the Process as a Rule node, and write the check into the stub body. No free value-object class exists (`generated-code-extend` §10).

Rule stub `{BC}/Closure/ObisCodeIsValid.php` (frame generated, body yours):

```php
namespace MeterDevice\Counter\Closure;

use JardisSupport\Response\Data\RuleResult;
use MeterDevice\MeterDeviceContext;

final class ObisCodeIsValid extends MeterDeviceContext
{
    public function __invoke(string $obis): RuleResult
    {
        return preg_match('/^\d+-\d+:\d+\.\d+\.\d+\*\d+$/', $obis) === 1
            ? RuleResult::pass()
            : RuleResult::reject(rule: self::class, messageKey: 'counter.obisInvalid', context: ['obis' => $obis]);
    }
}
```

In the Process the Rule node binds the input with the long form `ruleArgs: [{"param": "obis", "source": "obis"}]` (Recipe 10): a violation is `reject(ResponseStatus::RuleViolation, …)` — 422 with the catalog `messageKey` `counter.obisInvalid`, the run ends and rolls back (`reject` is not a route); a passed check takes `Route::OnTrue`. Without a catalog `messageKey` the body's key passes through. For a tenant-specific variant of the Rule see `Closure/v{N}/` (`generated-code-versioning` §1).

**Recipe 2 — Call to another system, read or write (External-Call node, Domain Service)**

Model an **External-Call** node in the Process Designer. The target is a system outside your domain — a service, or **another Jardis domain**. A Cross-BC-Call node reaches only BCs of your own domain; a node that names a target of another domain is the blocker `V-XDOMAIN-TARGET`, because a process never calls another domain in-process. The generator emits the Service stub `{BC}/Service/SendInvoiceToLedger.php` (extends the Domain Context, header-aware merge, `generated-code-extend` §1) with a null-guarded `$this->resource()->http()` scaffold and a docblock that names the answer path and the failure contract; the node body delegates to it (`return $this->handle(SendInvoiceToLedger::class)->__invoke($context);`). Your part is the body of the Service's `__invoke()`: send the request, read the answer, return a `NodeResult`. V9: the Service writes nothing in its own BC — the write goes to the other system.

**Call or event?** Only your own BCs are under your control; the other system is not. Choose by what you want from it:

| You want to … | Use | Why |
|---|---|---|
| Read from the other system | External-Call node | Harmless: nothing to take back. |
| Tell it that something happened | An Event: announced by the Event ◇ node, published by the delivery code after the commit (Recipe 4) | Preferred for every effect on another system. A run that ends in a refusal or fails announces no event, so nothing is published and there is nothing to take back there; you need not know the other system's API. |
| Write at it through a call | External-Call node, only when it cannot receive events | You know its API. The step that takes the write back (compensation) is a node you model yourself; the tool does not do it for you, and what the other system wrote stays (see "What the call does not give you." below). |

This skill states no delivery guarantee for the publishing itself.

1. **Model the refusals.** List every status of the other system that is a refusal of your node — 400, 404, 409, 422 — in the node's `rejects[]`, each with its message key — `V-NODE-REJECT-UNDECLARED` does not see a status that is only mapped by `MapExternalResponse`, so keep the list complete yourself — and draw the `onFalse` edge (and `onTimeout` when the body routes there); an undeclared route is a 500.
2. **Send through the kernel.** `$this->resource()->http()` is the `HttpServiceInterface` of the kernel (`get`/`delete`/`head` take `uri, headers`; `post`/`put`/`patch` take `uri, data, headers`, `data` goes out as JSON). It returns a PSR-7 response: a 4xx or 5xx is a response, only a failed transport throws `ClientExceptionInterface`. `null` means no service is configured — throw. No `JardisAdapter\…` import, no `new` client (V2 / V3).
3. **Read the answer** with `DecodeAnswer` (`jardissupport/response`): body text and status code in, a `DomainResponseInterface` out. A body that is no Jardis answer throws `NoJardisAnswerException`.
4. **Map it** with `MapExternalResponse` (`jardissupport/workflow`), not with `MapResponse`: `MapResponse` would hand a 401 or 403 of the other system to your caller as a refusal.
5. **Two ways of failure, never mixed.** 2xx, 400, 404, 409 and 422 of the other system are the answer of your node (2xx `ok`, the four refusals `reject` with the same status and the context of the other system). 401, 403, 405, every 5xx and every status outside the response form throw `ExternalFailureException`: a technical failure, the door logs it and answers 500; no route takes it. A transport failure and an answer that is no Jardis answer are the no-way: `Route::OnFalse`; a timeout is `Route::OnTimeout`. The body translates both, the mapping does not.

```php
final class SendInvoiceToLedger extends ShopContext
{
    private const URL = 'https://ledger.example.test/entries';

    public function __invoke(ProcessContext $context): NodeResult
    {
        /** @var SettleInvoice $cmd */
        $cmd = $context->input();
        $http = $this->resource()->http()
            ?? throw new \RuntimeException('The kernel has no http() service: ' . self::class);

        try {
            $reply = $http->post(self::URL, ['invoiceNumber' => $cmd->invoiceNumber, 'amount' => $cmd->amount]);
            $answer = (new DecodeAnswer())((string) $reply->getBody(), $reply->getStatusCode());
        } catch (ClientExceptionInterface | NoJardisAnswerException) {
            return NodeResult::route(Route::OnFalse);
        }

        return (new MapExternalResponse())($answer);
    }
}
```

The imports are `JardisSupport\Response\Handler\Response\DecodeAnswer`, `JardisSupport\Response\Exception\NoJardisAnswerException`, `JardisSupport\Workflow\Handler\Node\MapExternalResponse`, `JardisSupport\Workflow\Data\{NodeResult, ProcessContext, Route}` and `Psr\Http\Client\ClientExceptionInterface`. To carry on after a refusal instead of ending the run, pass the route as second argument: `(new MapExternalResponse())($answer, Route::OnFalse)` — it acts on the four refusals only, never on `ExternalFailureException`.

**What the call does not give you.** The other system is not part of your transaction bracket: it writes on its own connection, so a refusal that rolls your run back leaves what it wrote. Retry is the `HttpClient`'s, not the body's: it repeats on 5xx and network errors up to `maxRetries` (`HTTP_MAX_RETRIES`, default 0 = none), and the body holds no retry loop. This recipe states no idempotency guarantee — neither the stub nor the `HttpClient` documents one; a retry sends the request again, and whether that is safe is the contract of the other system. The client is described in `adapter-http`, the answer reading in `support-response`, the mapping in `support-workflow`.

**Recipe 2a — Mail from a hand body**

A node body or a Domain Service sends mail through the kernel service `mail()` (`MailServiceInterface`), the same way it sends HTTP — no `JardisAdapter\…` import:

```php
$mail = $this->resource()->mail()
    ?? throw new \RuntimeException('The kernel has no mail() service: ' . self::class);
$mail->sendMail(
    to: $cmd->recipientEmail,                        // string | list<string>
    subject: 'Invoice ' . $cmd->invoiceNumber,
    text: 'Your invoice is ready.',                  // text and/or html
    html: null,
    options: ['from' => 'billing@example.test'],     // from, fromName, cc, bcc, replyTo, headers
);
```

`mail()` is `null` when the project has no mailer adapter or no `MAIL_HOST`: a missing service is a configuration error, so the body throws (a technical failure, the door answers 500) and does not skip the mail silently. Neither text nor HTML, an unknown option key, or `fromName` without `from` throws `\InvalidArgumentException`; an absent `from` falls back to the configured sender; a failed transfer throws `MailerExceptionInterface` — let it pass (500), or catch it and return `NodeResult::route(Route::OnFalse)` with an `onFalse` edge on the node. Messages with attachments or inline images use `mailer()` instead (`adapter-mailer`).

**Recipe 3 — A new operation: aggregate command/query vs. BC-level Process**

There is no hand-edit slot on the aggregate; the route depends on *what kind* of operation it is.

**Case A — a new aggregate command or query** (mutates/reads this one aggregate's state). Re-model it in the **Aggregate Designer** (`Aggregate.json`) and rebuild. The Generator regenerates the Command/Query DTO + handler + validator tree and exposes the operation **inline on the matching facade** — a query on the read facade `{Agg}Read.php` (the BC facade's public surface), a command on the write facade `{Agg}.php` (family-internal only):

```php
$bc->counter()->getCounterByIdentifier($dto);               // query — generated, reached via $bc->{agg}() (public surface)
$this->handle(Counter::class)->deactivateCounter($dto);     // command — generated, family-internal via the kernel seam
```

No method is hand-written and nothing under `{Agg}/` is edited — both facades are fully regenerated (no `@flow-id`, no body merge). A new command has **no** external caller by itself (row `closed` by default): open its row in `Doors.json` (`domain` = facade door `{BC}::{command}()`, `outside` = route + right) for a single guarded command, or wrap it in a Process (Case B) to expose it via `$bc->process()`.

**Case B — a BC-level operation or one that coordinates several aggregates.** Model a **Process** in the Process Designer. The Generator emits, under `{BC}/Process/<Name>/`:

1. `Command/<Name>.php` — the self-contained input DTO (`readonly`, its own `input.fields` — Recipe 5). Namespace: `…\Process\<Name>\Command\<Name>`.
2. `Command/Handler/<Name>Handler.php` — the door that starts the run (`ValidateInput`, `RunOwnedTransaction` around `ProcessRunner::run(...)`, answer closures of `response`; the graph is its private `definition()`), next to the input gate `<Name>Validator.php` (only when the input carries a rule), the typed run view `<Name>Context.php` (one getter per node) and one object-value class `<Name><Value>.php` per `gives` with fields (tenant and user of the signed-in user are the fields `tenant`/`user` of the process input, read as `$ctx->input()->tenant`; no process class of its own for them) — `generated-code-workflow-api` §1/§6. ForceOverwrite — regenerated, not edited. Namespace: `…\Process\<Name>\Command\Handler\<Name>Handler`.
3. `Command/Handler/Action/<NodeClass>.php` — one node stub per node. Custom nodes are `CreateIfNotExists` with an `@node-id` marker; **the body is yours** and survives rebuilds. Namespace: `…\Process\<Name>\Command\Handler\Action\<NodeClass>`.
4. A thin-dispatch method on the hermetic `{BC}Process` facade: `return $this->context(<Name>Handler::class, $in, $version)();` — reached via `$bc->process()->{name}($dto)`.

Hand code in a process stands only in the custom-node bodies; a Domain Service lives at `{BC}/Service/` (`generated-code-extend` §10).

Your logic lives in the custom-node bodies. The process has **no aggregate ownership** and invokes whichever aggregate commands/queries it needs via `$this->context(...)` / `$this->handle(...)` from a node.

**Recipe 4 — Event to Kafka (end-to-end)**

A concrete instance of §1. Model a Process whose nodes call the aggregate command and then announce the Domain event with an Event ◇ node (generated, no body to write); the delivery code in `App/**` publishes it after the commit:

```php
// Process CreateCounter: node 1 calls the aggregate command (generated default body),
//                        node 2 is the Event ◇ node `counterCreated` — NodeResult::route(OnTrue)->withEvents(new CounterCreated(...))

// App/** — delivery code, no business logic
$response = $app->counter()->process()->createCounter($dto);

if ($response->isSuccess()) {
    foreach (array_merge(...array_values($response->getEvents(EventScope::Domain))) as $event) {
        $kernel->messaging()?->publish('meterdevice.counter.counter.created', $event);
    }
}
```

Test via the `EventCollector` fake (see `foundation-testing` §6) plus a `MessagingService` fake asserting the published payload. Never edit the hermetic `<Agg>EventRouter` (§1).

**Recipe 5 — A Process input is self-contained**

A Process DTO declares **its own** input and never extends an aggregate Command/Query DTO. The former `input.extends: platform:<Name>` reference was **removed** in the Flow→BC refactor (a process has no aggregate ownership). The Designer carries the input as `input.fields` with Option-1.5 type syntax (`?type` = nullable, `= <literal>` = default), and the Generator materialises a standalone `readonly` DTO:

```php
namespace MeterDevice\Counter\Process\CounterChange;

readonly class CounterChange
{
    public function __construct(
        public string $identifier,
        public int $newValue,
        public ?string $note = null,
    ) {}
}
```

**Input types are the scalar vocabulary of the aggregate door**, not a poorer one: `string`/`int`/`float`/`bool`, `date`, `datetime`, `time`, `decimal`, `uuid`, … (the backend lists them: process load answer `inputTypes`, MCP resource `jardis://process/…`; `save_process` `input.fields[].type`) plus the legacy words `string|array|mixed`, plus a value list as `valueList: <Name>` (instead of `type`; becomes an `enum` in the OpenAPI request in the list's declared order). The DTO type, the route and the OpenAPI schema follow the type (`date` → `DateTimeImmutable`, `format: date|uuid|decimal`), and the process gate checks the value like the aggregate door does: `date` → calendar-strict `Y-m-d`, `datetime` → RFC 3339 normalised to UTC, `time` → `H:i:s`; a violation is `400` `fields[]` with `reason type` before the first node. A field typed `array` or `mixed` carries no scalar shape and warns (`V-INPUT-UNTYPED`); a `valueList` that does not exist in the BC is a blocker (`V-INPUT-VALUELIST`).

**Lists and objects as process input.** `list: true` turns a scalar field into a typed list (`{name: "codes", type: "varchar", list: true}` → `public array $codes` with `@param list<string>`); `fields: [...]` makes an object (`{name: "address", fields: [{name: "city", type: "varchar"}]}` → item class `{Process}AddressItem` under `Command/`), `fields` + `list: true` an object list; objects nest at most two levels. A list has no minimum length (`[]` is valid; a required minimum is a Rule answering 422); a missing list is a 400 `missing`, a nullable list may be absent (`null`). Every element is checked at the route and at the gate before the first node: a wrong element answers 400 with its index path (`lines[1].quantity`), never 500. In a node body you read the typed tree directly (`foreach ($cmd->lines as $line) { $line->discount?->percent; }`).

If a process needs data that lives on an aggregate, it does **not** inherit a DTO — it **runs the aggregate query/command** from a node (`$this->context(...)`) and reads the response. There is no merged-DTO / `platform:` parent concept; an unknown field in `input.fields` is a hard build error (no silent fallback).
