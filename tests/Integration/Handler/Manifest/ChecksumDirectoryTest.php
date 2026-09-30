<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Manifest;

use JardisTools\DevSkills\Handler\Manifest\ChecksumDirectory;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class ChecksumDirectoryTest extends TestCase
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

    public function testChecksumIsDeterministicAndIndependentOfCreationOrder(): void
    {
        $this->project->writeFile('a/one.md', 'alpha');
        $this->project->writeFile('a/sub/two.md', 'beta');
        $this->project->writeFile('b/sub/two.md', 'beta');
        $this->project->writeFile('b/one.md', 'alpha');

        $checksum = new ChecksumDirectory();

        self::assertSame($checksum($this->project->path('a')), $checksum($this->project->path('a')));
        self::assertSame($checksum($this->project->path('a')), $checksum($this->project->path('b')));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $checksum($this->project->path('a')));
    }

    public function testLineEndingChangeChangesChecksum(): void
    {
        $this->project->writeFile('lf/SKILL.md', "line one\nline two\n");
        $this->project->writeFile('crlf/SKILL.md', "line one\r\nline two\r\n");

        $checksum = new ChecksumDirectory();

        self::assertNotSame($checksum($this->project->path('lf')), $checksum($this->project->path('crlf')));
    }

    public function testContentAndNameChangesChangeChecksum(): void
    {
        $this->project->writeFile('base/x.md', 'same');
        $this->project->writeFile('content/x.md', 'other');
        $this->project->writeFile('name/y.md', 'same');

        $checksum = new ChecksumDirectory();
        $base = $checksum($this->project->path('base'));

        self::assertNotSame($base, $checksum($this->project->path('content')));
        self::assertNotSame($base, $checksum($this->project->path('name')));
    }

    public function testMissingDirectoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ChecksumDirectory())($this->project->path('absent'));
    }
}
