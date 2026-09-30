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
    ): void {
        $extra = $bundledSkills
            ? ['jardis/dev-skills' => ['bundled-skills' => true]]
            : new \stdClass();

        $json = [
            'name'              => 'jardis-test/consumer',
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
            'require' => [
                'jardis/dev-skills'         => '*',
                'jardisadapter/fakecache'   => '*',
            ],
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
        $cmd = sprintf(
            'cd %s && COMPOSER_HOME=%s composer %s --no-interaction --no-progress 2>&1',
            escapeshellarg($project->root),
            escapeshellarg($project->root . '/.composer-home'),
            $command,
        );

        $output    = [];
        $exitCode  = 0;
        exec($cmd, $output, $exitCode);
        $joined = implode("\n", $output);

        if ($exitCode !== 0) {
            Assert::fail(sprintf(
                "Composer command failed (exit %d): composer %s\nOutput:\n%s",
                $exitCode,
                $command,
                $joined,
            ));
        }

        return $joined;
    }
}
