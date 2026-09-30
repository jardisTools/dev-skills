<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Building blocks for the state a 1.3.x install leaves behind and for update
 * runs "from 1.3.x": stub folders only, no real skill content is checked in.
 */
final class LegacyFixture
{
    /** Bundle skill names shipped up to 1.3.x. */
    public const BUNDLE_NAMES = [
        'do-git-branch',
        'do-git-commit',
        'do-git-compliance',
        'do-git-push',
        'do-project-git-setup',
        'jardis-catalog',
        'jardis-mcp-consumer',
        'jardis-start-here',
        'platform-cookbook',
        'platform-implementation',
        'platform-usage',
        'platform-versioning',
        'platform-workflow',
        'rules-architecture',
        'rules-frontend',
        'rules-patterns',
        'rules-testing',
        'schema-authoring',
    ];

    public static function stubContent(string $name): string
    {
        return "# legacy stub of " . $name . "\n";
    }

    /**
     * Writes 1.3.x-style skill folders into `.claude/skills/` (no manifest, no `.agents` copy).
     *
     * @param list<string>|null $names defaults to all bundle names
     */
    public static function writeInstalledBundle(TempProject $project, ?array $names = null): void
    {
        foreach ($names ?? self::BUNDLE_NAMES as $name) {
            $project->writeFile('.claude/skills/' . $name . '/SKILL.md', self::stubContent($name));
        }
    }

    /**
     * Archives the given release tag as an `artifact` repository package. The
     * version is stamped into the archived composer.json, which carries none.
     */
    public static function archiveRelease(string $repoRoot, string $tag, string $version, string $artifactDir): void
    {
        $tmp = $artifactDir . '/.release-' . bin2hex(random_bytes(4)) . '.zip';
        self::git($repoRoot, ['archive', '--format=zip', '-o', $tmp, $tag . '^{commit}']);
        self::stampVersion($tmp, $version, $artifactDir . '/dev-skills-' . $version . '.zip');
    }

    /**
     * Archives the candidate: every tracked or not-ignored file of the working
     * tree. In CI (clean checkout) this is exactly `git archive HEAD`; locally it
     * also carries work that is not committed yet.
     */
    public static function archiveCandidate(string $repoRoot, string $version, string $artifactDir): void
    {
        $files = explode("\0", rtrim(self::git($repoRoot, ['ls-files', '-z', '--cached', '--others', '--exclude-standard']), "\0"));
        $tmp = $artifactDir . '/.candidate-' . bin2hex(random_bytes(4)) . '.zip';

        $zip = new \ZipArchive();
        Assert::assertTrue($zip->open($tmp, \ZipArchive::CREATE) === true, 'Could not create candidate archive.');
        foreach ($files as $file) {
            if ($file !== '' && is_file($repoRoot . '/' . $file)) {
                $zip->addFile($repoRoot . '/' . $file, $file);
            }
        }
        $zip->close();

        self::stampVersion($tmp, $version, $artifactDir . '/dev-skills-' . $version . '.zip');
    }

    /**
     * Consumer project that takes the plugin from an `artifact` repository.
     */
    public static function writeArtifactConsumerComposerJson(
        TempProject $project,
        string $artifactDir,
        string $fakeVendorRoot,
        string $pluginConstraint,
    ): void {
        $json = [
            'name'              => 'jardis-test/consumer',
            'description'       => 'E2E update test consumer project',
            'type'              => 'project',
            'minimum-stability' => 'dev',
            'prefer-stable'     => true,
            'repositories'      => [
                ['type' => 'artifact', 'url' => $artifactDir],
                ['type' => 'path', 'url' => $fakeVendorRoot, 'options' => ['symlink' => false]],
            ],
            'require' => [
                'jardis/dev-skills'       => $pluginConstraint,
                'jardisadapter/fakecache' => '*',
            ],
            'config' => ['allow-plugins' => ['jardis/dev-skills' => true]],
            'extra'  => ['jardis/dev-skills' => ['bundled-skills' => true]],
        ];

        $project->writeFile(
            'composer.json',
            json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    private static function stampVersion(string $sourceZip, string $version, string $targetZip): void
    {
        $zip = new \ZipArchive();
        Assert::assertTrue($zip->open($sourceZip) === true, 'Could not open archive ' . $sourceZip);
        $composerJson = $zip->getFromName('composer.json');
        Assert::assertIsString($composerJson, 'Archive carries no composer.json.');

        $data = json_decode($composerJson, true, 32, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($data);
        $data['version'] = $version;
        $zip->addFromString('composer.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $zip->close();

        Assert::assertTrue(rename($sourceZip, $targetZip));
    }

    /**
     * @param list<string> $args
     */
    private static function git(string $repoRoot, array $args): string
    {
        $cmd = "git -c 'safe.directory=*' -C " . escapeshellarg($repoRoot) . ' '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);
        $joined = implode("\n", $output);
        if ($exit !== 0) {
            Assert::fail("git {$args[0]} failed (exit {$exit}). Releases need full history and tags "
                . "(CI: fetch-depth 0; locally: git fetch --tags).\n" . $joined);
        }

        return $joined;
    }
}
