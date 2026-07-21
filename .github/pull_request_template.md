## Beschreibung

<!-- Was ändert dieser PR? Warum? -->

## Checklist

- [ ] Tests aktualisiert / ergänzt
- [ ] CHANGELOG erweitert (Added / Changed / Fixed / Removed)
- [ ] Skill (`skills/<name>/SKILL.md`) spiegelt die Änderung wider — Anwender-Sicht, keine Implementierungs-Interna (PRD §V5.2, Entscheidung 1)
- [ ] CLAUDE.md aktuell, falls Implementierungs-Details berührt sind
- [ ] `make validate-skills` grün
- [ ] `make phpunit` grün
- [ ] `make phpstan` grün
- [ ] `make phpcs` grün

## Reviewer-Hinweise

- Skill-Checkbox ist **nicht optional** — wenn ein Skill nicht geupdatet wurde, im Review begründen warum (z.B. rein interne Änderung ohne Anwender-Auswirkung).
- Bei API-Brüchen: `BREAKING:`-Eintrag im CHANGELOG.
