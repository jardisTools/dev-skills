# PRD — Bundle-Skills Greenfield Overhaul

**Status:** v3 · 2026-04-23 · Author: Rolf + Claude
**Iteration:** v2 mergt `tools-builder` in `platform-implementation` (siehe §3, §4). Nur die nicht-trivialen Teile (Generated/Skeleton-Catalog, Layout-Diagramm, Api-Registry-Regel) wandern als §1 in `platform-implementation`; der Rest ist aus dem generierten PHP-Code selbst lesbar.

> **v3-Postscript (2026-04-23).** Nach v2 sind drei Änderungen ins Bundle eingeflossen, die das PRD nicht vorweggenommen hat:
> 1. **`platform-usage` hinzugekommen** (7. Skill, `post-active`). Die v2-Planung hatte Transport-Wiring (HTTP / CLI / Queue / Worker) implizit in `platform-implementation` verortet; in der Umsetzung zeigte sich, dass die Inhalte (4-Hop-Call-Chain, Bootstrap-Lebenszyklus, `DomainResponse`-Mapping, Error-Handling) einen eigenen Trigger brauchen, damit die AI beim Controller-Schreiben feuert — nicht nur beim Domain-Code. Tabelle in §3 umfasst jetzt 7 Skills.
> 2. **Format-Standard gelockert** (`docs/SKILL-FORMAT.md` v3). Die ursprünglich vorgeschriebene 5-Überschriften-Struktur (`## When this skill applies` …) wurde gestrichen zugunsten topischer nummerierter Abschnitte (`### 1. …`). Grund: Reference-lastige Skills (`tools-definition`, `platform-implementation`) transportieren Inhalte dichter, wenn die Gliederung dem Stoff folgt statt einem generischen Template. Description-Cap von 30 auf 60 Wörter erhöht, `post-active`-Budget von 400 auf 550 Zeilen (für `platform-implementation` nötig).
> 3. **`examples/`-Konvention etabliert.** `schema-authoring` und `tools-definition` liefern vollständige MeterDevice-Artefakte (`examples/Schema.yaml`, `examples/Counter/{Aggregate,Source,FieldMap,Lists}.yaml`) als Companion-Dateien neben der SKILL.md mit. Das ist in §5 (Format-Standard) ergänzt; zählt nicht gegen das Zeilenbudget.
>
> Die Quality-Gates aus §6 (Trigger-Schärfe, keine Duplikate, Phasen-Übergänge) gelten weiter und werden vom Validator (`bin/validate-skills.php`) erzwungen.

---

## 1. Ziel

Maximale AI-Unterstützung für Jardis-Entwickler bei minimalem, scharfem Skill-Set.

**Was sich ändert:** Die heutigen 9 Bundle-Skills in `dev-skills/skills/` werden durch 6 neue/überarbeitete Skills ersetzt. Greenfield — keine Nutzer im Bestand, keine Rückwärtskompatibilität nötig.

**Was sich nicht ändert:**
- Das Composer-Plugin selbst (Discovery, Bundled-Skills-Konfiguration via `extra."jardis/dev-skills".bundled-skills`, AGENTS.md-Aggregation).
- Package-Skills (`adapter-*`, `support-*`, `core-*`, `tools-dbschema`) — die liegen in den jeweiligen Vendor-Packages, nicht hier.
- `do-*`-Skills aus dem `claude/`-Projekt — bleiben außen vor.

---

## 2. Kontext: Wer arbeitet wie mit Jardis

Jardis ist ein PHP-DDD-Ecosystem (20+ Packages). Kern: **Jardis Designer** — ein Tool, das Definitions (`.yaml`) und Code (`.php`) **komplett erzeugt**.

### Drei-Zonen-Topologie

```
[Pre-Designer]  ─►  [Designer + Builder = Black Box]  ─►  [Post-Designer]
   1 Skill              0 Skills (User klickt im UI)         5 Skills
```

**Pre-Designer:** Der Designer braucht Schema-Input (DB-Verbindung **oder** Schema-Datei). Hat der Entwickler nur eine Idee, hilft die AI, eine Schema-Datei im exakten Designer-Input-Format zu erzeugen.

**Designer + Builder:** Visuelle Klick-Arbeit. AI ist passiv. Designer importiert Schema → User modelliert Aggregate-Hierarchie → Knopfdruck → fertige `Aggregate.yaml`, `Source.yaml`, `FieldMap.yaml`, `Lists.yaml` + komplette PHP-Klassen (`Aggregate/`, `Repository/`, `Command/`, `Query/`, `Handler/`).

**Post-Designer:** Entwickler implementiert Fachlogik auf erzeugtem Code. AI hilft aktiv mit Implementierungsregeln, kann Designer-Output (YAML) lesen, kennt Builder-PHP-Output, kennt Architektur/Pattern/Test-Regeln.

---

## 3. Skill-Set (final)

| # | Skill | Zone | Größe | Funktion |
|---|---|---|---|---|
| 1 | `schema-authoring` | Pre | ~250 Z. | Aus Idee → `Schema.yaml` im DB-Export-Stil. Leitet Tabellen + Zusammenhänge ab, schlägt Indexe vor. FK optional. Designer-import-fähig. Companion `examples/Schema.yaml`. |
| 2 | `tools-definition` | Post (Reference) | ~150 Z. | Designer-Output verstehen: `Aggregate.yaml`, `Source.yaml`, `FieldMap.yaml`, `Lists.yaml`. Fokus auf nicht-triviales Vokabular (`erm`/`depend`/`adopt`/`relates`, Parameter-Binding). Companion `examples/Counter/*.yaml`. |
| 3 | `platform-implementation` | Post (Active) | ~500 Z. | Wie Fachlogik auf erzeugtem Code: §1 Generated-Baseline (Verzeichnis-Layout + Generated-vs-Skeleton-Katalog), ClassVersion v2/Override-Mechanik, V-Verbote, Implementierungs-Stufen. |
| 4 | `platform-usage` | Post (Active) | ~130 Z. | **Nachträglich (v3):** Transport-Wiring — 4-Hop-Call-Chain, Bootstrap-Lebenszyklus, `DomainResponse`→HTTP/CLI/Queue, Error-Handling. |
| 5 | `rules-architecture` | Crosscut | ~120 Z. | 5 Säulen, Hexagonal, Closure-Orchestrator. |
| 6 | `rules-patterns` | Crosscut | ~75 Z. | Pattern-Katalog (Facade, Strategy, Decorator, …). |
| 7 | `rules-testing` | Crosscut | ~140 Z. | Integration > Unit, Mock nur Ports, Verhalten testen, Phase-3-Test-Patterns für generierte Domain-Klassen. |

**Gesamt-Reduktion:** heute ~3050 Zeilen → erreicht ~1160 Zeilen (**~62% kürzer**, Rest ist inhaltliches Wachstum: `platform-usage` + `rules-testing` Phase-3-Patterns + Extensions/-Layout in `platform-implementation`).

### Gestrichen / ersetzt / gemerged

| Heute | Schicksal | Begründung |
|---|---|---|
| `plan-requirements` | **gestrichen** | PRD-Erstellung ist generische AI-Aufgabe, kein Jardis-Spezifikum. Vor Designer irrelevant. |
| `plan-ddd-modeling` | **gestrichen** | DDD-Schnitt macht der Designer visuell. AI hat dort nichts beizutragen. |
| `plan-data-discovery` | **ersetzt durch `schema-authoring`** | Radikal vereinfacht: nur noch Schema.yaml-Erzeugung, keine Discovery-Methodik. |
| `tools-definition` | **gestrafft** | Heute PHP-zentrisch + viel Methodik. Neu: Vokabular-Referenz (`erm`/`depend`/`adopt`/`relates`, Parameter-Binding). Format-Wiederholung gestrichen — die YAML-Struktur ist aus den Files selbst lesbar. |
| `tools-builder` | **gemerged in `platform-implementation` §1** | Reading PHP code is largely self-documenting. Nicht-triviales (Layout, Generated-vs-Skeleton-Katalog, Api-Registry-Regel) lebt jetzt als §1 in `platform-implementation`, weil dort die Information beim Implementieren gebraucht wird. |
| `platform-implementation` | **gekürzt + erweitert um §1** | Heute 695 Z. mit Pattern-Doppelung zu `rules-*`. Patterns raus, dafür generierter-Code-Layout aus `tools-builder` rein. |

---

## 4. Source-of-Truth-Trennung

Jeder Inhalt **lebt in genau einem Skill**. Andere Skills verlinken, wiederholen nicht.

| Inhalt | Lebt in | Wer verlinkt |
|---|---|---|
| 5 Säulen, Hexagonal, Closure-Orchestrator-Regeln | `rules-architecture` | alle Post-Skills bei Bedarf |
| Pattern-Definitionen (Facade, Strategy, …) | `rules-patterns` | `platform-implementation` |
| Test-Regeln | `rules-testing` | `platform-implementation` |
| Designer-Output-YAML — Vokabular & Hints | `tools-definition` | `platform-implementation` |
| Generated-PHP-Layout & Generated-vs-Skeleton-Katalog | `platform-implementation` §1 | `rules-architecture` (Handoff) |
| Schema.yaml-Format | `schema-authoring` | (niemand sonst) |
| Implementierungs-Mechanik (ClassVersion, V-Verbote, Levels) | `platform-implementation` §2–§7 | (niemand sonst) |

---

## 5. Format-Standard (verbindlich)

Details in `docs/SKILL-FORMAT.md`. Kurzfassung:

```yaml
---
name: <skill-name>
description: <ONE sentence — when this skill applies, narrow>
zone: pre | post-active | post-reference | crosscut
prerequisites: []
next: []
---

## When this skill applies        (3 concrete user-statement examples)
## What the AI does               (step-by-step, max 7 steps)
## Output / Artefact              (what must exist when done)
## Handoff                        (how the AI knows it's complete)
## References                     (pointers, not duplicated content)
```

**Sprache:** Englisch durchgehend (Frontmatter und Body). AI-Trigger und international.

---

## 6. Erfolgskriterien

1. **Trigger-Schärfe:** Jede `description` listet ≤4 konkrete Szenarien (heute teils 9+ Stichworte).
2. **Keine Duplikate:** Pattern-, Architektur-, Test-Inhalte stehen jeweils in genau einem Skill.
3. **Phasen-Übergänge explizit:** Skills nennen `next:` und im Body einen "Handoff"-Abschnitt.
4. **Längen-Compliance:** ≤300 Zeilen pro Skill (heute teils 695).
5. **Konsistente Struktur:** Alle 7 Skills folgen dem Format-Template aus `SKILL-FORMAT.md`.
6. **Designer-Realität:** `schema-authoring` produziert valides Schema.yaml (geprüft gegen Beispiel `tests/Fixture/Definition/MeterDevice/Schema.yaml` im Builder-Repo).

---

## 7. Out of Scope

- Composer-Plugin-Code (`src/`) — bleibt unverändert.
- `bundled-skills`-Default in `composer.json` — bleibt opt-in (false).
- AGENTS.md-Aggregation — bleibt unverändert.
- Zukünftige Optimierungs-Iteration — separater Schritt, nicht in diesem PRD.

---

## 8. Referenzen

- Builder-Repo (Schema.yaml-Format, Designer-Output-Struktur): `/Users/Rolf/Development/headgent/jardis/tools/builder/`
  - Schema-Parser: `internal/definition/schema.go`
  - Beispiel: `tests/Fixture/Definition/MeterDevice/Schema.yaml`
- Format-Template: `docs/SKILL-FORMAT.md` (in diesem Repo)
- Phasenplan: `docs/PLAN-skill-overhaul.md` (in diesem Repo)
