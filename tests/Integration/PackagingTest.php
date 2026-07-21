<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Composer dist manifest defined by `.gitattributes`.
 *
 * Composer ships the `dist` archive to consumers; `export-ignore` decides
 * what lands in their `vendor/jardis/dev-skills/`. A forgotten `export-ignore`
 * on a new dev path silently bloats every consumer project; an accidental
 * `export-ignore` on `src/` or `skills/` silently breaks the package. Both
 * failure modes only surface at release time — this test catches them in CI.
 *
 * Measured via `git check-attr` against the working tree (index + worktree),
 * so it is accurate even before the change is committed.
 */
final class PackagingTest extends TestCase
{
    private string $pluginRoot;

    protected function setUp(): void
    {
        $this->pluginRoot = (string) realpath(__DIR__ . '/../..');
        if (!is_file($this->pluginRoot . '/.gitattributes')) {
            self::fail('.gitattributes not resolvable at plugin root: ' . $this->pluginRoot);
        }
    }

    /**
     * Runtime essentials the consumer needs — must NOT be export-ignored.
     */
    public function testRuntimeEssentialsAreShipped(): void
    {
        foreach (['src', 'skills', 'AGENTS.md', 'composer.json', 'README.md', 'LICENSE.md'] as $path) {
            self::assertSame(
                'unspecified',
                $this->exportIgnore($path),
                sprintf('"%s" must ship in the dist archive but is export-ignored.', $path),
            );
        }
    }

    /**
     * Development / CI artefacts — must be export-ignored from the dist archive.
     */
    public function testDevelopmentArtefactsAreExcluded(): void
    {
        $devPaths = [
            'docs', 'tests', 'bin', 'support', '.github',
            'REQUIREMENT.md', 'phpunit.xml', 'phpstan.neon', 'phpcs.xml',
            'Makefile', '.env.example', 'composer.lock',
        ];

        foreach ($devPaths as $path) {
            self::assertSame(
                'set',
                $this->exportIgnore($path),
                sprintf('"%s" is a dev-only path and must be export-ignored.', $path),
            );
        }
    }

    /**
     * Returns git's export-ignore verdict for $path: "set" or "unspecified".
     */
    private function exportIgnore(string $path): string
    {
        $cmd = sprintf(
            'git -C %s check-attr export-ignore -- %s 2>&1',
            escapeshellarg($this->pluginRoot),
            escapeshellarg($path),
        );

        $output   = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        $line = implode("\n", $output);

        if ($exitCode !== 0) {
            self::fail(sprintf('git check-attr failed for "%s": %s', $path, $line));
        }

        // Output format: "<path>: export-ignore: <value>"
        $value = substr($line, (int) strrpos($line, ': ') + 2);

        return trim($value);
    }
}
