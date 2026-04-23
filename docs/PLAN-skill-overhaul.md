# Phasenplan — Bundle-Skills Overhaul

**Status:** v3 · 2026-04-23
**PRD:** `docs/PRD-skill-overhaul.md`
**Format:** `docs/SKILL-FORMAT.md`
**Iteration v2:** Phase B reduziert auf nur `tools-definition`. Inhalt von `tools-builder` (Generated/Skeleton-Katalog, Layout) wandert als §1 in `platform-implementation` (Phase C). Phase F ergänzt: tools-builder löschen + Cross-References.
**Iteration v3 (Post-Skill-Edits, 2026-04-23):** Phase G nachgelagert: `platform-usage` als 7. Skill, Format-Spec v3 (topische Gliederung, Wort-Cap 60, `post-active`-Budget 550, `examples/`-Konvention). Siehe Phase G unten.

---

## Reihenfolge & Begründung

Skills werden in **Topological Order** umgebaut: zuerst die, von denen andere abhängen.

```
Phase A: Crosscut Rules (3 Skills)
   rules-architecture  →  rules-patterns  →  rules-testing
                              │
Phase B: Post-Reference (1 Skill)
   tools-definition
                              │
Phase C: Post-Active (1 Skill, enthält §1 = ehem. tools-builder)
   platform-implementation  (links to A; absorbiert tools-builder-Inhalt)
                              │
Phase D: Pre (1 Skill, neu)
   schema-authoring  (independent, kann auch früher)
                              │
Phase E: Cleanup
   Streichen: plan-requirements, plan-ddd-modeling, plan-data-discovery
   README.md im Repo aktualisieren (Skill-Liste, Versionshinweis)
   CHANGELOG.md ergänzen

Phase F: Iteration v2 — tools-builder Merge
   tools-builder löschen, Cross-References (Tests, Skill-Body, Plugin-Doc)
```

**Warum diese Reihenfolge:**
- Phase A definiert die Source-of-Truth (Patterns, Architektur, Tests). Andere Skills verlinken.
- Phase B kann erst nach A, weil B verlinkt zu A.
- Phase C verlinkt zu A und B.
- Phase D ist eigenständig (kein Verweis auf andere Skills nötig).
- Phase E räumt auf, sobald die neuen Skills stehen.

---

## Phase A — Crosscut Rules

### A1 · `rules-architecture`

| Aspekt | Wert |
|---|---|
| Heute | 160 Zeilen, deutsch, schon nahe am Ziel |
| Ziel | ≤150 Zeilen, englisch, Format-Standard |
| Quellen | `skills/rules-architecture/SKILL.md` (heute), `claude/rules/development.md` §1 (5 Säulen), §2 (Hexagonal), §3 (Closure-Orchestrator) |
| Aufwand | klein (Übersetzung + Format) |

**Acceptance:**
- [ ] Frontmatter folgt SKILL-FORMAT §2.
- [ ] 5 Säulen, Hexagonal-Layout, Closure-Orchestrator-Pattern dokumentiert.
- [ ] Anti-Patterns als Tabelle (heute ja, beibehalten).
- [ ] Pattern-Beispiele werden NICHT wiederholt — nur Verweis auf `rules-patterns`.
- [ ] Test-Regeln werden NICHT wiederholt — Verweis auf `rules-testing`.

### A2 · `rules-patterns`

| Aspekt | Wert |
|---|---|
| Heute | 130 Zeilen |
| Ziel | ≤150 Zeilen |
| Quellen | `skills/rules-patterns/SKILL.md` (heute), `claude/rules/development.md` §4 (Pattern-Tabelle) |
| Aufwand | klein |

**Acceptance:**
- [ ] Pattern-Katalog vollständig: Facade, Strategy, Priority-Layers, CoR, Lazy Init, Adapter, VO, Factory, Repository, Decorator.
- [ ] Pro Pattern: Zweck, Kernregel, Mini-Beispiel (≤8 Z. Code).
- [ ] Verweis auf `rules-architecture` für die Säulen-Begründung.

### A3 · `rules-testing`

| Aspekt | Wert |
|---|---|
| Heute | 133 Zeilen |
| Ziel | ≤130 Zeilen |
| Quellen | `skills/rules-testing/SKILL.md` (heute), `claude/rules/development.md` §5 (Testing) |
| Aufwand | klein |

**Acceptance:**
- [ ] Integration > Unit klar begründet.
- [ ] Mocking-Regel: nur Ports.
- [ ] Test-Naming + AAA dokumentiert.
- [ ] Anweisung "wie mit Testfehlern umgehen" beibehalten (heute klare Stärke).
- [ ] Docker-Service-Hinweis für Integration-Tests.

---

## Phase B — Post-Reference

### B1 · `tools-definition`

| Aspekt | Wert |
|---|---|
| Heute | 422 Zeilen, fokussiert auf alten PHP-Builder + viel API-Doku |
| Ziel | ≤250 Zeilen, fokussiert auf **Designer-Output verstehen** |
| Quellen | Builder-Repo: `internal/definition/generator/`, `internal/definition/writer/`, Beispiele unter `tests/Fixture/Definition/MeterDevice/` |
| Aufwand | mittel (komplette Neufassung mit Go-Builder-Bezug) |

**Acceptance:**
- [ ] Erklärt die 4 Output-Dateien: `Aggregate.yaml`, `Source.yaml`, `FieldMap.yaml`, `Lists.yaml`.
- [ ] Erklärt die DDD-Hints im YAML: `erm` (one/many), `depend`, `adopt`, `relates`.
- [ ] Zeigt 1 Beispiel pro Datei (jeweils ≤30 Zeilen).
- [ ] Erklärt, **wie die AI ein generiertes Definition-File interpretiert** (nicht: wie sie eines schreibt — das macht der Designer).
- [ ] Verweis auf `platform-implementation` für PHP-Output und Implementation.

### B2 · `tools-builder` — entfällt (v2)

In Iteration v2 gestrichen. Inhalte sind in `platform-implementation` §1 gewandert (siehe Phase C). Begründung: PHP-Code-Lesen ist großteils selbsterklärend; nur die Generated-vs-Skeleton-Unterscheidung und das Layout-Diagramm sind nicht-trivial — und die werden direkt beim Implementieren gebraucht, also gehören sie in `platform-implementation`.

---

## Phase C — Post-Active

### C1 · `platform-implementation`

| Aspekt | Wert |
|---|---|
| Heute | 695 Zeilen, mischt ClassVersion + Patterns + Implementation-Stufen + V-Verbote |
| Ziel | ≤300 Zeilen, fokussiert auf **Mechanik der Implementierung** |
| Quellen | `skills/platform-implementation/SKILL.md` (heute) — destillieren, Patterns rauswerfen |
| Aufwand | mittel-groß (Inhalt destillieren + Verlinkungen einarbeiten) |

**Acceptance:**
- [ ] ClassVersion v2-Mechanik klar erklärt.
- [ ] Override-Mechanik (Namespace-Injection) klar erklärt.
- [ ] V1–V12 Verbote als Tabelle, jeder Verbot mit Why.
- [ ] 7 Implementierungs-Stufen kompakt (heute lang).
- [ ] **Pattern-Definitionen entfernt** — Verweis auf `rules-patterns`.
- [ ] **Architektur-Säulen entfernt** — Verweis auf `rules-architecture`.
- [ ] **Test-Regeln entfernt** — Verweis auf `rules-testing`.
- [ ] **§1 Generated Baseline ergänzt (v2)** — Layout-Diagramm + Generated/Skeleton-Tabelle + Api-Registry-Regel aus dem ehemaligen `tools-builder`.

---

## Phase D — Pre

### D1 · `schema-authoring` (NEU)

| Aspekt | Wert |
|---|---|
| Heute | existiert nicht |
| Ziel | ≤250 Zeilen |
| Quellen | Builder-Repo: `internal/definition/schema.go` (Parser-Definition), `tests/Fixture/Definition/MeterDevice/Schema.yaml` (Beispiel) |
| Aufwand | mittel (Greenfield, aber kleiner Scope) |

**Acceptance:**
- [ ] Schema.yaml-Format vollständig dokumentiert: `tables` → `columns` → `indexes` → `foreignKeys`.
- [ ] Spalten-Typen-Liste mit `phpType`-Mapping.
- [ ] AI-Anleitung: aus Idee → Tabellen ableiten, Zusammenhänge erkennen, Indexe vorschlagen.
- [ ] FK-Behandlung: optional, nur wenn unambiguous; sonst Designer überlassen.
- [ ] Mindestens 1 vollständiges End-to-End-Beispiel (Idee → Schema.yaml).
- [ ] Handoff: "import in Designer, then visual modeling".

---

## Phase E — Cleanup

### E1 · Streichungen

```
rm -rf skills/plan-requirements/
rm -rf skills/plan-ddd-modeling/
rm -rf skills/plan-data-discovery/
```

### E2 · Repo-Updates

- `README.md`: Skill-Liste auf 7 aktualisieren, "Configuring bundled skills" Beispiele aktualisieren.
- `CHANGELOG.md`: Eintrag unter `[Unreleased]` mit BREAKING-Hinweis.
- `src/Plugin.php` und Tests: prüfen, ob die hartkodierte Skill-Liste irgendwo Annahmen über Namen macht (vermutlich nur Discovery, aber checken).

### E3 · Optional: AGENTS.md im Repo selbst

Heute hat `dev-skills/` keine eigene AGENTS.md, die in Consumer-Projekte aggregiert wird. Prüfen, ob für diese Skills eine kurze AGENTS.md-Header-Zeile sinnvoll wäre.

---

## Phase F — Iteration v2 (tools-builder Merge)

Nach Abschluss von A–E entschieden: `tools-builder` weglassen, weil PHP-Code-Lesen weitgehend selbsterklärend ist. Nur Layout + Generated/Skeleton-Katalog sind nicht-trivial und wandern als §1 in `platform-implementation` (dort wird die Info beim Implementieren gebraucht).

**Schritte:**
- [x] Inhalt von `tools-builder` §1, §2, §4 in `platform-implementation` §1 mergen.
- [x] `skills/tools-builder/` löschen.
- [x] Cross-References updaten: `rules-architecture` Handoff, `tools-definition` Handoff/References, `ScanPluginSkills` Doc-Comment.
- [x] Tests anpassen: `PluginTest::testOnComposerRunInstallsAllPluginOwnSkills` (7→6), `testBundledSkillsWhitelistInstallsSubset` (eine Assertion entfernt). Synthetische Test-Setups (`FilterBundledSkillsTest`, `RemoveStaleBundledSkillsTest`, `ComputeStaleBundledSkillsTest`, `RemoveJardisSkillsTest`) auf existierende Skill-Namen umstellen.
- [x] README aktualisieren: 7→6, Prefix-Tabelle (`tools-*`), Whitelist-Beispiel, Upgrade-notes.
- [x] CHANGELOG ergänzen: Removed (merged), Migration-Hinweis für `tools-builder`.

---

## Aufwandsschätzung gesamt

| Phase | Skills | Geschätzter Kontext-Anteil |
|---|---|---|
| A (rules-*) | 3 | 1 Session (~20%) |
| B (tools-*) | 1 | (in Session 1 abgehakt) |
| C (platform-implementation) | 1 | (in Session 1 abgehakt) |
| D (schema-authoring) | 1 | (in Session 1 abgehakt) |
| E (Cleanup) | — | (in Session 1 abgehakt) |
| F (Iteration v2: tools-builder Merge) | -1 | Session 2 (~10%) |
| G (Iteration v3: platform-usage + Format-Spec v3) | +1 | Session 3 (~15%) |

**Gesamt:** Phase A–E in 1 Session, Phase F in 1 Folge-Session, Phase G in 1 weiterer Folge-Session.

---

## Phase G — Iteration v3 (platform-usage + Format-Spec v3)

Hands-on-Ergebnis aus dem Arbeiten mit den v2-Skills:

**G1 · `platform-usage` als 7. Skill (zone `post-active`).** Beim Implementieren eines Controllers / CLI-Command / Queue-Consumers zeigte sich, dass der Trigger "Transport-Wiring" separat stehen muss — sonst feuert die AI nur `platform-implementation` und der Fokus ist Domain-Code, nicht Adapter-Code. Inhalte: 4-Hop-Call-Chain (Domain → BC → Aggregate → Registry), Bootstrap-Lebenszyklus pro Transport, `DomainResponse`→HTTP/CLI/Queue-Mapping, Error-Handling-Regeln, Transport-Patterns (PSR-15, Symfony Console, Queue-Consumer). Size: ~130 Zeilen.

**G2 · Format-Spec v3.** Beobachtungen:
- Die vorgeschriebenen 5 Body-Headings (`## When this skill applies`, `## What the AI does`, `## Output / Artefact`, `## Handoff`, `## References`) passten für Pre-Skills gut, erzwangen aber bei Reference-Skills (`tools-definition`, `rules-*`) redundante Leerabschnitte.
- Dichte Descriptions mit mehreren Trigger-Begriffen feuern zuverlässiger als kurze Einzelsätze.
- `platform-implementation` läuft mit vollem Layout-Diagramm + Steps + Cookbook über das 400-Zeilen-Budget.

Konsequenz: `docs/SKILL-FORMAT.md` auf v3 gehoben — topische nummerierte Gliederung (`### 1. …`), Wort-Cap 30→60, `post-active`-Budget 400→550. Validator (`bin/validate-skills.php` + `ValidateSkillMd`) entsprechend angepasst: kein Heading-Template mehr, nur noch "≥1 `##`/`###`-Abschnitt".

**G3 · `examples/`-Konvention.** Lange Working-Artefakte (Schema.yaml, Aggregate.yaml-Familie) leben jetzt unter `skills/<name>/examples/`. Body zeigt Sketches, `examples/` enthält die vollständige Version. Zählt nicht gegen das Zeilenbudget.

**Schritte:**
- [x] `skills/platform-usage/SKILL.md` erstellt.
- [x] `docs/SKILL-FORMAT.md` auf v3 umgeschrieben (topische Gliederung, neue Budgets, `examples/`-Konvention).
- [x] `ValidateSkillMd` + Tests angepasst — 5-Heading-Loop ersetzt durch "mind. 1 Section-Heading", `MAX_DESCRIPTION_WORDS` 30→60, `post-active`-Budget 400→550.
- [x] `README.md`, `AGENTS.md`, `CHANGELOG.md` auf 7 Skills + `examples/`-Hinweis aktualisiert.
- [x] `PluginTest::testOnComposerRunInstallsAllPluginOwnSkills` um `platform-usage` erweitert.
- [x] `PRD-skill-overhaul.md` + dieses Dokument mit v3-Postscript versehen.

**Acceptance v3:**
- [x] `make validate-skills` grün (7/7).
- [x] `make phpunit` grün.
- [x] README-Skill-Liste, AGENTS-Bundle-Liste und Prefix-Tabelle nennen `platform-usage`.
- [x] `examples/`-Konvention in `docs/SKILL-FORMAT.md` §6 dokumentiert.

---

## Acceptance gesamt

Wenn alle Phasen durch sind:

- [x] `wc -l skills/*/SKILL.md` zeigt ≤300 pro Skill, Gesamt ~880 (Ziel ~1480 deutlich unterschritten dank Merge).
- [x] Keine zwei Skills definieren denselben Inhalt (Pattern, Säule, Testregel) doppelt.
- [x] Jede `description` besteht §2-Checkliste in `SKILL-FORMAT.md`.
- [x] `composer install` in einem Test-Projekt installiert weiterhin nur die opt-in-konfigurierten Skills.
- [x] README skill-list und CHANGELOG aktualisiert.
