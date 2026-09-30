<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\E2E;

use JardisTools\DevSkills\Tests\Support\ComposerFixture;
use JardisTools\DevSkills\Tests\Support\PoolFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * Real Composer run: requiring this plugin links `scripts/pool-check.php` as `vendor/bin/pool-check.php`,
 * and the linked binary runs in the consumer project without the plugin's own autoloader.
 */
final class PoolCheckBinEndToEndTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject('dev-skills-bin-e2e-');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testComposerRequireLinksPoolCheckBinAndItRuns(): void
    {
        $pluginRoot = (string) realpath(__DIR__ . '/../../..');
        self::assertDirectoryExists($pluginRoot . '/src');

        $this->project->writeFile('composer.json', (string) json_encode([
            'name'              => 'jardis-test/consumer',
            'type'              => 'project',
            'minimum-stability' => 'dev',
            'prefer-stable'     => true,
            'repositories'      => [['type' => 'path', 'url' => $pluginRoot, 'options' => ['symlink' => false]]],
            'config'            => ['allow-plugins' => ['jardis/dev-skills' => true]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        ComposerFixture::runComposer($this->project, 'require jardis/dev-skills:*');

        $bin = $this->project->path('vendor/bin/pool-check.php');
        self::assertFileExists($bin);
        self::assertTrue(is_executable($bin), 'vendor/bin/pool-check.php must be executable.');

        [$helpExit, $helpOut] = $this->runBin($bin, '--help');
        self::assertSame(0, $helpExit, $helpOut);
        self::assertStringContainsString('usage: php pool-check.php', $helpOut);

        PoolFixture::install($this->project, 'green');
        [$greenExit, $greenOut] = $this->runBin($bin, '');
        self::assertSame(0, $greenExit, $greenOut);
        self::assertSame('Pool clean (2 page(s) checked).', trim($greenOut));

        $this->project->writeFile('.claude/wissen/broken.md', "# Broken\n");
        [$redExit, $redOut] = $this->runBin($bin, '');
        self::assertSame(1, $redExit, $redOut);
        self::assertStringContainsString(' section-missing ', $redOut);
    }

    /**
     * @return array{int, string}
     */
    private function runBin(string $bin, string $arguments): array
    {
        $output   = [];
        $exitCode = 0;
        exec(
            sprintf('cd %s && %s %s 2>&1', escapeshellarg($this->project->root), escapeshellarg($bin), $arguments),
            $output,
            $exitCode,
        );

        return [$exitCode, implode("\n", $output)];
    }
}
