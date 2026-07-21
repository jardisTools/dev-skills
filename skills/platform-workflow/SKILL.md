---
name: platform-workflow
description: Workflow-Engine API used by Process-Designer-generated Use-Case orchestrators — seven routing statuses (`ON_SUCCESS` / `ON_FAIL` / `ON_TIMEOUT` / `ON_SKIP` / `ON_CANCEL` / `ON_EVENT` / `ON_EXIT`), `WorkflowConfig`/`addNode` graph construction, the Event-Kasten ◇ node variant, `handlerFactory` Closure conventions, three opaque `WorkflowContext` slots (`reference`, `response`, `exception`), R5 routing-safety rules.
zone: post-active
persona: C
prerequisites: [platform-implementation]
next: []
---

The Process-Designer-Orchestrator (`<Name>Handler.php`, generated under `{BC}/Process/{Name}/`) wires a `WorkflowConfig` and calls `$workflow($config, $dto)`; the Engine walks the graph, invokes each Node-Action-Stub `__invoke(WorkflowContextInterface): WorkflowResultInterface`, and stamps every result with its producing handler FQCN. Knowing this API is mandatory both for Node bodies (whose editable `logic()` returns an `array{status, data}` that the generated `__invoke` wraps into a `WorkflowResult`) and for hand-written Orchestrator-Mantel-Code.

### 1. The seven routing statuses

The editable node body is `protected function logic($cmd, WorkflowContextInterface $context): array` and returns exactly one of these as `['status' => WorkflowResult::ON_*, 'data' => [...]]` (same shape as the Event-Kasten below); the generated `__invoke` wraps that into a `WorkflowResult` automatically — never construct `WorkflowResult` inside the `logic()` body:

| Konstante | Bedeutung |
|---|---|
| `ON_SUCCESS` | Erfolgreicher Abschluss des Handlers — Default-Happy-Path. |
| `ON_FAIL` | Fachlicher Misserfolg (Validierung, Geschaeftsregel verletzt). |
| `ON_TIMEOUT` | Geplanter Recovery-Pfad: Service-Side-Timeout in fachliches Routing uebersetzt. |
| `ON_SKIP` | Handler nicht anwendbar — Flow ueberspringt zum Re-Konvergenz-Punkt. |
| `ON_CANCEL` | Fachlicher Abbruch (Stornierung, Zustimmung zurueckgezogen) — Cleanup-Pfad. |
| `ON_EVENT` | Aktiver async-Hand-off via DomainEvent — Folge-Runs entstehen extern (kein synchroner Folge-Node). |
| `ON_EXIT` | Schleifen-/Block-Terminierung — beendet eine Schleife bzw. einen Block aktiv (kein synchroner Folge-Node). |

User-Code laesst `handlerFqcn` immer `null` — die Engine stamped es via `WorkflowResult::withHandler()` selbst, sobald sie das Ergebnis in den Context anhaengt.

### 2. `WorkflowConfig` im `config()`-Body

Der Generator emittiert eine private `config(): WorkflowConfigInterface`, die **jeden** Knoten des gemalten Graphen via `addNode()` registriert (Start zuerst, in topologischer Reihenfolge; ein End-Knoten als `addNode(X::class, [])`). Routing ist eine `[WorkflowResult::ON_* => NextNode::class]`-Map pro Knoten — die Engine laeuft die gemalten Kanten, exklusive Zweige laufen exklusiv. Hand-edits am Orchestrator (z.B. zusaetzliche Transition) folgen demselben Muster:

```php
private function config(): WorkflowConfigInterface
{
    return (new WorkflowConfig())
        ->addNode(ValidateInput::class, [
            WorkflowResult::ON_SUCCESS => LoadAggregate::class,
            WorkflowResult::ON_FAIL    => RejectInput::class,
        ])
        ->addNode(LoadAggregate::class, [
            WorkflowResult::ON_SUCCESS => MutateState::class,
            WorkflowResult::ON_SKIP    => RejectInput::class,
        ])
        ->addNode(MutateState::class, [
            WorkflowResult::ON_SUCCESS => PersistAndEmit::class,
            WorkflowResult::ON_CANCEL  => CompensateState::class,
        ])
        ->addNode(PersistAndEmit::class, [])
        ->addNode(RejectInput::class, [])
        ->addNode(CompensateState::class, []);
}
```

End-Knoten (leere Routing-Map `[]`) lassen die Engine ordentlich beenden.

### Event-Kasten ◇ (Event-Knoten)

Ein Designer-Knoten kann statt **Action** als **Event ◇** markiert sein (`mode: async`). Im Designer deklariert der Autor am Knoten eine **Event-Feld-Bindung** — eine Liste `eventFields: [{label, source}]`, wobei jede `source` auf ein Command-Feld des Prozess-Inputs zeigt (`ProcessEventFieldEditor.svelte`, Details-Tab des Ticket-Panels). Der Generator loest daraus die **modell-aufgeloeste Identitaet** des betroffenen Aggregats auf (ganze Vorfahren-Kette, eine Property je Kettenglied, `{label}{EntityName}Id`) und erzeugt zweierlei:

1. eine **`final readonly` Event-Daten-Klasse** unter `{BC}/Process/{Name}/Event/<EventClass>.php` (hermetisch, ForceOverwrite — der Knotenname ist der Event-Klassenname) mit einer Property je aufgeloester Identitaets-Kette **plus** zwei automatischen Standardfeldern: `eventId` (UUID7) und `occurredAt` (`\DateTimeImmutable`);
2. einen **Knoten**, dessen `logic()`-Body **vollstaendig generiert** ist (kein Hand-auszufuellender Stub mehr) — die Identitaetswerte werden direkt von den public-readonly-Properties des gebundenen Command-DTOs gelesen, die `eventId` per `Identity`-Service (`generateUuid7()`) erzeugt:

```php
protected function logic(FlagReading $cmd, WorkflowContextInterface $context): array
{
    return [
        'status' => WorkflowResult::ON_SUCCESS,
        'data'   => [
            EventScope::Domain->value => [
                new ReadingFlagged(
                    flaggedCounterId: $cmd->affectedCounter->counterIdentifier,
                    eventId: $this->handle(Identity::class)->generateUuid7(),
                    occurredAt: new \DateTimeImmutable(),
                ),
            ],
        ],
    ];
}
```

Eine **Union-Quelle** (die Bindung zeigt auf ein Command-Feld mit mehreren `accepts:`-Zweigen) rendert einen `match(true)`/`instanceof`-Dispatch je Zweig statt eines Direkt-Lesens; eine `list:true`-Quelle rendert `array_map` ueber die Liste. Der Orchestrator erntet alle so abgelegten Domain-Events nach dem Lauf aus der Kette (`getChain()`) und haengt sie via `addEvent(…, EventScope::Domain)` an die Response. **Es gibt keine Dev-Aufgabe am generierten Knoten-Body** — die einzige Autoren-Tätigkeit ist die Feld-Bindung **im Designer**, nicht im Code. Regeln fürs Binden (V-EVT-*): mind. eine Bindung, Quelle muss identitätstragend sein, keine Namenskollision, Union-Zweige gleiche Kettentiefe. Publikation nach Commit ist Sache des Aufrufers (Event-Transport-Rezepte: `platform-cookbook` §1).

### 3. handlerFactory-Closure

`new Workflow($factory)` akzeptiert optional `Closure(string $fqcn, mixed $data): object`. Die Konvention im Aggregat-Kontext ist `fn($cls, $data) => $this->context($cls, $data)`, sodass jeder Node eine frische BC mit `$data` als Payload bekommt — Nodes lesen es via `$this->payload()`. Bei `$data === null` ist `$this->handle($cls)` der Default, und der aeussere Payload bleibt erhalten. Ohne Factory ruft die Engine `new $fqcn()` (`$data` ignoriert) — fuer puren PHP-Code ausserhalb des Aggregat-Kontexts brauchbar.

### 4. Drei opake Context-Slots

`WorkflowContext` traegt neben dem Result-Chain drei freie Slots, die *nicht* vom Routing inspiziert werden:

| Slot | Getter / Setter | Verwendung |
|---|---|---|
| `reference` | `reference()` / `setReference(mixed)` | Out-of-Band-Kanal Orchestrator → Node (z.B. vor-aufgeloeste Aggregat-Identitaet weiterreichen). |
| `response` | `response()` / `setResponse(mixed)` | Out-of-Band-Kanal Node → Orchestrator (z.B. `DomainResponse` aus dem End-Node ablegen, statt ueber `WorkflowResult.data`). |
| `exception` | `getException()` / `setException(\Throwable)` | Ein gefangenes `Throwable` einsteuern, ohne den Engine-Loop zu unterbrechen — Cleanup-Nodes koennen es auslesen und in die Response-Mapping-Schicht ueberfuehren. |

Slots sind explizit *nicht* fuer Daten gedacht, die zwischen sequentiellen Nodes fliessen — dafuer dienen `WorkflowResult.data` und `WorkflowContext::getPrevious() / getLatest($fqcn) / getAll($fqcn) / getChain()`.

### 5. R5 — Routing-Safety

Die Engine bricht ohne Throw ab, wenn

1. der aktuelle Handler keinerlei Transitions konfiguriert hat, **oder**
2. fuer den zurueckgegebenen Status keine Transition existiert, **oder**
3. das konfigurierte Transition-Target nicht selbst per `addNode()` registriert ist.

In allen drei Faellen erhaelt der Aufrufer den vollstaendigen `WorkflowContext` zurueck; die Verantwortung fuer "war das jetzt ein gewolltes Ende oder ein Konfigurationsfehler?" liegt beim Orchestrator-Mantel (typisch: `try/catch` + Pruefung von `$context->getException()` und `$context->getPrevious()`).

### Anchors

- `platform-implementation` (Generated baseline, override targets, decision tree).
- `support-workflow` (the engine implementation itself).
