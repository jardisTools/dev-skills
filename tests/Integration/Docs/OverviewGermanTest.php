<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Docs;

use PHPUnit\Framework\TestCase;

/**
 * The German overview page `docs/overview.de.html` against the repository: the
 * skill names on the skill map are exactly the folders of `skills/`, the page has
 * its five areas, the tool coverage matrix names the five tools and carries the
 * legend with the retrieval date, and the page states facts only.
 */
final class OverviewGermanTest extends TestCase
{
    private const BUNDLED_SKILLS = 34;
    private const AREAS = 5;
    private const BUILDING_BLOCKS = 10;
    private const MIN_CONCEPT_HITS = 200;

    /** The word "Tor" as a word or compound part ("Sicht-Tor", "QA-Tore"), never inside "Orchestrator". */
    private const TOR = '(?<!\p{L})Tor(?:e|es|s)?(?!\p{L})';

    /**
     * README section names, head keys and skill area names that stay English on the German page: removed
     * from both nodes before the table is applied.
     *
     * @var list<array{string, string}>
     */
    private const LITERALS = [
        ['Reviewer agent files', 'Reviewer agent files'],
        ['Supported tools', 'Supported tools'],
        ['Phase, Stage, Next step, Open decisions', 'Phase, Stage, Next step, Open decisions'],
        ['tools, resources', 'Tools, Resources'],
        ['start · packages', 'start · packages'],
    ];

    /**
     * English concept (regex, case-insensitive, whole word) => chosen German word and discarded variants
     * (regexes on the German node).
     *
     * @var list<array{string, string, list<string>}>
     */
    private const CONCEPTS = [
        ['packages?', 'Package', ['Paket']],
        ['gates?', 'Gate', [self::TOR]],
        ['sight gate', 'Sicht-Gate', []],
        ['open-question gate', 'Rückfrage-Gate', []],
        ['acceptance gate', 'Akzeptanz-Gate', []],
        ['QA gates', 'QA-Gates', []],
        ['quality gate', 'Quality-Gate', []],
        ['gates of the human', 'Gates des Menschen', []],
        ['with a reason', 'mit Grund', ['Begründung']],
        ['entry points?', 'Einstieg', ['Einstiegspunkt']],
        ['skill map', 'Skill-Karte', ['Skill-Landkarte']],
        ['fallback', 'Ersatzweg', ['Ausweichweg', 'Ausweg']],
        ['decisions?', 'Entscheid', ['Entscheidung']],
        ['decid(?:e|es|ed)', '(?i:entscheid)', ['beschließ']],
        ['retry', 'Wiederholungslauf', ['Retry']],
        ['topic pages?', 'Themenseite', []],
        ['agent files?', 'Agent-Datei', []],
        ['sessions?', '(?i:session)', ['Sitzung']],
        ['(?:reviewers?|checkers?)', 'Prüfer', []],
        ['(?:implementer|doer)', 'Umsetzer', ['Doer']],
        ['undertakings?', 'Vorhaben', []],
        ['tiers?', 'Stufe', []],
        ['single action', '(?i:handgriff)', []],
        ['(?:small )?assignments?', '(?i:auftrag)', []],
        ['tasks?', '(?i:aufgabe)', []],
        ['subtasks?', '(?i:aufgabe)', []],
        ['task list', 'Aufgabenliste', []],
        ['findings?', 'Befund', []],
        ['verdicts?', 'Verdikt', []],
        ['switch', 'Schalter', []],
        ['managed', 'verwaltet', []],
        ['fresh', '(?i:frisch)', []],
        ['target picture', 'Zielbild', []],
        ['head', 'Kopf', []],
        ['documents?', 'Arbeitsunterlagen', ['Prozessdokument']],
        ['documented', 'belegt', ['dokumentiert']],
        ['update', 'aktualisier', []],
        ['interface', 'Benutzeroberfläche', []],
        ['used', '(?i:verwendet)', ['Eingesetzt', 'Einsatz']],
        ['other', '(?i:ander)', []],
        ['facts?', '(?i:fakt)', ['Sachverhalt']],
        ['paths?', '(?i:pfad)', ['Datenweg']],
        ['states?', '(?i:zust(?:a|ä)nd)', []],
        ['produc(?:e|es|ed)', 'erzeug', []],
        ['approv(?:ed|al)', '(?:Abnahme|abgenommen)', ['genehmigt']],
        ['asks?', '(?i:frag)', ['verlangt']],
        ['tested', 'getestet', []],
        ['briefs?', 'Brief', []],
        ['stages?', '(?:Etappe|Stadi(?:um|en)|Stage)', []],
        ['acceptance', '(?:Abnahme|Akzeptanz)', []],
        ['changed', 'geändert', ['verändert']],
        ['project folder', 'Projektordner', ['Vorhabensordner']],
    ];

    private string $root;
    private OverviewPage $page;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
        $this->page = new OverviewPage($this->root . '/docs/overview.de.html');
    }

    public function testPageDeclaresGermanAsLanguage(): void
    {
        $html = $this->page->xpath->query('/html')?->item(0);

        self::assertNotNull($html);
        self::assertSame('de', $html->attributes?->getNamedItem('lang')?->nodeValue);
    }

    public function testSkillMapNamesAreExactlyTheSkillFolders(): void
    {
        $folders = $this->skillFolders();
        $names   = $this->page->skillMapNames();

        self::assertCount(self::BUNDLED_SKILLS, $folders);
        self::assertSame([], array_values(array_diff($folders, $names)), 'skill folder missing on the page');
        self::assertSame([], array_values(array_diff($names, $folders)), 'page names a skill without a folder');
        self::assertSame(count($names), count(array_unique($names)), 'a skill name appears twice');
    }

    public function testPageHasFiveAreas(): void
    {
        self::assertSame(self::AREAS, $this->page->xpath->query('//h2')?->length);
    }

    public function testToolCoverageMatrixHasFiveToolColumnsAndLegend(): void
    {
        $headers = $this->page->matrixHeaders();
        self::assertCount(7, $headers, 'building block, five tools, fallback');
        self::assertSame(
            ['Claude Code', 'Codex CLI/IDE', 'Cursor', 'GitHub Copilot', 'Gemini CLI'],
            array_slice($headers, 1, 5)
        );

        $rows = $this->page->matrixSymbolRows();
        self::assertGreaterThanOrEqual(self::BUILDING_BLOCKS, count($rows), 'nine building blocks plus the commit-msg hook');
        foreach ($rows as $symbols) {
            self::assertCount(5, $symbols);
            self::assertSame([], array_values(array_diff($symbols, ['✔', '◐', '✘', '?'])));
        }

        $legend = $this->page->matrixLegend();
        foreach (['✔', '◐', '✘', '?', 'abgerufen am 2026-10-01'] as $part) {
            self::assertStringContainsString($part, $legend);
        }
    }

    public function testGuaranteeRowUsesTheGermanLevels(): void
    {
        $row = $this->page->guaranteeRow();

        self::assertStringContainsString('getestet', $row);
        self::assertStringContainsString('aus Doku belegt, nicht getestet', $row);
    }

    public function testPageStatesFactsAndLeavesNothingForDecision(): void
    {
        $text = mb_strtolower($this->page->html);

        foreach (['vorschlag', 'zur abnahme', 'zu bestätigen'] as $word) {
            self::assertStringNotContainsString($word, $text);
        }
    }

    public function testSubtitleAndHeadingsNameEachAreaWithTheSameWord(): void
    {
        $subtitle = $this->page->subtitle();
        $headings = $this->page->areaHeadings();
        $areas    = ['Prozess-Landkarte', 'Wissens-Kreislauf', 'Skill-Karte', 'Installationsbild', 'Werkzeug-Abdeckung'];

        self::assertCount(count($areas), $headings);
        foreach ($areas as $index => $area) {
            self::assertStringContainsString($area, $subtitle);
            self::assertStringStartsWith($area, $headings[$index]);
        }
    }

    public function testFallbackColumnIsCalledErsatzweg(): void
    {
        $headers = $this->page->matrixHeaders();

        self::assertStringStartsWith('Ersatzweg', $headers[6]);
    }

    /**
     * One German word per concept (outside `<code>`): each discarded variant has a
     * chosen word, so the same thing is never named two ways on the page.
     */
    public function testWordingUsesOneWordPerConcept(): void
    {
        $text = $this->page->textOutsideCode();

        $discarded = [
            'Skill-Landkarte' => 'Skill-Karte',
            'Ausweichweg'     => 'Ersatzweg',
            'Ausweg'          => 'Ersatzweg',
            'Retry'           => 'Wiederholungslauf',
            'Doer'            => 'Umsetzer',
            'Entscheidung'    => 'Entscheid',
            'Paket'           => 'Package',
            'Begründung'      => 'Grund (mit Grund)',
            'Einstiegspunkt'  => 'Einstieg',
            'Datenwege'       => 'Datenpfade',
            'Sachverhalt'     => 'Fakt',
            'verändert'       => 'geändert',
            'beschließ'       => 'entscheid',
            'genehmigt'       => 'abgenommen',
            'dokumentiert'    => 'belegt',
            'Eingesetzt'      => 'Verwendet',
            'laufen lassen'   => 'ausführen',
            'Prozessdokument' => 'Prozess-Arbeitsunterlagen',
            'Vorhabensordner' => 'Projektordner',
        ];
        foreach ($discarded as $variant => $chosen) {
            self::assertFalse(str_contains($text, $variant), sprintf('use "%s" instead of "%s"', $chosen, $variant));
        }

        self::assertSame(
            0,
            preg_match('~' . self::TOR . '~u', $text, $match),
            sprintf('use "Gate" instead of "%s"', $match[0] ?? 'Tor')
        );
    }

    /**
     * The concept table: every English text node that names a concept of the table has, at the same
     * place of the German page, the one chosen German word and none of the discarded variants. The
     * nodes are the text slots of both pages outside `<code>` and `.mono` (`OverviewPage::textSlots()`),
     * paired by position.
     *
     * Named exceptions, kept out of the table because the English itself means two things or the
     * German word is fixed by the process:
     * - stage: Etappe (plan) or Stadium (process 0 to 4), the table accepts both and `Stage` (head key)
     * - acceptance: Abnahme (phase and act of the human) or Akzeptanz-Gate (machine run, role acceptance-gate)
     * - tool: Werkzeug; only the MCP words "tools, resources" stay English (literal below)
     * - page: Themenseite (pool), Seite (this page, "one page per topic" = "je Thema eine Seite")
     * - vendor: Hersteller (the maker) or Vendor (Composer vendor directory, "Jardis vendor packages")
     * - entry: Einstieg (entry point) or Eintrag (list item)
     * - build: Umsetzung (phase), baut (verb), Build (noun in the project profile)
     * - block: Block (managed block), Baustein (building block), blockiert (verb)
     * - carry: trägt (bears), übernehmen (carry into)
     * - hold: enthält (contains), zutreffen (all four criteria hold)
     * - keep: hält (keeps in the commit), führt (keeps the list)
     * - rule: Regel (noun), bescheiden (rule on a finding)
     * - run: Lauf (noun), läuft (intransitive), ausführen (transitive)
     * - read: lesen, gegenlesen (read back)
     * - list, load, offer: noun and verb of the same English word
     * - above: oben (before) or über (threshold of 32,768 bytes)
     * - place: Ort (one place), bereitstellen (puts in place)
     * - reference: Pfadverweise (path references), Referenz (PHP 8.3 reference)
     * - value: Werte (values), Value Objects (pattern name)
     * - minor: kleine (adjective), Minor (grade of a finding)
     * - level: Level (PHPStan level 8, product name), Stufe (guarantee level)
     * - map: Prozess-Landkarte and Skill-Karte, the two area names confirmed in the target picture
     */
    public function testConceptTableHoldsOverAllPairedTextNodes(): void
    {
        $english = (new OverviewPage($this->root . '/docs/overview.html'))->textSlots();
        $german  = $this->page->textSlots();

        self::assertCount(count($english), $german, 'both pages have the same element skeleton');

        $checked = 0;
        $failed  = [];
        foreach ($english as $index => $en) {
            $de = $german[$index];
            if ($en === '' && $de === '') {
                continue;
            }
            foreach (self::LITERALS as [$literalEn, $literalDe]) {
                $en = str_replace($literalEn, '', $en);
                $de = str_replace($literalDe, '', $de);
            }
            foreach (self::CONCEPTS as [$concept, $chosen, $discarded]) {
                if (preg_match('~(?<![\p{L}\p{N}_-])(?:' . $concept . ')(?![\p{L}\p{N}_-])~iu', $en) !== 1) {
                    continue;
                }
                ++$checked;
                if (preg_match('~' . $chosen . '~u', $de) !== 1) {
                    $failed[] = sprintf('node %d "%s" lacks /%s/ in "%s"', $index, $en, $chosen, $de);
                }
                foreach ($discarded as $variant) {
                    if (preg_match('~' . $variant . '~u', $de) === 1) {
                        $failed[] = sprintf('node %d "%s" has discarded /%s/ in "%s"', $index, $en, $variant, $de);
                    }
                }
            }
        }

        self::assertGreaterThan(self::MIN_CONCEPT_HITS, $checked, 'the table finds its concepts on the page');
        self::assertSame([], $failed);
    }

    /**
     * @return list<string>
     */
    private function skillFolders(): array
    {
        $folders = [];
        foreach (glob($this->root . '/skills/*', GLOB_ONLYDIR) ?: [] as $path) {
            $folders[] = basename($path);
        }
        sort($folders);

        return $folders;
    }
}
