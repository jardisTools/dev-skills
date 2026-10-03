<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Helpers for tests that drive a real Composer run in a temporary consumer project.
 */
final class ComposerFixture
{
    public static function writeConsumerComposerJson(
        TempProject $project,
        string $pluginRoot,
        string $fakeVendorRoot,
        bool $bundledSkills,
        bool $pluginAsDevRequirement = false,
    ): void {
        $extra = $bundledSkills
            ? ['jardis/dev-skills' => ['bundled-skills' => true]]
            : new \stdClass();

        $json = [
            'name'              => 'acme/consumer',
            'description'       => 'E2E test consumer project',
            'type'              => 'project',
            'minimum-stability' => 'dev',
            'prefer-stable'     => true,
            'repositories'      => [
                [
                    'type'    => 'path',
                    'url'     => $pluginRoot,
                    'options' => ['symlink' => false],
                ],
                [
                    'type'    => 'path',
                    'url'     => $fakeVendorRoot,
                    'options' => ['symlink' => false],
                ],
            ],
            'require' => $pluginAsDevRequirement
                ? ['jardisadapter/fakecache' => '*']
                : ['jardis/dev-skills' => '*', 'jardisadapter/fakecache' => '*'],
            'require-dev' => $pluginAsDevRequirement
                ? ['jardis/dev-skills' => '*']
                : new \stdClass(),
            'config' => [
                'allow-plugins' => [
                    'jardis/dev-skills' => true,
                ],
            ],
            'extra' => $extra,
        ];

        $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Could not encode consumer composer.json.');
        }

        $project->writeFile('composer.json', $encoded);
    }

    public static function runComposer(TempProject $project, string $command): string
    {
        [$exitCode, $output] = self::run($project->root, $project->root . '/.composer-home', $command);

        if ($exitCode !== 0) {
            Assert::fail(sprintf(
                "Composer command failed (exit %d): composer %s\nOutput:\n%s",
                $exitCode,
                $command,
                $output,
            ));
        }

        return $output;
    }

    /**
     * Runs a real Composer command and returns its exit code and combined output.
     *
     * @param string $envPrefix extra shell prefix in front of `composer`, e.g. `env -u HOME`
     * @return array{int, string}
     */
    public static function run(string $cwd, string $composerHome, string $command, string $envPrefix = ''): array
    {
        $cmd = sprintf(
            'cd %s && %s COMPOSER_HOME=%s composer %s --no-interaction --no-progress 2>&1',
            escapeshellarg($cwd),
            $envPrefix === '' ? 'env' : $envPrefix,
            escapeshellarg($composerHome),
            $command,
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }
}
