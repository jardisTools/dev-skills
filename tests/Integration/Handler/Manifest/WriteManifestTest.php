<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Manifest;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestState;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\Handler\Manifest\WriteManifest;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class WriteManifestTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testWrittenManifestRoundTripsThroughRead(): void
    {
        $path = $this->project->path(Manifest::FILE);
        $entries = [
            '.claude/skills/zeta' => ['source' => 'bundle', 'sha256' => str_repeat('b', 64)],
            '.claude/skills/alpha' => ['source' => 'vendor/acme/pkg', 'sha256' => str_repeat('a', 64)],
        ];

        (new WriteManifest())($path, new Manifest(1, '2.0.0', $entries));
        $result = (new ReadManifest())($path, '2.0.0');

        self::assertSame(ManifestState::Healthy, $result->state);
        self::assertSame(['.claude/skills/alpha', '.claude/skills/zeta'], array_keys($result->manifest->entries ?? []));
        self::assertSame($entries['.claude/skills/alpha'], $result->manifest->entries['.claude/skills/alpha']);
        self::assertSame([basename($path)], $this->siblings($path));
    }

    public function testFailedWriteKeepsOldManifestIntactAndLeavesNoTempFile(): void
    {
        $path = $this->project->path(Manifest::FILE);
        $old = new Manifest(1, '1.0.0', ['.claude/skills/one' => ['source' => 'bundle', 'sha256' => str_repeat('c', 64)]]);
        (new WriteManifest())($path, $old);
        $before = file_get_contents($path);

        $broken = new Manifest(1, '2.0.0', ['.claude/skills/bad' => ['source' => "\xB1\x31", 'sha256' => str_repeat('d', 64)]]);
        try {
            (new WriteManifest())($path, $broken);
            self::fail('Expected the encoding failure to abort the write.');
        } catch (\JsonException) {
            // expected: aborted before the target was touched
        }

        self::assertSame($before, file_get_contents($path));
        self::assertSame([basename($path)], $this->siblings($path));
    }

    public function testFailedRenameKeepsTargetAndRemovesTempFile(): void
    {
        $target = $this->project->mkdir('.claude/skills/.jardis-managed.json');
        file_put_contents($target . '/keep.txt', 'kept');

        try {
            (new WriteManifest())($target, new Manifest(1, '1.0.0'));
            self::fail('Expected the rename failure to abort the write.');
        } catch (\RuntimeException) {
            // expected: a directory occupies the target path
        }

        self::assertSame('kept', file_get_contents($target . '/keep.txt'));
        self::assertSame([basename($target)], $this->siblings($target));
    }

    /**
     * @return list<string>
     */
    private function siblings(string $path): array
    {
        $names = array_values(array_diff(scandir(dirname($path)) ?: [], ['.', '..']));
        sort($names);

        return $names;
    }
}
