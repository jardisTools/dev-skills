<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Scripts;

use JardisTools\DevSkills\Tests\Support\GitRepo;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * The commit-msg hook and its CI net run as plain `sh` scripts, so every test starts them in a child
 * process; none of them depends on the exec bit.
 */
final class CommitMsgHookTest extends TestCase
{
    private const DASH = "\u{2013}";

    private string $hook;

    private string $check;

    private TempProject $project;

    protected function setUp(): void
    {
        $root          = (string) realpath(__DIR__ . '/../../..');
        $this->hook    = $root . '/scripts/commit-msg';
        $this->check   = $root . '/scripts/check-commit-messages';
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testFeatWithoutNoteIsRejected(): void
    {
        $result = $this->runHook("feat: add a thing\n\nSome body text.\n");

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('commit-msg:', $result['stderr']);
        self::assertSame('', $result['stdout']);
        self::assertNotSame(0, $this->runHook("fix: repair a thing\n")['exit']);
    }

    public function testScopedAndBangFeatFixWithoutNoteAreRejected(): void
    {
        foreach (['feat(hooks): x', 'fix(core): x', 'feat!: x', 'fix!: x', 'feat(api)!: x'] as $subject) {
            $result = $this->runHook($subject . "\n");

            self::assertNotSame(0, $result['exit'], $subject);
            self::assertStringContainsString("'Wissen: <page>#<section>'", $result['stderr'], $subject);
            self::assertStringContainsString("'Wissen: keins " . self::DASH . " <reason>'", $result['stderr'], $subject);
        }
    }

    public function testNoteWithPageAndSectionPasses(): void
    {
        foreach (['stand', 'entscheide', 'fallen', 'ersetzt', 'verweise'] as $section) {
            $result = $this->runHook("feat(hooks): add it\n\nWissen: commit-hooks#{$section}\n");

            self::assertSame(0, $result['exit'], $section . ': ' . $result['stderr']);
            self::assertSame('', $result['stderr']);
        }
        self::assertSame(0, $this->runHook("fix: x\n\nBody.\n\nWissen: a1-b#fallen\n")['exit']);
        self::assertSame(0, $this->runHook("feat!: x\n\nWissen: page#stand   \n")['exit']);
    }

    public function testKeinsWithReasonPasses(): void
    {
        $dash = self::DASH;

        self::assertSame(0, $this->runHook("feat: x\n\nWissen: keins {$dash} pure refactoring\n")['exit']);
        self::assertSame(0, $this->runHook("fix: x\n\nWissen: keins{$dash}typo\n")['exit']);
        self::assertSame(0, $this->runHook("fix: x\n\nWissen:   keins  {$dash}  typo in a comment  \n")['exit']);
    }

    public function testKeinsWithoutReasonIsRejected(): void
    {
        $dash = self::DASH;

        foreach (["Wissen: keins", "Wissen: keins {$dash}", "Wissen: keins {$dash}   ", "Wissen: keins - hyphen only",
            "Wissen: keins \u{2014} em dash", 'Wissen: keins because'] as $note) {
            $result = $this->runHook("feat: x\n\n{$note}\n");

            self::assertNotSame(0, $result['exit'], $note);
            self::assertStringContainsString('reason', $result['stderr'], $note);
        }
    }

    public function testChoreAndDocsPass(): void
    {
        foreach (['chore: x', 'docs: x', 'docs(readme): x', 'refactor: x', 'test!: x', 'Update the readme', 'feature: x'] as $subject) {
            $result = $this->runHook($subject . "\n\nBody.\n");

            self::assertSame(0, $result['exit'], $subject . ': ' . $result['stderr']);
        }
    }

    public function testMalformedNoteIsRejectedForAnyType(): void
    {
        foreach (['chore', 'docs', 'feat', 'fix'] as $type) {
            foreach (['Wissen:', 'Wissen: Not A Page', 'Wissen: page', 'Wissen: page#', 'Wissen: Page#stand', 'Wissen: page##stand'] as $note) {
                $result = $this->runHook("{$type}: x\n\n{$note}\n");

                self::assertNotSame(0, $result['exit'], $type . ' / ' . $note);
                self::assertStringContainsString('commit-msg:', $result['stderr']);
            }
        }
    }

    public function testUnknownSectionIsRejected(): void
    {
        foreach (['docs', 'feat'] as $type) {
            $result = $this->runHook("{$type}: x\n\nWissen: page#unknown\n");

            self::assertNotSame(0, $result['exit'], $type);
            self::assertStringContainsString("'#unknown' is not a known section", $result['stderr']);
        }
        self::assertNotSame(0, $this->runHook("chore: x\n\nWissen: page#Stand\n")['exit']);
    }

    public function testSpaceBeforeHashIsRejected(): void
    {
        foreach (['docs', 'fix'] as $type) {
            $result = $this->runHook("{$type}: x\n\nWissen: page #stand\n");

            self::assertNotSame(0, $result['exit'], $type);
            self::assertStringContainsString('no space allowed before', $result['stderr']);
        }
    }

    public function testCommentLinesAreIgnored(): void
    {
        $message = "feat: x\n\nWissen: page#stand\n# Please enter the commit message\n#\n# On branch main\n";
        self::assertSame(0, $this->runHook($message)['exit']);

        $onlyComments = "docs: x\n# Wissen: page#unknown\n# Wissen: keins\n";
        self::assertSame(0, $this->runHook($onlyComments)['exit']);

        $commentedSubject = "# feat: x\nchore: y\n";
        self::assertSame(0, $this->runHook($commentedSubject)['exit']);
    }

    public function testHashCommentedNoteDoesNotCount(): void
    {
        $result = $this->runHook("feat: x\n\n# Wissen: page#stand\n");
        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString("without a 'Wissen:' note", $result['stderr']);

        self::assertNotSame(0, $this->runHook("fix: x\n\n#Wissen: keins {$this->dash()} reason\n")['exit']);
    }

    public function testMergeRevertFixupSquashMessagesPass(): void
    {
        $messages = [
            "Merge branch 'feature/a' into develop\n",
            "Merge pull request #12 from org/feature\n\nfeat: add it\n",
            "Revert \"feat: add it\"\n\nThis reverts commit 0123456789abcdef0123456789abcdef01234567.\n",
            "fixup! feat: add it\n",
            "squash! fix: repair it\n",
            "amend! feat: add it\n\nfeat: add it, better\n",
            // A revert is recognised by its subject only; the old case (feat: subject, phrase in the body) was no revert.
            "Revert \"feat: the thing\"\n\nThis reverts commit 0123abc.\n",
        ];

        foreach ($messages as $message) {
            $result = $this->runHook($message);

            self::assertSame(0, $result['exit'], $message . $result['stderr']);
        }
    }

    public function testRevertPhraseInBodyDoesNotBypassTheRule(): void
    {
        $result = $this->runHook("feat: undo the thing\n\nThis reverts commit 0123abc.\n");

        self::assertNotSame(0, $result['exit']);
    }

    public function testRealGitCommitIsRejectedAndAccepted(): void
    {
        $repo = $this->project->mkdir('repo');
        GitRepo::init($repo);
        $this->project->mkdir('repo/.git/hooks');
        $target = $repo . '/.git/hooks/commit-msg';
        copy($this->hook, $target);
        chmod($target, 0o755);

        try {
            GitRepo::run($repo, 'commit', '-q', '--allow-empty', '-m', 'feat: without a note');
            self::fail('The hook must reject the commit.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('commit-msg:', $e->getMessage());
        }
        self::assertSame('0', trim(GitRepo::run($repo, 'rev-list', '--all', '--count')));

        GitRepo::run($repo, 'commit', '-q', '--allow-empty', '-m', "feat: with a note\n\nWissen: commit-hooks#stand");
        GitRepo::run($repo, 'commit', '-q', '--allow-empty', '-m', 'chore: no note needed');

        self::assertSame('2', trim(GitRepo::run($repo, 'rev-list', '--count', 'HEAD')));
    }

    public function testRangeChecksEveryMessageAndFailsOnViolation(): void
    {
        $repo = $this->newRepo();
        $base = $this->commit($repo, 'chore: base');
        $this->commit($repo, "feat: good\n\nWissen: page#stand");
        $this->commit($repo, 'fix: bad one');
        $this->commit($repo, "docs: bad form\n\nWissen: page#nope");
        $this->commit($repo, 'chore: fine');

        $result = $this->runCheck($repo, $base . '..HEAD');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('fix: bad one', $result['stderr']);
        self::assertStringContainsString('docs: bad form', $result['stderr']);
        self::assertStringNotContainsString('feat: good', $result['stderr']);
        self::assertStringContainsString('2 of 4 commit message(s) rejected', $result['stderr']);
    }

    public function testPassesWhenEveryMessageIsValid(): void
    {
        $repo = $this->newRepo();
        $base = $this->commit($repo, 'feat: base without checking');
        $this->commit($repo, "feat: good\n\nWissen: page#entscheide");
        $this->commit($repo, "fix: good too\n\nWissen: keins {$this->dash()} typo");
        $this->commit($repo, 'docs: fine');

        $result = $this->runCheck($repo, $base . '..HEAD');

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('3 commit message(s) checked', $result['stdout']);
        self::assertSame(0, $this->runCheck($repo, 'HEAD..HEAD')['exit']);
        self::assertSame(2, $this->runCheck($repo, 'no-such-ref..HEAD')['exit']);
        self::assertSame(2, $this->runCheck($repo, 'HEAD')['exit']);
    }

    public function testMergeCommitsInRangeAreSkipped(): void
    {
        $repo = $this->newRepo();
        $base = $this->commit($repo, 'chore: base');
        $main = trim(GitRepo::run($repo, 'rev-parse', '--abbrev-ref', 'HEAD'));
        GitRepo::run($repo, 'checkout', '-q', '-b', 'side');
        $this->commit($repo, 'docs: on the side');
        GitRepo::run($repo, 'checkout', '-q', $main);
        $this->commit($repo, 'chore: on main');
        GitRepo::run($repo, 'merge', '-q', '--no-ff', '-m', 'feat: merged without a note', 'side');

        $result = $this->runCheck($repo, $base . '..HEAD');

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('2 commit message(s) checked', $result['stdout']);
    }

    public function testCheckScriptHoldsNoCopyOfTheRules(): void
    {
        $source = (string) file_get_contents($this->check);
        foreach (['Wissen', 'keins', 'feat', 'entscheide', 'verweise'] as $term) {
            self::assertStringNotContainsString($term, $source, $term);
        }
        self::assertStringContainsString('commit-msg', $source);

        // Behaviour: the verdict comes from the commit-msg next to it, so a stub decides.
        $repo = $this->newRepo();
        $base = $this->commit($repo, 'chore: base');
        $this->commit($repo, 'chore: harmless');
        $scripts = $this->project->mkdir('stub/scripts');
        copy($this->check, $scripts . '/check-commit-messages');

        $this->project->writeFile('stub/scripts/commit-msg', "#!/bin/sh\necho stub says no >&2\nexit 1\n");
        $rejecting = $this->runCheck($repo, $base . '..HEAD', $scripts . '/check-commit-messages');
        self::assertSame(1, $rejecting['exit']);
        self::assertStringContainsString('stub says no', $rejecting['stderr']);

        $this->project->writeFile('stub/scripts/commit-msg', "#!/bin/sh\nexit 0\n");
        self::assertSame(0, $this->runCheck($repo, $base . '..HEAD', $scripts . '/check-commit-messages')['exit']);
    }

    public function testScriptsPassShSyntaxCheck(): void
    {
        foreach ([$this->hook, $this->check] as $script) {
            $result = $this->runProcess(['sh', '-n', $script], $this->project->root);

            self::assertSame(0, $result['exit'], $script . ': ' . $result['stderr']);
        }
    }

    public function testScriptsUseNoBashisms(): void
    {
        foreach ([$this->hook, $this->check] as $script) {
            $source = (string) file_get_contents($script);

            self::assertStringStartsWith("#!/bin/sh\n", $source);
            foreach (['[[ ', ' ]]', '<<<', 'echo -e', 'local ', 'function ', '${BASH', '<(', 'source '] as $bashism) {
                self::assertStringNotContainsString($bashism, $source, $script);
            }
        }
    }

    public function testHookRunsUnderDashWhenAvailable(): void
    {
        $dash = trim((string) shell_exec('command -v dash 2>/dev/null'));
        if ($dash === '') {
            self::markTestSkipped('dash is not installed.');
        }

        $file = $this->project->writeFile('msg', "feat: x\n");
        self::assertSame(1, $this->runProcess([$dash, $this->hook, $file], $this->project->root)['exit']);
        $file = $this->project->writeFile('msg', "feat: x\n\nWissen: page#stand\n");
        self::assertSame(0, $this->runProcess([$dash, $this->hook, $file], $this->project->root)['exit']);
        self::assertSame(0, $this->runProcess([$dash, '-n', $this->check], $this->project->root)['exit']);
    }

    public function testMissingMessageFileIsAUsageError(): void
    {
        self::assertSame(2, $this->runProcess(['sh', $this->hook], $this->project->root)['exit']);
        self::assertSame(2, $this->runProcess(['sh', $this->hook, $this->project->path('none')], $this->project->root)['exit']);
    }

    private function dash(): string
    {
        return self::DASH;
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runHook(string $message): array
    {
        $file = $this->project->writeFile('COMMIT_EDITMSG', $message);

        return $this->runProcess(['sh', $this->hook, $file], $this->project->root);
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCheck(string $repo, string $range, ?string $script = null): array
    {
        return $this->runProcess(['sh', $script ?? $this->check, $range], $repo);
    }

    private function newRepo(): string
    {
        $repo = $this->project->mkdir('work');
        GitRepo::init($repo);

        return $repo;
    }

    /** Commits with the hook not installed and returns the new HEAD. */
    private function commit(string $repo, string $message): string
    {
        GitRepo::commitAll($repo, $message);

        return trim(GitRepo::run($repo, 'rev-parse', 'HEAD'));
    }

    /**
     * @param list<string> $command
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, string $cwd): array
    {
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            array_merge(getenv(), [
                'GIT_CONFIG_COUNT'   => '1',
                'GIT_CONFIG_KEY_0'   => 'safe.directory',
                'GIT_CONFIG_VALUE_0' => '*',
            ]),
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start ' . $command[0]);
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
