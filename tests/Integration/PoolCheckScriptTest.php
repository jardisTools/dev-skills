<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use JardisTools\DevSkills\PoolCheck;
use JardisTools\DevSkills\Handler\Pool\FormatReport;
use JardisTools\DevSkills\Tests\Support\PoolFixture;
use JardisTools\DevSkills\Tests\Support\RunScript;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * The pool check must run where the Composer autoloader of this plugin is not: in a customer project,
 * from vendor/jardis/dev-skills/, next to a vendor/autoload.php that does not know this package.
 */
final class PoolCheckScriptTest extends TestCase
{
    private string $pluginRoot;

    private TempProject $project;

    protected function setUp(): void
    {
        $this->pluginRoot = (string) realpath(__DIR__ . '/../..');
        $this->project    = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testNoComposerImportInPoolSources(): void
    {
        $files = [$this->pluginRoot . '/src/PoolCheck.php', $this->pluginRoot . '/scripts/pool-check.php'];
        foreach (['/src/Handler/Pool', '/src/Data'] as $dir) {
            foreach (glob($this->pluginRoot . $dir . '/*.php') ?: [] as $file) {
                if ($dir === '/src/Handler/Pool' || str_contains(basename($file), 'Pool')) {
                    $files[] = $file;
                }
            }
        }

        self::assertGreaterThan(12, count($files));
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/Composer\\\\/', $source, $file);
            self::assertStringNotContainsString('vendor/autoload.php', $source, $file);
        }
    }

    public function testHelpExitsZeroWithoutProjectAutoload(): void
    {
        PoolFixture::copyTree($this->pluginRoot . '/scripts', $this->project->path('plugin/scripts'));
        PoolFixture::copyTree($this->pluginRoot . '/src', $this->project->path('plugin/src'));
        self::assertDirectoryDoesNotExist($this->project->path('vendor'));
        self::assertFileDoesNotExist($this->project->path('.claude/wissen/INDEX.md'));

        $help = RunScript::run($this->project->path('plugin/scripts/pool-check.php'), $this->project->root, ['--help']);
        $late = RunScript::run(
            $this->project->path('plugin/scripts/pool-check.php'),
            $this->project->root,
            ['--root=' . $this->project->root, '--help'],
        );

        self::assertSame(0, $help['exit']);
        self::assertStringContainsString('usage: php pool-check.php [--root=<dir>] [--help]', $help['stdout']);
        self::assertStringContainsString('--root=<dir>', $help['stdout']);
        self::assertSame('', $help['stderr']);
        self::assertSame($help, $late);
    }

    public function testScriptRunsInTempProjectWithoutVendorWithSameExitAndLines(): void
    {
        PoolFixture::install($this->project, 'red-links');
        PoolFixture::copyTree($this->pluginRoot . '/scripts', $this->project->path('plugin/scripts'));
        PoolFixture::copyTree($this->pluginRoot . '/src', $this->project->path('plugin/src'));
        self::assertDirectoryDoesNotExist($this->project->path('vendor'));

        $inProject = RunScript::run($this->project->path('plugin/scripts/pool-check.php'), $this->project->root);
        $inRepo    = RunScript::run(
            $this->pluginRoot . '/scripts/pool-check.php',
            $this->project->root,
            ['--root=' . $this->project->root],
        );
        $expected  = implode("\n", (new FormatReport())((new PoolCheck())($this->project->root))) . "\n";

        self::assertSame(1, $inProject['exit']);
        self::assertSame($inRepo['exit'], $inProject['exit']);
        self::assertSame($inRepo['stdout'], $inProject['stdout']);
        self::assertSame($expected, $inProject['stdout']);
        self::assertSame('', $inProject['stderr']);
    }
}
