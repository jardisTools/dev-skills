<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Validate;

use JardisTools\DevSkills\Handler\Shell\ParseReviewerSource;
use JardisTools\DevSkills\Handler\Validate\CheckSkillLinks;
use JardisTools\DevSkills\Handler\Validate\ParseSkillFrontmatter;
use JardisTools\DevSkills\Tests\Support\RunScript;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks the shipped foundation skills against the bundle: they exist, keep their
 * descriptions short, and link only to skills that ship in the same bundle.
 */
final class BundleTest extends TestCase
{
    private const FOUNDATION_SKILLS = ['foundation-php', 'foundation-working-principles'];
    private const KNOWLEDGE_SKILLS = ['knowledge-maintain-pool', 'knowledge-record-decision'];
    private const PROCESS_SKILLS = ['process-choose-tier', 'process-check-existing', 'process-concept', 'process-resume', 'process-write-prd', 'process-write-plan'];

    public function testFoundationSkillsAreBundled(): void
    {
        foreach (self::FOUNDATION_SKILLS as $name) {
            $file = $this->skillFile($name);

            self::assertFileExists($file, sprintf('Skill %s is not bundled.', $name));

            $document = (new ParseSkillFrontmatter())((string) file_get_contents($file));
            self::assertNotNull($document, sprintf('Skill %s has no frontmatter.', $name));
            self::assertSame($name, $document['fields']['name'] ?? null);
            self::assertSame('crosscut', $document['fields']['zone'] ?? null);
            self::assertSame('C', $document['fields']['persona'] ?? null);
        }
    }

    #[DataProvider('foundationSkills')]
    public function testFoundationSkillDescriptionsHaveAtMostFortyFiveWords(string $name): void
    {
        $document = (new ParseSkillFrontmatter())((string) file_get_contents($this->skillFile($name)));
        self::assertNotNull($document);

        $description = $document['fields']['description'] ?? '';
        self::assertIsString($description);

        $words = preg_split('/\s+/', trim($description), -1, PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($words);
        self::assertNotEmpty($words);
        self::assertLessThanOrEqual(45, count($words), sprintf('Description of %s has %d words.', $name, count($words)));
    }

    public function testSetupSkillKeepsInstallHooksPhaseAndAddsCommitMsgPhase(): void
    {
        $content = (string) file_get_contents($this->skillFile('git-setup-repository'));

        $hooks   = strpos($content, '### Phase 5: Git hooks');
        $commit  = strpos($content, '### Phase 6: commit-msg hook');
        $verify  = strpos($content, '### Phase 7: Verify');
        self::assertIsInt($hooks);
        self::assertIsInt($commit);
        self::assertIsInt($verify);
        self::assertLessThan($commit, $hooks);
        self::assertLessThan($verify, $commit);
        self::assertStringNotContainsString('### Phase 6: Verify', $content);

        self::assertStringContainsString('make install-hooks', substr($content, $hooks, $commit - $hooks));

        $verifyPhase = substr($content, $verify);
        self::assertStringNotContainsString('install-commit-msg-hook', $verifyPhase, 'Phase 7 only reads.');
        self::assertStringContainsString('scripts/commit-msg', $verifyPhase);
        self::assertStringContainsString('not wired in yet', $verifyPhase);

        $phase = substr($content, $commit, $verify - $commit);
        self::assertStringContainsString('sh vendor/jardis/dev-skills/scripts/install-commit-msg-hook', $phase);
        foreach (['Husky', 'CaptainHook', 'GrumPHP', 'Lefthook', 'core.hooksPath', '.git/hooks/commit-msg'] as $term) {
            self::assertStringContainsString($term, $phase);
        }
        self::assertStringContainsString('never overwrit', $content);
        self::assertStringContainsString('commit-msg hook wired in', $content);

        self::assertSame(0, preg_match('/[\x{00C4}\x{00D6}\x{00DC}\x{00E4}\x{00F6}\x{00FC}\x{00DF}]/u', $content), 'The body is English.');
        self::assertSame([], (new CheckSkillLinks())($this->skillFile('git-setup-repository')));
    }

    public function testComplianceSkillWarnsOnMissingCommitMsgHook(): void
    {
        $content = (string) file_get_contents($this->skillFile('git-check-compliance'));

        self::assertStringNotContainsString('10 checks', $content);
        self::assertStringContainsString('11 checks for git hooks', $content);
        self::assertStringContainsString('### The 11 checks', $content);
        self::assertStringContainsString('commit-msg hook wired (a warning when missing, never a FAIL)', $content);
        self::assertStringContainsString('[WARN] commit-msg hook missing', $content);

        $start = (int) strpos($content, '### The 11 checks');
        $end   = (int) strpos($content, '### Output format');
        preg_match_all('/^(\d+)\. /m', substr($content, $start, $end - $start), $matches);
        self::assertSame(range(1, 11), array_map('intval', $matches[1]));
    }

    public function testFoundationPhpOmitsReleaseLifecycleAndNamespaceAssignment(): void
    {
        $content = (string) file_get_contents($this->skillFile('foundation-php'));

        foreach (['Release', 'Packagist', 'release lifecycle', 'JardisCore', 'Application/Delivery'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $content,
                sprintf('foundation-php must not contain "%s".', $forbidden),
            );
        }
    }

    #[DataProvider('foundationSkills')]
    public function testFoundationSkillLinksResolveToShippedSkills(string $name): void
    {
        $file = $this->skillFile($name);

        self::assertSame([], (new CheckSkillLinks())($file));

        $document = (new ParseSkillFrontmatter())((string) file_get_contents($file));
        self::assertNotNull($document);
        foreach (['prerequisites', 'next'] as $field) {
            $links = $document['fields'][$field] ?? [];
            self::assertIsArray($links);
            foreach ($links as $link) {
                self::assertIsString($link);
                self::assertStringStartsNotWith('knowledge-', $link);
                self::assertStringStartsNotWith('process-', $link);
                self::assertFileExists($this->skillFile($link));
            }
        }
    }

    public function testKnowledgeSkillsAreBundledWithTemplates(): void
    {
        foreach (self::KNOWLEDGE_SKILLS as $name) {
            $file = $this->skillFile($name);

            self::assertFileExists($file, sprintf('Skill %s is not bundled.', $name));

            $document = (new ParseSkillFrontmatter())((string) file_get_contents($file));
            self::assertNotNull($document, sprintf('Skill %s has no frontmatter.', $name));
            self::assertSame($name, $document['fields']['name'] ?? null);
            self::assertSame('process', $document['fields']['zone'] ?? null);
            self::assertSame('O', $document['fields']['persona'] ?? null);
            self::assertSame([], (new CheckSkillLinks())($file));
        }

        $templates = dirname($this->skillFile('knowledge-maintain-pool')) . '/templates';
        self::assertFileExists($templates . '/INDEX.md');
        self::assertFileExists($templates . '/themenseite.md');
        self::assertLessThan(10240, (int) filesize($templates . '/INDEX.md'));

        foreach (self::KNOWLEDGE_SKILLS as $name) {
            $document = (new ParseSkillFrontmatter())((string) file_get_contents($this->skillFile($name)));
            self::assertNotNull($document);
            $chain = $document['fields']['prerequisites'] ?? [];
            self::assertIsArray($chain);
            self::assertContains(
                'foundation-working-principles',
                $chain,
                sprintf('%s must chain to foundation-working-principles.', $name),
            );
        }

        $record = (new ParseSkillFrontmatter())((string) file_get_contents($this->skillFile('knowledge-record-decision')));
        self::assertNotNull($record);
        $prerequisites = $record['fields']['prerequisites'] ?? [];
        self::assertIsArray($prerequisites);
        self::assertContains('foundation-working-principles', $prerequisites);
        self::assertNotContains('process-close', $prerequisites);
    }

    public function testThemenseiteTemplateHasFiveSectionsInOrder(): void
    {
        $content = (string) file_get_contents(
            dirname($this->skillFile('knowledge-maintain-pool')) . '/templates/themenseite.md',
        );

        preg_match_all('/^## .+$/m', $content, $matches);
        self::assertSame(
            ['## Stand', '## Entscheide', '## Fallen', '## Ersetzt', '## Verweise'],
            $matches[0],
        );

        foreach (['id:', 'description:', 'heimat:', 'ersetzt: []', 'schema_version: 1.0.0'] as $key) {
            self::assertStringContainsString($key, $content);
        }
    }

    public function testRecordDecisionNamesBothNoteFormsWithEnDash(): void
    {
        $content = (string) file_get_contents($this->skillFile('knowledge-record-decision'));

        self::assertStringContainsString('Wissen: <seite>#<abschnitt>', $content);
        self::assertStringContainsString("Wissen: keins \u{2013} <Grund>", $content);
        self::assertStringNotContainsString("Wissen: keins \u{2014}", $content);
        self::assertStringNotContainsString('Wissen: keins - ', $content);

        foreach (['stand', 'entscheide', 'fallen', 'ersetzt', 'verweise'] as $section) {
            self::assertStringContainsString('`' . $section . '`', $content);
        }
    }

    #[DataProvider('knowledgeSkills')]
    public function testKnowledgeDescriptionsHaveAtMostFortyFiveWords(string $name): void
    {
        $document = (new ParseSkillFrontmatter())((string) file_get_contents($this->skillFile($name)));
        self::assertNotNull($document);

        $description = $document['fields']['description'] ?? '';
        self::assertIsString($description);

        $words = preg_split('/\s+/', trim($description), -1, PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($words);
        self::assertNotEmpty($words);
        self::assertLessThanOrEqual(45, count($words), sprintf('Description of %s has %d words.', $name, count($words)));
    }

    public function testProcessSkillsHaveZoneProcessPersonaOAndShortDescription(): void
    {
        foreach (self::PROCESS_SKILLS as $name) {
            $file = $this->skillFile($name);

            self::assertFileExists($file, sprintf('Skill %s is not bundled.', $name));

            $document = (new ParseSkillFrontmatter())((string) file_get_contents($file));
            self::assertNotNull($document, sprintf('Skill %s has no frontmatter.', $name));
            self::assertSame($name, $document['fields']['name'] ?? null);
            self::assertSame('process', $document['fields']['zone'] ?? null);
            self::assertSame('O', $document['fields']['persona'] ?? null);
            self::assertSame([], (new CheckSkillLinks())($file));

            $description = $document['fields']['description'] ?? '';
            self::assertIsString($description);
            $words = preg_split('/\s+/', trim($description), -1, PREG_SPLIT_NO_EMPTY);
            self::assertIsArray($words);
            self::assertNotEmpty($words);
            self::assertLessThanOrEqual(45, count($words), sprintf('Description of %s has %d words.', $name, count($words)));
        }
    }

    public function testProcessSkillFilesAreEnglishAndCarryNoPrivateTerms(): void
    {
        $files = $this->processFiles();
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            $label   = substr($file, strlen(dirname(__DIR__, 4)) + 1);

            self::assertSame(0, preg_match('/[\x{00C4}\x{00D6}\x{00DC}\x{00E4}\x{00F6}\x{00FC}\x{00DF}]/u', $content), $label . ' is English.');
            foreach (['make qa', 'prozess-check', 'kosten', 'beweggruende'] as $term) {
                self::assertStringNotContainsStringIgnoringCase($term, $content, sprintf('%s must not contain "%s".', $label, $term));
            }
            self::assertSame(
                0,
                preg_match('/\b(opus|sonnet|haiku|fable|rolf)\b/i', $content),
                $label . ' names no model or person.',
            );
        }
    }

    public function testChooseTierNamesFourTiersAndBothMarkers(): void
    {
        $content = (string) file_get_contents($this->skillFile('process-choose-tier'));

        foreach (['**0 Answer**', '**1 Single action**', '**2 Small assignment**', '**3 Undertaking**'] as $tier) {
            self::assertStringContainsString($tier, $content);
        }

        $escalate = strpos($content, '<!-- rule:tier-escalate -->');
        $offer    = strpos($content, '<!-- rule:chat-end-offer -->');
        self::assertIsInt($escalate);
        self::assertIsInt($offer);
        foreach (['only with a named reason', 'the lower tier', 'two or more subtasks are never'] as $keyword) {
            self::assertStringContainsString($keyword, $content);
        }
        foreach (['create a project folder?', 'docs/vorhaben/', 'carry knowledge into the pool?'] as $keyword) {
            self::assertStringContainsString($keyword, substr($content, $offer));
        }
        self::assertLessThan(
            (int) strpos($content, 'carry knowledge into the pool?'),
            (int) strpos($content, 'create a project folder?'),
            'Chat, then project documents, then knowledge.',
        );

        self::assertStringNotContainsStringIgnoringCase('builder', $content);
        self::assertStringNotContainsString('jardis ui', $content);

        $document = (new ParseSkillFrontmatter())($content);
        self::assertNotNull($document);
        self::assertSame(['process-check-existing', 'process-concept'], $document['fields']['next'] ?? null);
        self::assertLessThanOrEqual(250, substr_count($content, "\n"));
    }

    public function testProgressTemplatePassesPoolCheck(): void
    {
        $root     = dirname(__DIR__, 4);
        $template = $root . '/skills/process-concept/templates/PROGRESS.md';
        self::assertFileExists($template);

        $content = (string) file_get_contents($template);
        self::assertLessThanOrEqual(60, substr_count($content, "\n"));
        self::assertStringContainsString(
            "## Kopf\n- **Phase:** concept\n- **Stage:** \u{2014}\n- **Next step:** ",
            $content,
        );
        self::assertStringContainsString("\n- **Open decisions:** \u{2014}\n", $content);

        $project = new TempProject();
        try {
            $project->writeFile('.claude/wissen/INDEX.md', "# Knowledge pool\n");
            $project->writeFile('docs/vorhaben/demo/PROGRESS.md', $content);

            $result = RunScript::run($root . '/scripts/pool-check.php', $project->root, ['--root=' . $project->root]);

            self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        } finally {
            $project->cleanup();
        }
    }

    public function testProjectProfileTemplateHasFourTopics(): void
    {
        $file = dirname(__DIR__, 4) . '/skills/process-concept/templates/PROJECT_PROFILE.md';
        self::assertFileExists($file);

        $content = (string) file_get_contents($file);
        preg_match_all('/^## .+$/m', $content, $matches);
        self::assertSame(['## QA entry', '## Ports', '## Build', '## Pitfalls'], $matches[0]);
        self::assertStringContainsString('<make target or command>', $content);
        self::assertStringContainsString('<host port>', $content);
        self::assertStringContainsString('Test call', $content);
    }

    public function testConceptSkillCarriesBothSetUpRulesAndTheFolderRules(): void
    {
        $content = (string) file_get_contents($this->skillFile('process-concept'));

        $scaffold = strpos($content, '<!-- rule:pool-scaffold -->');
        $profile  = strpos($content, '<!-- rule:project-profile -->');
        self::assertIsInt($scaffold);
        self::assertIsInt($profile);
        self::assertLessThan($profile, $scaffold);
        foreach (['.claude/wissen/', 'is missing'] as $keyword) {
            self::assertStringContainsString($keyword, substr($content, $scaffold, $profile - $scaffold));
        }
        foreach (['.claude/PROJECT_PROFILE.md', 'is missing', 'never overwritten'] as $keyword) {
            self::assertStringContainsString($keyword, substr($content, $profile));
        }
        foreach (['kebab-case', '`process-resume`', 'KONZEPT.html', 'KONZEPT.png', 'Never overwrite', 'not an undertaking'] as $keyword) {
            self::assertStringContainsString($keyword, $content);
        }
        self::assertStringContainsString('`process-docs`', $content);

        $document = (new ParseSkillFrontmatter())($content);
        self::assertNotNull($document);
        self::assertSame(['process-choose-tier'], $document['fields']['prerequisites'] ?? null);
        self::assertLessThanOrEqual(250, substr_count($content, "\n"));
    }

    public function testResumeSkillFindsActiveFileRunsPreflightAndTakesOneStep(): void
    {
        $content = (string) file_get_contents($this->skillFile('process-resume'));

        foreach (['`close`', 'STOPP:', 'Working tree', 'Orphaned agent tasks', 'Artefact freshness', 'Containers and ports', 'PROJECT_PROFILE.md', 'exactly that one action', 'at most 60 lines'] as $keyword) {
            self::assertStringContainsString($keyword, $content);
        }

        $document = (new ParseSkillFrontmatter())($content);
        self::assertNotNull($document);
        self::assertSame(['process-choose-tier'], $document['fields']['prerequisites'] ?? null);
        self::assertLessThanOrEqual(250, substr_count($content, "\n"));
    }

    public function testWriteSkillsChainAndAddWhatThePictureDoesNotShow(): void
    {
        $prd  = (string) file_get_contents($this->skillFile('process-write-prd'));
        $plan = (string) file_get_contents($this->skillFile('process-write-plan'));

        $prdDocument  = (new ParseSkillFrontmatter())($prd);
        $planDocument = (new ParseSkillFrontmatter())($plan);
        self::assertNotNull($prdDocument);
        self::assertNotNull($planDocument);
        self::assertSame(['process-concept'], $prdDocument['fields']['prerequisites'] ?? null);
        self::assertSame(['process-write-plan'], $prdDocument['fields']['next'] ?? null);
        self::assertSame(['process-write-prd'], $planDocument['fields']['prerequisites'] ?? null);

        foreach (['process-concept', 'process-resume'] as $name) {
            $document = (new ParseSkillFrontmatter())((string) file_get_contents($this->skillFile($name)));
            self::assertNotNull($document);
            self::assertSame(['process-write-prd'], $document['fields']['next'] ?? null, $name);
        }

        foreach (['Error cases', 'States', 'Limits', 'Data paths', 'never re-describe', 'docs/vorhaben/<name>/PRD.md', 'The human confirms'] as $keyword) {
            self::assertStringContainsString($keyword, $prd);
        }
        foreach (
            [
                'Stages first, then phases',
                'File list measured, exhaustively',
                'same phase or an earlier one',
                'in every AK list',
                'Every commitment has an AK',
                'never split a phase afterwards',
                'docs/vorhaben/<name>/PLAN.md',
                'The human releases it',
            ] as $keyword
        ) {
            self::assertStringContainsString($keyword, $plan);
        }
        self::assertLessThanOrEqual(250, substr_count($prd, "\n"));
        self::assertLessThanOrEqual(250, substr_count($plan, "\n"));
    }

    public function testWritePlanNamesCapsAndPoolCheckScript(): void
    {
        $content = (string) file_get_contents($this->skillFile('process-write-plan'));

        foreach (['`150`', '`16 KB`', '6 KB', '8 commitments', '30 KB', 'scripts/pool-check.php', '`process-run-stage`'] as $keyword) {
            self::assertStringContainsString($keyword, $content);
        }
        self::assertFileExists(dirname(__DIR__, 4) . '/scripts/pool-check.php');
    }

    public function testWriteSkillsRunBoardsOncePerProject(): void
    {
        $prd  = (string) file_get_contents($this->skillFile('process-write-prd'));
        $plan = (string) file_get_contents($this->skillFile('process-write-plan'));

        foreach ([$prd, $plan] as $content) {
            self::assertStringContainsString('exactly once per undertaking', $content);
            self::assertStringContainsString('blind and in parallel', $content);
            self::assertStringContainsString('`process-review-board`', $content);
        }
        self::assertStringContainsString('before the human confirms the PRD', $prd);
        self::assertStringContainsString('The skeptic is always part of it', $prd);
        self::assertStringContainsString('does not run a second time', $prd);
        self::assertStringContainsString('two roles, architecture and test strategy', $plan);
        self::assertStringContainsString('only when the plan touches new package APIs', $plan);
        self::assertStringContainsString('there is no second run', $plan);
    }

    public function testExistingCapabilityCheckSourceParses(): void
    {
        $file   = dirname(__DIR__, 4) . '/skills/process-review-board/reviewers/existing-capability-check.md';
        $parser = new ParseSkillFrontmatter();

        self::assertFileExists($file);
        $source = (new ParseReviewerSource(static fn (string $content): ?array => $parser($content)))(
            'existing-capability-check',
            (string) file_get_contents($file),
        );

        self::assertNotNull($source);
        self::assertStringContainsString('MET | PARTIAL | NOT FOUND', $source->body);

        $document = $parser((string) file_get_contents($file));
        self::assertNotNull($document);
        self::assertArrayNotHasKey('model', $document['fields']);

        $check = (string) file_get_contents($this->skillFile('process-check-existing'));
        self::assertStringContainsString('../process-review-board/reviewers/existing-capability-check.md', $check);
    }

    /**
     * @return list<string>
     */
    private function processFiles(): array
    {
        $root  = dirname(__DIR__, 4) . '/skills';
        $files = [];
        $dirs  = [...(glob($root . '/process-*', GLOB_ONLYDIR) ?: []), ...(glob($root . '/code-review-change', GLOB_ONLYDIR) ?: [])];
        foreach ($dirs as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator((string) $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if ($entry instanceof \SplFileInfo && $entry->isFile()) {
                    $files[] = $entry->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function knowledgeSkills(): array
    {
        $cases = [];
        foreach (self::KNOWLEDGE_SKILLS as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foundationSkills(): array
    {
        $cases = [];
        foreach (self::FOUNDATION_SKILLS as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    private function skillFile(string $name): string
    {
        return dirname(__DIR__, 4) . '/skills/' . $name . '/SKILL.md';
    }
}
