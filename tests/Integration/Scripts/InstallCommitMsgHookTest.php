<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Scripts;

use JardisTools\DevSkills\Tests\Support\ComposerFixture;
use JardisTools\DevSkills\Tests\Support\GitRepo;
use JardisTools\DevSkills\Tests\Support\TempProject;
use JardisTools\DevSkills\Tests\Support\TreeSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * The installer is a plain `sh` script that is started in the project root, so every test runs it in a child
 * process inside a temporary project.
 */
final class InstallCommitMsgHookTest extends TestCase
{
    private const SCRIPT = 'vendor/jardis/dev-skills/scripts/commit-msg';

    private string $installer;

    private string $pluginRoot;

    private TempProject $project;

    protected function setUp(): void
    {
        $this->pluginRoot = (string) realpath(__DIR__ . '/../../..');
        $this->installer  = $this->pluginRoot . '/scripts/install-commit-msg-hook';
        $this->project    = new TempProject('dev-skills-hook-');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testDetectsCoreHooksPath(): void
    {
        $this->repo();
        GitRepo::run($this->project->root, 'config', 'core.hooksPath', '.githooks');

        self::assertSame('hooks-path', $this->manager($this->install()));
    }

    public function testDetectsCaptainHook(): void
    {
        $this->repo();
        $this->project->writeFile('captainhook.json', "{}\n");

        self::assertSame('captainhook', $this->manager($this->install()));
    }

    public function testDetectsGrumPhp(): void
    {
        foreach (['grumphp.yml', 'grumphp.yaml', 'grumphp.yml.dist', 'grumphp.yaml.dist'] as $file) {
            $this->fresh();
            $this->project->writeFile($file, "grumphp: ~\n");

            self::assertSame('grumphp', $this->manager($this->install()), $file);
        }
    }

    public function testDetectsHusky(): void
    {
        $this->repo();
        $this->project->mkdir('.husky');

        self::assertSame('husky', $this->manager($this->install()));
    }

    public function testDetectsLefthook(): void
    {
        foreach (['lefthook.yml', '.lefthook.yml', 'lefthook.yaml', '.lefthook.yaml'] as $file) {
            $this->fresh();
            $this->project->writeFile($file, "pre-commit: {}\n");

            self::assertSame('lefthook', $this->manager($this->install()), $file);
        }
    }

    public function testDetectsNoManager(): void
    {
        $this->repo();

        self::assertSame('none', $this->manager($this->install()));
    }

    public function testHuskyWinsOverTheHooksPathItSetsItself(): void
    {
        $this->repo();
        $this->project->mkdir('.husky/_');
        GitRepo::run($this->project->root, 'config', 'core.hooksPath', '.husky/_');
        $this->project->writeFile('lefthook.yml', "x: 1\n");

        $result = $this->install();

        self::assertSame('husky', $this->manager($result));
        self::assertFileExists($this->project->path('.husky/commit-msg'));
        self::assertFileDoesNotExist($this->project->path('.husky/_/commit-msg'));
    }

    public function testManagerConfigWinsOverPlainHooksPath(): void
    {
        $this->repo();
        GitRepo::run($this->project->root, 'config', 'core.hooksPath', '.githooks');
        $this->project->writeFile('captainhook.json', "{}\n");

        self::assertSame('captainhook', $this->manager($this->install()));
        self::assertDirectoryDoesNotExist($this->project->path('.githooks'));
    }

    public function testHooksPathFolderGetsHookFile(): void
    {
        $this->repo();
        GitRepo::run($this->project->root, 'config', 'core.hooksPath', '.githooks');
        $this->project->mkdir('.githooks');
        $this->project->writeFile('.githooks/pre-commit', "#!/bin/sh\n");

        $result = $this->install();

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString("result: installed .githooks/commit-msg\n", $result['stdout']);
        self::assertFileExists($this->project->path('.githooks/commit-msg'));
        self::assertTrue(is_executable($this->project->path('.githooks/commit-msg')));
        self::assertFileDoesNotExist($this->project->path('.git/hooks/commit-msg'));
        self::assertSame("#!/bin/sh\n", file_get_contents($this->project->path('.githooks/pre-commit')));
    }

    public function testHooksPathFolderIsCreatedWhenMissing(): void
    {
        $this->repo();
        GitRepo::run($this->project->root, 'config', 'core.hooksPath', 'tools/hooks');

        $this->install();

        self::assertFileExists($this->project->path('tools/hooks/commit-msg'));
    }

    public function testGitHooksGetsHookOnlyWhenNoneExists(): void
    {
        $this->repo();

        $first = $this->install();

        self::assertStringContainsString("result: installed .git/hooks/commit-msg\n", $first['stdout']);
        self::assertTrue(is_executable($this->project->path('.git/hooks/commit-msg')));
        $installed = (string) file_get_contents($this->project->path('.git/hooks/commit-msg'));

        $second = $this->install();

        self::assertSame(0, $second['exit']);
        self::assertStringContainsString("result: unchanged .git/hooks/commit-msg\n", $second['stdout']);
        self::assertSame($installed, file_get_contents($this->project->path('.git/hooks/commit-msg')));

        file_put_contents($this->project->path('.git/hooks/commit-msg'), "#!/bin/sh\necho mine\n");
        $third = $this->install();

        self::assertStringContainsString("result: foreign .git/hooks/commit-msg\n", $third['stdout']);
        self::assertSame("#!/bin/sh\necho mine\n", file_get_contents($this->project->path('.git/hooks/commit-msg')));
    }

    public function testHuskyGetsCommitMsgFile(): void
    {
        $this->repo();
        $this->project->mkdir('.husky');
        $this->project->writeFile('.husky/pre-commit', "npm test\n");

        $result = $this->install();

        self::assertStringContainsString("result: installed .husky/commit-msg\n", $result['stdout']);
        self::assertTrue(is_executable($this->project->path('.husky/commit-msg')));
        self::assertSame("npm test\n", file_get_contents($this->project->path('.husky/pre-commit')));
        self::assertFileDoesNotExist($this->project->path('.git/hooks/commit-msg'));
    }

    public function testCaptainHookGrumPhpLefthookPrintExactSnippetAndWriteNothing(): void
    {
        $script = self::SCRIPT;
        $cases = [
            'captainhook.json' => [
                "\"commit-msg\": {\n    \"enabled\": true,\n    \"actions\": [\n        {\n"
                . "            \"action\": \"test ! -f {$script} || sh {$script} {\$ARG|value-of:message-file}\"\n"
                . "        }\n    ]\n}\n",
                '{}',
            ],
            'grumphp.yml' => [
                "# GrumPHP has no task that runs a script on commit-msg. Give GrumPHP your own hook templates\n"
                . "# (grumphp.yml: grumphp.hooks_dir), copy its commit-msg template there, and add before the\n"
                . "# \"Run GrumPHP\" line of that template:\n"
                . "[ -f {$script} ] && { sh {$script} \"\$COMMIT_MSG_FILE\" || exit 1; }\n",
                'grumphp: ~',
            ],
            'lefthook.yml' => [
                "commit-msg:\n  commands:\n    knowledge-note:\n"
                . "      run: test ! -f {$script} || sh {$script} {1}\n",
                'pre-commit: {}',
            ],
        ];

        foreach ($cases as $file => [$snippet, $content]) {
            $this->fresh();
            $this->project->writeFile($file, $content . "\n");
            $before = TreeSnapshot::of($this->project->root);

            $result = $this->install();

            self::assertSame(0, $result['exit'], $file . ': ' . $result['stderr']);
            self::assertStringContainsString("result: snippet\n\n" . $snippet, $result['stdout'], $file);
            self::assertStringEndsWith($snippet, $result['stdout'], $file);
            self::assertSame($before, TreeSnapshot::of($this->project->root), $file . ' must stay untouched.');
        }
    }

    public function testForeignHookStaysByteEqual(): void
    {
        $foreign = "#!/bin/sh\n# not ours \x00\xff\nexit 0\n";
        $cases = [
            'git hooks'  => ['.git/hooks/commit-msg', null],
            'hooks path' => ['.githooks/commit-msg', static function (string $root): void {
                GitRepo::run($root, 'config', 'core.hooksPath', '.githooks');
            }],
            'husky'      => ['.husky/commit-msg', null],
        ];

        foreach ($cases as $label => [$path, $prepare]) {
            $this->fresh();
            $prepare?->__invoke($this->project->root);
            $this->project->writeFile($path, $foreign);
            chmod($this->project->path($path), 0o644);
            $before = TreeSnapshot::of($this->project->root);

            $result = $this->install();

            self::assertSame(0, $result['exit'], $label);
            self::assertStringContainsString('result: foreign ' . $path . "\n", $result['stdout'], $label);
            self::assertStringContainsString('[ -f ' . self::SCRIPT . ' ] && {', $result['stdout'], $label);
            self::assertStringContainsString('hook of its own', $result['stderr'], $label);
            self::assertSame($foreign, file_get_contents($this->project->path($path)), $label);
            self::assertSame(0o644, fileperms($this->project->path($path)) & 0o777, $label);
            self::assertSame($before, TreeSnapshot::of($this->project->root), $label);
        }
    }

    public function testNoGitRepoWarnsAndInstallsNothing(): void
    {
        $this->project->writeFile('composer.json', "{}\n");
        $this->project->mkdir('.husky');
        $before = TreeSnapshot::of($this->project->root);

        $result = $this->install();

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('warning:', $result['stderr']);
        self::assertStringContainsString('not inside a Git work tree', $result['stderr']);
        self::assertStringContainsString("result: skipped\n", $result['stdout']);
        self::assertSame($before, TreeSnapshot::of($this->project->root));
    }

    public function testRunFromASubfolderInstallsNothing(): void
    {
        $this->repo();
        $sub = $this->project->mkdir('sub');

        $result = $this->installIn($sub);

        self::assertStringContainsString('not in the project root', $result['stderr']);
        self::assertFileDoesNotExist($this->project->path('.git/hooks/commit-msg'));
        self::assertFileDoesNotExist($sub . '/.git/hooks/commit-msg');
    }

    public function testLinkedTargetIsNeverWritten(): void
    {
        $outside   = new TempProject('dev-skills-outside-');
        $elsewhere = $outside->mkdir('elsewhere');
        $secret    = $outside->writeFile('elsewhere/original', "keep me\n");

        try {
            $this->assertLinkedTargetsStayUntouched($elsewhere, $secret);
        } finally {
            $outside->cleanup();
        }
    }

    private function assertLinkedTargetsStayUntouched(string $elsewhere, string $secret): void
    {

        // A symlinked hooks folder (core.hooksPath), a symlinked .husky folder, a symlinked .git/hooks folder.
        $folderCases = [
            'hooks path'  => ['.githooks', static function (string $root): void {
                GitRepo::run($root, 'config', 'core.hooksPath', '.githooks');
            }],
            'husky'       => ['.husky', null],
        ];
        foreach ($folderCases as $label => [$link, $prepare]) {
            $this->fresh();
            $prepare?->__invoke($this->project->root);
            symlink($elsewhere, $this->project->path($link));

            $result = $this->install();

            self::assertStringContainsString('is a symlink', $result['stderr'], $label);
            self::assertStringContainsString("result: skipped\n", $result['stdout'], $label);
            self::assertFileDoesNotExist($elsewhere . '/commit-msg', $label);
        }

        $this->fresh();
        $hooks = $this->project->path('.git/hooks');
        $this->removeTree($hooks);
        symlink($elsewhere, $hooks);
        $result = $this->install();
        self::assertStringContainsString('is a symlink', $result['stderr']);
        self::assertFileDoesNotExist($elsewhere . '/commit-msg');

        // A symlinked hook file, pointing at an existing file and at nothing.
        foreach ([$secret, $elsewhere . '/missing'] as $destination) {
            $this->fresh();
            symlink($destination, $this->project->path('.git/hooks/commit-msg'));

            $result = $this->install();

            self::assertStringContainsString('is a symlink', $result['stderr'], $destination);
            self::assertSame("keep me\n", file_get_contents($secret));
            self::assertFileDoesNotExist($elsewhere . '/missing');
            self::assertTrue(is_link($this->project->path('.git/hooks/commit-msg')));
        }
    }

    public function testHooksPathFromGlobalConfigIsNeverWritten(): void
    {
        $this->repo();
        $shared = $this->project->root . '-shared-hooks';
        $global = $this->project->root . '-gitconfig';
        file_put_contents($global, "[core]\n\thooksPath = {$shared}\n");

        try {
            $result = $this->install([], null, ['GIT_CONFIG_GLOBAL' => $global]);
        } finally {
            unlink($global);
        }

        self::assertSame('hooks-path', $this->manager($result));
        self::assertStringContainsString('outside this repository', $result['stderr']);
        self::assertStringContainsString("result: snippet\n", $result['stdout']);
        self::assertDirectoryDoesNotExist($shared);
        self::assertFileDoesNotExist($this->project->path('.git/hooks/commit-msg'));
    }

    public function testInstalledHookGuardsMissingScript(): void
    {
        $this->repo();
        $this->install();
        $hook = (string) file_get_contents($this->project->path('.git/hooks/commit-msg'));

        self::assertStringContainsString('[ -f ' . self::SCRIPT . " ] || exit 0\n", $hook);
        self::assertStringContainsString('sh ' . self::SCRIPT . ' "$1"', $hook);

        // The package is not there: a feat commit without a note gets through.
        GitRepo::run($this->project->root, 'commit', '-q', '--allow-empty', '-m', 'feat: no package, no check');

        // The package is there: the same commit still goes through (the hook warns, it never stops a commit).
        // GitRepo::run discards stderr on success, so the warning is proved by calling the hook directly.
        $this->project->mkdir('vendor/jardis/dev-skills/scripts');
        copy($this->pluginRoot . '/scripts/commit-msg', $this->project->path(self::SCRIPT));
        GitRepo::run($this->project->root, 'commit', '-q', '--allow-empty', '-m', 'feat: warned now');
        self::assertSame('2', trim(GitRepo::run($this->project->root, 'rev-list', '--count', 'HEAD')));
        $this->assertHookWarns($this->project->path('.git/hooks/commit-msg'));

        GitRepo::run($this->project->root, 'commit', '-q', '--allow-empty', '-m', "feat: ok\n\nWissen: hooks#stand");
        self::assertSame('3', trim(GitRepo::run($this->project->root, 'rev-list', '--count', 'HEAD')));
    }

    public function testInstallerPassesShSyntaxCheckAndUsesNoBashisms(): void
    {
        $process = proc_open(['sh', '-n', $this->installer], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $error);

        $content = (string) file_get_contents($this->installer);
        self::assertStringStartsWith("#!/bin/sh\n", $content);
        foreach (['[[ ', ' ]]', '<<<', 'echo -e', 'function ', '${BASH', '<(', 'source '] as $bashism) {
            self::assertStringNotContainsString($bashism, $content, $bashism);
        }
        self::assertSame(0, preg_match('/^\s*local /m', $content), 'No local variables in plain sh.');
    }

    public function testUnknownArgumentIsAUsageError(): void
    {
        $this->repo();

        $result = $this->install(['--nope']);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('Usage:', $result['stderr']);
    }

    /**
     * T7: the hook points into vendor/, so removing the package must not block the next commit.
     */
    public function testCommitSucceedsAfterComposerRemove(): void
    {
        $fakeVendor = (string) realpath(__DIR__ . '/../../Fixture/E2E/fake-vendor/jardisadapter-fakecache');
        ComposerFixture::writeConsumerComposerJson($this->project, $this->pluginRoot, $fakeVendor, true);
        ComposerFixture::runComposer($this->project, 'install');

        $installerInVendor = $this->project->path('vendor/jardis/dev-skills/scripts/install-commit-msg-hook');
        self::assertFileExists($installerInVendor, 'The installer must ship with the package.');
        self::assertFileExists($this->project->path(self::SCRIPT), 'The hook must ship with the package.');

        $root = $this->project->root;
        GitRepo::init($root);
        $result = $this->install([], $installerInVendor);
        self::assertStringContainsString("result: installed .git/hooks/commit-msg\n", $result['stdout'], $result['stderr']);

        // The installed hook never stops the commit; it warns (proved by calling the hook directly).
        GitRepo::run($root, 'commit', '-q', '--allow-empty', '-m', 'feat: without a note');
        self::assertSame('1', trim(GitRepo::run($root, 'rev-list', '--count', 'HEAD')));
        $this->assertHookWarns($this->project->path('.git/hooks/commit-msg'));

        ComposerFixture::runComposer($this->project, 'remove jardis/dev-skills');
        self::assertFileDoesNotExist($this->project->path(self::SCRIPT));

        GitRepo::run($root, 'commit', '-q', '--allow-empty', '-m', 'feat: after the package is gone');
        self::assertSame('2', trim(GitRepo::run($root, 'rev-list', '--count', 'HEAD')));
    }

    private function repo(): void
    {
        GitRepo::init($this->project->root);
    }

    /** A clean project with an empty repository, for loops over several fixtures. */
    /**
     * Runs an installed hook the way Git does (cwd = project root, message file as the only argument)
     * and expects the warning, not a rejection.
     */
    private function assertHookWarns(string $hookFile): void
    {
        $message = $this->project->writeFile('MSG_FOR_HOOK', "feat: no note\n");
        $process = proc_open(
            ['sh', $hookFile, $message],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project->root,
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $stderr);
        self::assertStringContainsString('commit-msg: warning:', $stderr);
    }

    private function fresh(): void
    {
        $this->project->cleanup();
        $this->project = new TempProject('dev-skills-hook-');
        $this->repo();
    }

    private function removeTree(string $path): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    /**
     * @param array{exit: int, stdout: string, stderr: string} $result
     */
    private function manager(array $result): string
    {
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame(1, preg_match('/^manager: (\S+)$/m', $result['stdout'], $match), $result['stdout']);

        return $match[1];
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function install(array $arguments = [], ?string $installer = null, array $env = []): array
    {
        return $this->installIn($this->project->root, $arguments, $installer, $env);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function installIn(string $cwd, array $arguments = [], ?string $installer = null, array $env = []): array
    {
        $process = proc_open(
            ['sh', $installer ?? $this->installer, ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            array_merge(getenv(), [
                'GIT_CONFIG_COUNT'   => '1',
                'GIT_CONFIG_KEY_0'   => 'safe.directory',
                'GIT_CONFIG_VALUE_0' => '*',
                'GIT_CONFIG_GLOBAL'  => '/dev/null',
                'GIT_CONFIG_SYSTEM'  => '/dev/null',
                ...$env,
            ]),
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start sh.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
