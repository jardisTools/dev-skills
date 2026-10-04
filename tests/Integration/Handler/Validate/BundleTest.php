<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Validate;

use JardisTools\DevSkills\Data\BundleSkills;
use JardisTools\DevSkills\Data\RenamedSkills;
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
    private const PROCESS_SKILLS = ['process-choose-tier', 'process-check-existing', 'process-concept', 'process-resume', 'process-write-prd', 'process-write-plan', 'process-review-board', 'process-run-stage', 'process-verify', 'process-close', 'code-review-change'];

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

    public function testTheFifteenNewSkillsAreExactlyTheOnesTheDescriptionTestsCover(): void
    {
        $covered = [...self::FOUNDATION_SKILLS, ...self::KNOWLEDGE_SKILLS, ...self::PROCESS_SKILLS];

        $new = $this->newSkillNames();
        sort($covered);

        self::assertCount(15, $new);
        self::assertSame($covered, $new);
    }

    public function testEveryNewSkillDescriptionHasAtMostFortyFiveWordsAndTheListingBudgetIsLogged(): void
    {
        $new = $this->newSkillNames();
        $characters = 0;
        $skills = 0;

        foreach ($this->skillFolderNames() as $name) {
            $description = $this->description($name);
            $characters += mb_strlen($description);
            ++$skills;

            if (!in_array($name, $new, true)) {
                continue;
            }

            $words = preg_split('/\s+/', trim($description), -1, PREG_SPLIT_NO_EMPTY);
            self::assertIsArray($words);
            self::assertNotEmpty($words, sprintf('Skill %s has no description.', $name));
            self::assertLessThanOrEqual(45, count($words), sprintf('Description of %s has %d words.', $name, count($words)));
        }

        // Evidence for the listing budget: every skill description is part of the session listing.
        fwrite(STDERR, sprintf("\ndescriptions: %d skills, %d characters\n", $skills, $characters));

        self::assertSame(33, $skills);
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

    public function testChooseTierCarriesThePresentCriterionAndDecideYourselfRules(): void
    {
        $content = (string) file_get_contents($this->skillFile('process-choose-tier'));

        // Doubt concerns size and risk, never a criterion that is present.
        self::assertStringContainsString('never a criterion that is present', $content);
        self::assertStringContainsString('decides for tier 3, whatever the size', $content);

        $marker = strpos($content, '<!-- rule:decide-yourself-no-tier-drop -->');
        self::assertIsInt($marker, 'Marker rule:decide-yourself-no-tier-drop is missing.');
        $rule = substr($content, $marker, 700);
        foreach (['decide open points yourself', 'proceed autonomously', 'lowers no tier', 'waives no gate', 'open-question gate'] as $keyword) {
            self::assertStringContainsString($keyword, $rule, $keyword);
        }
    }

    public function testGateWaiverIsStatedWhereTheTierThreePathNeverLoadsChooseTier(): void
    {
        // E7 rauchlauf 2: the sentence stood only in process-choose-tier, which the tier-3 path does not load.
        $paragraphs = [];
        foreach (['process-concept', 'process-write-prd'] as $skill) {
            $content = (string) file_get_contents($this->skillFile($skill));
            $marker  = strpos($content, '<!-- rule:decide-yourself-no-gate-waiver -->');
            self::assertIsInt($marker, $skill . ' lacks the marker rule:decide-yourself-no-gate-waiver.');
            $rule = substr($content, $marker, (int) strpos($content, "\n\n", $marker) - $marker);
            foreach (
                [
                    'decide open points yourself',
                    'proceed autonomously',
                    'waives no gate',
                    'never settled by the main session',
                    'open-question gate',
                    '`open-question-gate`',
                    '`process-run-stage`',
                    'STOPP: <YYYY-MM-DD> · <question>',
                    'at the next approval',
                ] as $keyword
            ) {
                self::assertStringContainsString($keyword, $rule, $skill . ': ' . $keyword);
            }
            $paragraphs[$skill] = $rule;
        }

        self::assertSame($paragraphs['process-concept'], $paragraphs['process-write-prd'], 'one marker, one wording in both skills');
    }

    public function testCloseLoadsTheRecordSkillBeforeTheFirstPoolWrite(): void
    {
        $content = (string) file_get_contents($this->skillFile('process-close'));
        $marker  = strpos($content, '<!-- rule:close-lessons-via-skill -->');
        self::assertIsInt($marker, 'Marker rule:close-lessons-via-skill is missing.');
        $rule = substr($content, $marker, (int) strpos($content, "\n\n", $marker) - $marker);

        foreach (['Load `knowledge-record-decision`', 'before the first write', 'topic page', 'never from memory'] as $keyword) {
            self::assertStringContainsString($keyword, $rule, $keyword);
        }
        // the marker sits in section 3 (lessons), before the docs sync of section 4
        self::assertLessThan((int) strpos($content, '### 4. Docs sync'), $marker);
        self::assertGreaterThan((int) strpos($content, '### 3. Lessons into the pool'), $marker);
    }

    public function testMaintainPoolDescribesTheScaffoldAsTheIndexAlone(): void
    {
        $content = (string) file_get_contents($this->skillFile('knowledge-maintain-pool'));
        $start   = (int) strpos($content, '### 2. The scaffold');
        $section = substr($content, $start, (int) strpos($content, '### 3.', $start) - $start);

        self::assertStringContainsString('only `INDEX.md`', $section);
        self::assertStringContainsString('`templates/themenseite.md`', $section);
        self::assertStringNotContainsString('and the topic-page template', $section);
    }

    public function testVerifyAndResumePointAtTheCommitGateInTheirFixAndResumeStep(): void
    {
        // E7 rauchlauf 3: after a fix run the session committed itself; the gate stood only in process-run-stage.
        foreach (['process-verify' => '### 4. After the verdict', 'process-resume' => '### 4. Take up one step'] as $skill => $heading) {
            $content = (string) file_get_contents($this->skillFile($skill));
            $start   = strpos($content, $heading);
            self::assertIsInt($start, $skill);
            $section = substr($content, $start, (int) strpos($content, "\n### 5.", $start) - $start);

            self::assertStringContainsString('human gate (`process-run-stage`)', $section, $skill);
            self::assertStringNotContainsString('<!-- rule:commit-is-human-gate -->', $content, $skill . ': a reference, no second wording');
        }
    }

    public function testClosePoolCheckRunsAfterTheDeleteAndSourcesLeaveTheFolder(): void
    {
        // E7 rauchlauf 3: pool-check ran before the folder was deleted, so sources into it passed.
        $content = (string) file_get_contents($this->skillFile('process-close'));
        $marker  = strpos($content, '<!-- rule:close-pool-check-after-delete -->');
        self::assertIsInt($marker, 'Marker rule:close-pool-check-after-delete is missing.');
        $rule = substr($content, $marker, (int) strpos($content, "\n\n", $marker) - $marker);

        foreach (
            [
                'no source of a pool page',
                '`docs/vorhaben/<name>/`',
                'commit hash or the digest',
                'after the delete',
                'pool-check.php',
                'only from this run',
            ] as $keyword
        ) {
            self::assertStringContainsString($keyword, $rule, $keyword);
        }
        // the marker sits in section 6, after the delete order and before the retro of section 7
        self::assertGreaterThan((int) strpos($content, '### 6. Delete the folder'), $marker);
        self::assertLessThan((int) strpos($content, '### 7. Retro'), $marker);
        self::assertGreaterThan((int) strpos($content, '**delete** `docs/vorhaben/<name>/`'), $marker);
        // DS5: the delete comes after the delivery (the folder is deleted only after it has been delivered)
        self::assertLessThan((int) strpos($content, '### 6. Delete the folder'), (int) strpos($content, '### 5. Delivery'));
    }

    public function testReviewBoardSendsAnOpenQuestionToTheGateBeforeAStop(): void
    {
        // E7 rauchlauf 3: the board paragraph sent the open question straight to the human, against process-run-stage.
        $content = (string) file_get_contents($this->skillFile('process-review-board'));
        $marker  = strpos($content, '<!-- rule:question-points -->');
        self::assertIsInt($marker);
        $rule = substr($content, $marker, (int) strpos($content, "\n\n", $marker) - $marker);

        self::assertStringContainsString('at most 2 roles', $rule);
        foreach (
            [
                'goes first to the open-question gate',
                '`open-question-gate`',
                '`process-run-stage`',
                'only when the gate cannot decide',
                'STOPP: <YYYY-MM-DD> · <question>',
            ] as $keyword
        ) {
            self::assertStringContainsString($keyword, $rule, $keyword);
        }
        self::assertLessThan(
            (int) strpos($rule, 'STOPP: <YYYY-MM-DD>'),
            (int) strpos($rule, 'open-question gate'),
            'The gate comes before the stop.',
        );
    }

    public function testConceptShowsTheOneLineFormForSeveralOpenQuestionsAndPoolCheckTakesIt(): void
    {
        // E7 rauchlauf 3: with several open questions the session invented a form that pool-check rejected.
        $root    = dirname(__DIR__, 4);
        $content = (string) file_get_contents($this->skillFile('process-concept'));
        $start   = (int) strpos($content, '### 7. Progress file rules');
        $section = substr($content, $start, (int) strpos($content, '### 8.', $start) - $start);

        self::assertSame(
            1,
            preg_match('/`(STOPP: \d{4}-\d{2}-\d{2} · \(1\) [^`]*\(2\) [^`]*)`/u', $section, $form),
            'Section 7 shows one line with two numbered questions.',
        );
        self::assertStringNotContainsString("\n", $form[1]);

        $template = (string) file_get_contents($root . '/skills/process-concept/templates/PROGRESS.md');
        $head     = str_replace("- **Open decisions:** \u{2014}\n", '- **Open decisions:** ' . $form[1] . "\n", $template);
        self::assertNotSame($template, $head);

        $project = new TempProject();
        try {
            $project->writeFile('.claude/wissen/INDEX.md', "# Knowledge pool\n");
            $project->writeFile('docs/vorhaben/demo/PROGRESS.md', $head);

            $result = RunScript::run($root . '/scripts/pool-check.php', $project->root, ['--root=' . $project->root]);

            self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        } finally {
            $project->cleanup();
        }
    }

    public function testCommitAndMergeAreAHumanGateInTheProcessSkills(): void
    {
        $content = (string) file_get_contents($this->skillFile('process-run-stage'));
        $marker  = strpos($content, '<!-- rule:commit-is-human-gate -->');
        self::assertIsInt($marker, 'Marker rule:commit-is-human-gate is missing.');
        $rule = substr($content, $marker, (int) strpos($content, "\n\n", $marker) - $marker);

        foreach (
            [
                'gate of the human',
                'branch',
                'commit',
                'merge',
                'started by the human',
                'against the phase scope',
                'commit message from the report',
                'asks the human',
                '`git log`',
                // E7 fix 4: the head update is a commit gate of its own; no merge request over an unclean tree
                'working tree is clean',
                'head update',
                'uncommitted',
                '`git status`',
                'never asks for the merge',
                // E7 fix 5: one halt names exactly one gate; the next gate is named only once the step before stands in `git log`
                'One halt, one gate',
                'exactly one git step',
                'names the next gate only once',
                // E7 P7.4: the opt-out lifts the gates only when the router names them no more; in doubt they apply
                'git-rules',
                'only when the router of the project does not name them',
                'follows the git rules of the project',
                'also when unsure',
                // the third stance: with "delegated" the session creates branch and commits itself, the merge stays a gate
                '"git-rules": "delegated"',
                'only the branch and the commit lapse',
                'creates the branch and makes the commits (steps 3 and 8) itself',
                'reports the hash it has seen in `git log`',
                'the merge (step 9) stays a gate of the human',
            ] as $keyword
        ) {
            self::assertStringContainsString($keyword, $rule, $keyword);
        }

        // the loop steps point at the gate instead of telling the session to commit or merge itself
        self::assertStringNotContainsString('the main session commits', $content);
        self::assertStringNotContainsString('then commits with', $content);
        // three names since E7 fix 4: steps 3 (commit), 8 (head update, commit gate of its own) and 9 (merge) of the loop name the gate
        self::assertSame(3, substr_count($content, 'human gate'), 'steps 3, 8 and 9 of the loop name the gate');

        $loopStart = strpos($content, '### 2. The stage loop');
        self::assertIsInt($loopStart);
        $loop = substr($content, $loopStart, (int) strpos($content, '### 3.', $loopStart) - $loopStart);
        foreach (
            [
                '8. **Head update.**',
                'working tree is clean',
                '`git-commit-change`',
                '9. **Merge**',
                '`git status`',
            ] as $keyword
        ) {
            self::assertStringContainsString($keyword, $loop, $keyword);
        }
        self::assertLessThan(strpos($loop, '9. **Merge**'), strpos($loop, '8. **Head update.**'), 'head update comes before the merge step');

        // the delivery step of process-close points at the same gate, with no second wording of the rule
        $close = (string) file_get_contents($this->skillFile('process-close'));
        self::assertStringContainsString('human gate (`process-run-stage`)', $close);
        self::assertStringContainsString('with the delegated git rules the session makes the commits itself', $close);
        self::assertStringContainsString('with the delegated git rules the session makes the commits itself', (string) file_get_contents($this->skillFile('process-resume')));
        self::assertStringNotContainsString('<!-- rule:commit-is-human-gate -->', $close);
    }

    public function testHeadUpdateHaltNamesOnlyTheCommit(): void
    {
        // E7 mini run 5: the halt at the head-update commit listed the commit and the merge as two gates.
        $content = (string) file_get_contents($this->skillFile('process-run-stage'));
        $start   = strpos($content, '8. **Head update.**');
        self::assertIsInt($start);
        $step = substr($content, $start, (int) strpos($content, "\n9. **Merge**", $start) - $start);

        foreach (
            [
                'names only the commit',
                'does not mention the merge (step 9)',
                'neither as a request nor as a second gate in a list',
                'stands in `git log`',
                '`git status` is empty',
            ] as $keyword
        ) {
            self::assertStringContainsString($keyword, $step, $keyword);
        }
    }

    public function testMergeTargetIsNamedFromExistingBranchesNeverInvented(): void
    {
        // E7 mini run git gates: the session named `develop` as merge target, the project had none.
        $content = (string) file_get_contents($this->skillFile('process-run-stage'));
        $start   = strpos($content, '9. **Merge**');
        self::assertIsInt($start);
        $step = substr($content, $start, (int) strpos($content, "\n\n", $start) - $start);

        foreach (
            [
                'existing branches',
                '`git branch -a`',
                'git flow of the project',
                'is missing',
                'at the gate',
            ] as $keyword
        ) {
            self::assertStringContainsString($keyword, $step, $keyword);
        }
    }

    public function testFailurePathStartsTheFixRunWithoutQuestionAndNeverEditsThePlanItself(): void
    {
        // E7 mini run git gates: after RED the session asked "start the fix run?"; a plan deviation was corrected
        // in the plan by the session itself and logged without the open-question gate.
        $content = (string) file_get_contents($this->skillFile('process-run-stage'));
        $marker  = strpos($content, '<!-- rule:failure-path -->');
        self::assertIsInt($marker);
        $rule = substr($content, $marker, (int) strpos($content, "\n\n", $marker) - $marker);

        foreach (
            [
                'without a question to the human',
                'no question point',
                'deviation from the plan',
                'before the fix brief',
                'open-question gate',
                'never changes the plan on its own decision',
                '`Decisions delegated`',
                'decided',
            ] as $keyword
        ) {
            self::assertStringContainsString($keyword, $rule, $keyword);
        }

        // the question-point paragraph keeps its sentence about the plan correction after the gate's answer
        self::assertStringContainsString('corrects the plan where the answer deviates', $content);
        // E7-fix-minors: section 6 must not read against section 8; the old absolute wording is gone
        self::assertStringNotContainsString('never changes the plan itself', $content);
    }

    public function testVerifyRedPointsAtTheFailurePathWithoutSecondWording(): void
    {
        // E7 mini run git gates: only process-verify was loaded after RED, so the session asked before the fix run.
        $content = (string) file_get_contents($this->skillFile('process-verify'));
        $start   = strpos($content, '### 4. After the verdict');
        self::assertIsInt($start);
        $section = substr($content, $start, (int) strpos($content, "\n### 5.", $start) - $start);

        foreach (
            [
                'load `process-run-stage`',
                'its failure path applies',
                'without a question to the human',
                'human gate (`process-run-stage`)',
            ] as $keyword
        ) {
            self::assertStringContainsString($keyword, $section, $keyword);
        }
        self::assertStringNotContainsString('<!-- rule:failure-path -->', $content, 'a reference, no second wording');
        self::assertStringNotContainsString('never changes the plan on its own decision', $content, 'a reference, no second wording');
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

    public function testHeadValuesTheSkillsPrescribePassPoolCheck(): void
    {
        $root     = dirname(__DIR__, 4);
        $template = (string) file_get_contents($root . '/skills/process-concept/templates/PROGRESS.md');
        $plan     = (string) file_get_contents($this->skillFile('process-write-plan'));
        self::assertStringContainsString('phase `stage`, stage `E1/<total>`', $plan);
        self::assertStringContainsString('`E<n>/<total>`', $plan);

        $head = static fn (string $stage): string => str_replace(
            ["- **Phase:** concept\n", "- **Stage:** \u{2014}\n"],
            ["- **Phase:** stage\n", '- **Stage:** ' . $stage . "\n"],
            $template,
        );

        $exits = [];
        foreach (['E1/3', 'E1'] as $stage) {
            $project = new TempProject();
            try {
                $project->writeFile('.claude/wissen/INDEX.md', "# Knowledge pool\n");
                $project->writeFile('docs/vorhaben/demo/PROGRESS.md', $head($stage));

                $result = RunScript::run($root . '/scripts/pool-check.php', $project->root, ['--root=' . $project->root]);
                $exits[$stage] = $result['exit'];
                if ($stage === 'E1') {
                    self::assertStringContainsString("'E<n>/<total>'", $result['stdout'] . $result['stderr']);
                }
            } finally {
                $project->cleanup();
            }
        }

        self::assertSame(['E1/3' => 0, 'E1' => 1], $exits);
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
        // E7 P7.3 fix 3 (PRD R16): the scaffold is the index alone; the topic page stays a template in the skill.
        $scaffoldRule = substr($content, $scaffold, $profile - $scaffold);
        self::assertStringContainsString('only `INDEX.md`', $scaffoldRule);
        self::assertStringContainsString('`themenseite.md` stays a template', $scaffoldRule);
        self::assertStringNotContainsString('`INDEX.md` and `themenseite.md`', $scaffoldRule);
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
        self::assertSame(['process-run-stage'], $planDocument['fields']['next'] ?? null);

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

    public function testEveryReviewerSourceParsesCarriesReturnAndNoneExistsForGo(): void
    {
        $dir    = dirname(__DIR__, 4) . '/skills/process-review-board/reviewers';
        $parser = new ParseSkillFrontmatter();
        $parse  = new ParseReviewerSource(static fn (string $content): ?array => $parser($content));

        $roles = array_map(
            static fn (string $file): string => basename($file, '.md'),
            glob($dir . '/*') ?: [],
        );
        sort($roles);

        // The names of all nineteen sources are pinned in BundleReviewersTest.
        self::assertCount(19, $roles);
        foreach ($roles as $role) {
            self::assertSame(0, preg_match('/(^|-)go($|-)/', $role), 'No reviewer source exists for Go.');
            self::assertStringNotContainsString('senior', $role);
        }

        foreach ($roles as $role) {
            $content = (string) file_get_contents($dir . '/' . $role . '.md');
            $source  = $parse($role, $content);

            self::assertNotNull($source, sprintf('Reviewer source %s does not parse.', $role));
            self::assertNotSame('', $source->body);
            self::assertStringContainsString('## Return', $source->body, $role);

            $document = $parser($content);
            self::assertNotNull($document);
            self::assertArrayNotHasKey('model', $document['fields'], $role);
        }
    }

    public function testReviewBoardCarriesQuestionPointsMarker(): void
    {
        $content = (string) file_get_contents($this->skillFile('process-review-board'));

        $marker = strpos($content, '<!-- rule:question-points -->');
        self::assertIsInt($marker);
        $rule = substr($content, $marker, 1200);
        foreach (['at most 2 roles', 'exactly once per undertaking', 'STOPP:'] as $keyword) {
            self::assertStringContainsString($keyword, $rule);
        }

        foreach (
            [
                'parallel',
                'one after another, each with a fresh context per role',
                '`claude -p "',
                '`codex exec "',
                '`agent -p "',
                '`copilot -p "',
                '`gemini -p "',
                'deadline in tool calls',
                'Rule on every finding',
                'Every fork goes to the human',
                'blind',
            ] as $keyword
        ) {
            self::assertStringContainsString($keyword, $content);
        }

        self::assertStringContainsString(
            '`packages-find-existing`',
            (string) file_get_contents(dirname(__DIR__, 4) . '/skills/process-review-board/reviewers/plan-review-packages.md'),
        );

        $document = (new ParseSkillFrontmatter())($content);
        self::assertNotNull($document);
        self::assertSame(['foundation-working-principles'], $document['fields']['prerequisites'] ?? null);
        self::assertLessThanOrEqual(250, substr_count($content, "\n"));
    }

    public function testRunStageNamesFallbackAndNeverMakeQa(): void
    {
        $content = (string) file_get_contents($this->skillFile('process-run-stage'));

        $document = (new ParseSkillFrontmatter())($content);
        self::assertNotNull($document);
        self::assertSame(['process-write-plan'], $document['fields']['prerequisites'] ?? null);
        self::assertLessThanOrEqual(250, substr_count($content, "\n"));

        $fresh    = strpos($content, '<!-- rule:fresh-session-per-stage -->');
        $failure  = strpos($content, '<!-- rule:failure-path -->');
        $question = strpos($content, '<!-- rule:question-points -->');
        self::assertIsInt($fresh);
        self::assertIsInt($failure);
        self::assertIsInt($question);
        self::assertStringContainsString('fresh agent session', substr($content, $fresh, 600));
        self::assertStringContainsString('head of the progress file and the brief', substr($content, $fresh, 600));
        foreach (['fix run', 'follow-up run', 'STOPP:', 'does not debug', 'failure-diagnosis'] as $keyword) {
            self::assertStringContainsString($keyword, substr($content, $failure, 1400), $keyword);
        }
        foreach (['at most 2 question points', 'STOPP:', 'open-question gate', 'next stronger tier'] as $keyword) {
            self::assertStringContainsString($keyword, substr($content, $question, 2200), $keyword);
        }

        foreach (
            [
                'at most 6 KB',
                'at most 8',
                'at most 30 KB',
                'once per stage',
                'never inside a sub-agent',
                'QA entry of `.claude/PROJECT_PROFILE.md`',
                'The sight gate always blocks, headless as well',
                'file and section of the target picture',
                'git worktree',
                'one after another, each in a fresh context',
                '`claude -p "',
                '`codex exec "',
                '`agent -p "',
                '`copilot -p "',
                '`gemini -p "',
            ] as $keyword
        ) {
            self::assertStringContainsString($keyword, $content, $keyword);
        }

        self::assertStringNotContainsStringIgnoringCase('make qa', $content);
    }

    public function testVerifyChainsBetweenRunStageAndClose(): void
    {
        $chain = [
            'process-run-stage' => ['prerequisites' => ['process-write-plan'], 'next' => ['process-verify']],
            'process-verify' => ['prerequisites' => ['process-run-stage'], 'next' => ['process-close']],
        ];
        foreach ($chain as $name => $expected) {
            $document = (new ParseSkillFrontmatter())((string) file_get_contents($this->skillFile($name)));
            self::assertNotNull($document);
            self::assertSame($expected['prerequisites'], $document['fields']['prerequisites'] ?? null, $name);
            self::assertSame($expected['next'], $document['fields']['next'] ?? null, $name);
        }

        $content = (string) file_get_contents($this->skillFile('process-verify'));
        self::assertLessThanOrEqual(250, substr_count($content, "\n"));
        foreach (
            [
                'once per stage',
                'blind',
                'doer and checker are two sessions',
                'at most `5`',
                'at most `3`',
                'once per undertaking',
                'end to end',
                'reviewers/stage-verifier.md',
                'reviewers/acceptance-gate.md',
            ] as $keyword
        ) {
            self::assertStringContainsString($keyword, $content, $keyword);
        }
    }

    public function testCloseChainsToRecordDecision(): void
    {
        $content  = (string) file_get_contents($this->skillFile('process-close'));
        $document = (new ParseSkillFrontmatter())($content);
        self::assertNotNull($document);
        self::assertSame(['process-verify'], $document['fields']['prerequisites'] ?? null);
        self::assertSame(['knowledge-record-decision'], $document['fields']['next'] ?? null);
        self::assertSame([], (new CheckSkillLinks())($this->skillFile('process-close')));
        self::assertLessThanOrEqual(250, substr_count($content, "\n"));

        $record = (new ParseSkillFrontmatter())((string) file_get_contents($this->skillFile('knowledge-record-decision')));
        self::assertNotNull($record);
        self::assertSame(['foundation-working-principles', 'knowledge-maintain-pool'], $record['fields']['prerequisites'] ?? null);

        foreach (
            [
                'Fix now',
                'Let the human decide now',
                'Strike',
                'Own item',
                'Collective blocks are forbidden',
                'docs/digests/digest-<name>-<jjjj-mm>.md',
                '`process-docs` set to `local`',
                'never stage it',
                '**delete** `docs/vorhaben/<name>/`',
                'Deliver **once**',
                'Retro',
            ] as $keyword
        ) {
            self::assertStringContainsString($keyword, $content, $keyword);
        }
    }

    public function testClosingResultsAreLinesInTheProgressFileInTheDs5Order(): void
    {
        // DS5: the three line grammars are the contract between the skills, the template and the reader of the progress file.
        $grammar = [
            'process-concept/templates/PROGRESS.md' => ['- <n>: met|gap|deferred — <evidence>', '- <text> — resolved|backlog|rejected — <due sentence>', '- yes|no — <entry>', '## Acceptance check', '## Open points', '## Knowledge'],
            'process-verify/SKILL.md'               => ['`- <n>: met|gap|deferred — <evidence>`', 'not checked', 'Only when the human accepts'],
            'process-close/SKILL.md'                => ['`- <text> — resolved|backlog|rejected — <due sentence>`', '`- yes|no — <entry>`', 'nothing to propose'],
        ];
        foreach ($grammar as $file => $keywords) {
            $content = (string) file_get_contents(dirname(__DIR__, 4) . '/skills/' . $file);
            foreach ($keywords as $keyword) {
                self::assertStringContainsString($keyword, $content, $file . ': ' . $keyword);
            }
        }

        $close = (string) file_get_contents($this->skillFile('process-close'));
        $order = ['### 2. Carry-over triage', '### 3. Lessons into the pool', '### 4. Docs sync and digest', '### 5. Delivery', '### 6. Delete the folder'];
        $last  = -1;
        foreach ($order as $heading) {
            $position = strpos($close, $heading);
            self::assertIsInt($position, $heading);
            self::assertGreaterThan($last, $position, $heading);
            $last = $position;
        }
    }

    public function testBriefTemplateCarriesCapsAndReturnSchema(): void
    {
        $file = dirname(__DIR__, 4) . '/skills/process-run-stage/templates/brief.md';
        self::assertFileExists($file);

        $content = (string) file_get_contents($file);
        self::assertLessThanOrEqual(6144, strlen($content));
        self::assertStringStartsWith('<!-- Caps:', $content);
        foreach (['6 KB', '8 commitments', '30 KB'] as $cap) {
            self::assertStringContainsString($cap, substr($content, 0, (int) strpos($content, '-->')), $cap);
        }

        preg_match_all('/^## .+$/m', $content, $matches);
        self::assertSame(
            ['## Inputs (read in this order)', '## Commitments (at most 8)', '## Limits', '## Gates', '## Return schema'],
            $matches[0],
        );
        self::assertStringContainsString('**Assignment:**', $content);
        self::assertStringContainsString('**Target artefact:**', $content);

        $schema = substr($content, (int) strpos($content, '## Return schema'));
        foreach (['STATUS:', 'FILES:', 'DECISIONS:', 'QA:', 'COMMIT-MSG:', 'STATE:', 'VIEW:', 'NEXT STEP:'] as $key) {
            self::assertStringContainsString($key, $schema, $key);
        }
        self::assertStringContainsString('the sight gate always blocks', $content);
        self::assertStringContainsString('"same" or "differs in ..."', $content);
        self::assertStringEndsWith("Return exactly ONE final report in this schema.\n", $content);
        self::assertStringNotContainsString('/Users/', $content);
        self::assertStringNotContainsString('/home/', $content);
    }

    public function testCodeReviewChangeHasPhpDeltaAndNoGoDelta(): void
    {
        $dir     = dirname(__DIR__, 4) . '/skills/code-review-change';
        $content = (string) file_get_contents($dir . '/SKILL.md');

        self::assertFileExists($dir . '/php-delta.md');
        self::assertSame(['SKILL.md', 'php-delta.md'], array_map('basename', glob($dir . '/*') ?: []), 'No Go delta ships.');
        self::assertLessThanOrEqual(250, substr_count($content, "\n"));
        self::assertStringContainsString('`php-delta.md`', $content);
        self::assertStringContainsString('never edits code', $content);
        self::assertStringContainsString('a finding, not an intervention', $content);
        foreach (['## Code review:', '### Blocker (must be fixed)', '### Major (should be fixed)', '### Minor (suggestion)', '### Positive (done well)'] as $keyword) {
            self::assertStringContainsString($keyword, $content, $keyword);
        }
        foreach (['Typing', 'Security', 'Error handling', 'API design', 'Performance'] as $section) {
            self::assertMatchesRegularExpression('/^### \d+\. ' . preg_quote($section, '/') . '/mi', $content, $section);
        }
        self::assertStringContainsString('declare(strict_types=1)', (string) file_get_contents($dir . '/php-delta.md'));
        self::assertDoesNotMatchRegularExpression('/\bgo\.mod\b|\.go\b|goroutine/i', $content . (string) file_get_contents($dir . '/php-delta.md'));
    }

    public function testStartOrientationNamesEverySkillOfTheBundle(): void
    {
        $content = (string) file_get_contents($this->skillFile('start-orientation'));

        foreach (BundleSkills::NAMES as $name) {
            if ($name === 'start-orientation') {
                continue;
            }
            self::assertStringContainsString('`' . $name . '`', $content, $name);
        }
        foreach (['**0 Answer**', '**1 Single action**', '**2 Small assignment**', '**3 Undertaking**', 'packages → schema → design → code'] as $keyword) {
            self::assertStringContainsString($keyword, $content, $keyword);
        }
        self::assertLessThanOrEqual(225, substr_count($content, "\n") + 1);
    }

    public function testUninstallNameListEqualsSkillFolders(): void
    {
        $folders = array_map('basename', glob(dirname(__DIR__, 4) . '/skills/*', GLOB_ONLYDIR) ?: []);
        $names   = BundleSkills::NAMES;
        sort($folders);
        sort($names);

        self::assertSame($folders, $names);
        self::assertCount(33, $names);
        self::assertSame($names, array_values(array_unique($names)));
    }

    public function testNoSkillBodyNamesARetiredSkill(): void
    {
        $root  = dirname(__DIR__, 4) . '/skills';
        $files = [
            ...(glob($root . '/*/SKILL.md') ?: []),
            ...(glob($root . '/*/templates/*') ?: []),
            ...(glob($root . '/process-review-board/reviewers/*') ?: []),
        ];
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            $label   = substr($file, strlen($root) + 1);

            foreach (array_keys(RenamedSkills::MAPPING) as $retired) {
                self::assertSame(
                    0,
                    preg_match('/(?<![\w-])' . preg_quote($retired, '/') . '(?![\w-])/', $content),
                    sprintf('%s names the retired skill "%s"; use "%s".', $label, $retired, RenamedSkills::MAPPING[$retired]),
                );
            }
        }
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

    /**
     * @return list<string> sorted names of all folders below skills/
     */
    private function skillFolderNames(): array
    {
        $names = [];
        foreach (scandir(dirname(__DIR__, 4) . '/skills') ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($this->skillFile($entry))) {
                $names[] = $entry;
            }
        }
        sort($names);

        return $names;
    }

    /**
     * Skills that are not the renamed ones: every folder minus the values of the renaming table.
     *
     * @return list<string>
     */
    private function newSkillNames(): array
    {
        return array_values(array_diff($this->skillFolderNames(), array_values(RenamedSkills::MAPPING)));
    }

    private function description(string $name): string
    {
        $document = (new ParseSkillFrontmatter())((string) file_get_contents($this->skillFile($name)));
        self::assertNotNull($document, sprintf('Skill %s has no frontmatter.', $name));
        $description = $document['fields']['description'] ?? '';
        self::assertIsString($description);

        return $description;
    }

    private function skillFile(string $name): string
    {
        return dirname(__DIR__, 4) . '/skills/' . $name . '/SKILL.md';
    }

    public function testProfilesAreDistributedAsEightJardisAndTwentyFiveCore(): void
    {
        $jardis = [
            'design-draft-schema', 'design-headless-mcp', 'generated-code-extend', 'generated-code-recipes',
            'generated-code-versioning', 'generated-code-wire-transport', 'generated-code-workflow-api',
            'start-orientation',
        ];
        $actual = ['core' => [], 'jardis' => []];
        foreach ($this->skillFolderNames() as $name) {
            $document = (new ParseSkillFrontmatter())((string) file_get_contents($this->skillFile($name)));
            self::assertNotNull($document);
            $profile = $document['fields']['profile'] ?? null;
            self::assertContains($profile, ['core', 'jardis'], sprintf('Skill %s has no valid profile.', $name));
            $actual[(string) $profile][] = $name;
        }

        self::assertSame($jardis, $actual['jardis']);
        self::assertCount(33, $this->skillFolderNames());
        self::assertCount(25, $actual['core']);
        self::assertContains('packages-find-existing', $actual['core']);
    }
}
