<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Validate;

use JardisTools\DevSkills\Handler\Validate\CheckSkillSize;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class CheckSkillSizeTest extends TestCase
{
    private const HEAD = "---\nname: some-skill\n---\n\n### 1. Topic\n\n";

    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testConformantSkillWithLeavesPasses(): void
    {
        $path = $this->writeSkillMd(self::HEAD . "Body.\n");
        $this->writeLeaf('topic.md', "# Topic\n\nLeaf body.\n");

        self::assertSame([], (new CheckSkillSize())($path));
    }

    public function testLineOverTheCharacterLimitInSkillMdFails(): void
    {
        $path = $this->writeSkillMd(self::HEAD . str_repeat('a', 2001) . "\n");

        self::assertSame(['SKILL.md:7: line has 2001 characters, max 2000'], (new CheckSkillSize())($path));
    }

    public function testLineAtTheCharacterLimitPasses(): void
    {
        $path = $this->writeSkillMd(self::HEAD . str_repeat('a', 2000) . "\n");

        self::assertSame([], (new CheckSkillSize())($path));
    }

    public function testCrLfLineAtTheCharacterLimitPasses(): void
    {
        $path = $this->writeSkillMd(self::HEAD . str_repeat('a', 2000) . "\r\nNext.\r\n");

        self::assertSame([], (new CheckSkillSize())($path));
    }

    public function testCrLfLineOverTheCharacterLimitFails(): void
    {
        $path = $this->writeSkillMd(self::HEAD . "Intro.\r\n" . str_repeat('a', 2001) . "\r\n");

        self::assertSame(['SKILL.md:8: line has 2001 characters, max 2000'], (new CheckSkillSize())($path));
    }

    public function testLoneCarriageReturnEndsALine(): void
    {
        $path = $this->writeSkillMd("Intro.\r" . str_repeat('a', 2001) . "\r");

        self::assertSame(['SKILL.md:2: line has 2001 characters, max 2000'], (new CheckSkillSize())($path));
    }

    public function testMultibyteLineIsMeasuredInCharactersNotBytes(): void
    {
        $line = str_repeat('ä', 2000);
        self::assertGreaterThan(2000, strlen($line));
        $path = $this->writeSkillMd(self::HEAD . $line . "\n");

        self::assertSame([], (new CheckSkillSize())($path));
    }

    public function testMultibyteLineOverTheCharacterLimitFails(): void
    {
        $path = $this->writeSkillMd(self::HEAD . str_repeat('ä', 2001) . "\n");

        self::assertSame(['SKILL.md:7: line has 2001 characters, max 2000'], (new CheckSkillSize())($path));
    }

    public function testInvalidUtf8FallsBackToBytesWithoutCrashing(): void
    {
        $path = $this->writeSkillMd(self::HEAD . str_repeat("\xFF", 2001) . "\n");

        self::assertSame(['SKILL.md:7: line has 2001 characters, max 2000'], (new CheckSkillSize())($path));
    }

    public function testLeafAtTheCharacterLimitPasses(): void
    {
        $path = $this->writeSkillMd(self::HEAD . "Body.\n");
        $this->writeLeaf('x.md', "# X\n" . str_repeat('b', 2000) . "\n");

        self::assertSame([], (new CheckSkillSize())($path));
    }

    public function testLongLineInALeafFails(): void
    {
        $path = $this->writeSkillMd(self::HEAD . "Body.\n");
        $this->writeLeaf('x.md', "# X\n" . str_repeat('b', 2114) . "\n");

        self::assertSame(['reference/x.md:2: line has 2114 characters, max 2000'], (new CheckSkillSize())($path));
    }

    public function testSkillMdOverTheIndexCapFailsWhenLeavesExist(): void
    {
        $path = $this->writeSkillMd($this->contentOfBytes(12289));
        $this->writeLeaf('topic.md', "# Topic\n");

        self::assertSame(
            ['SKILL.md: 12289 bytes exceeds the index cap of 12288'],
            (new CheckSkillSize())($path),
        );
    }

    public function testSkillMdAtTheIndexCapPassesWhenLeavesExist(): void
    {
        $path = $this->writeSkillMd($this->contentOfBytes(12288));
        $this->writeLeaf('topic.md', "# Topic\n");

        self::assertSame([], (new CheckSkillSize())($path));
    }

    public function testEmptyReferenceDirectoryKeepsTheFileCap(): void
    {
        $path = $this->writeSkillMd($this->contentOfBytes(20000));
        $this->project->mkdir('skills/some-skill/reference');

        self::assertSame([], (new CheckSkillSize())($path));
    }

    public function testSubdirectoryAndNonMarkdownFileAreNotLeaves(): void
    {
        $path = $this->writeSkillMd($this->contentOfBytes(20000));
        $this->project->writeFile('skills/some-skill/reference/notes.txt', $this->contentOfBytes(40000));
        $this->project->writeFile('skills/some-skill/reference/nested/deep.md', str_repeat('d', 3000) . "\n");
        $this->project->mkdir('skills/some-skill/reference/folder.md');

        self::assertSame([], (new CheckSkillSize())($path));
    }

    public function testSkillPathWithGlobMetacharactersStillFindsLeaves(): void
    {
        $path = $this->project->writeFile('[proj]/skills/some-skill/SKILL.md', self::HEAD . "Body.\n");
        $this->project->writeFile('[proj]/skills/some-skill/reference/big.md', $this->contentOfBytes(30721));

        self::assertSame(
            ['reference/big.md: 30721 bytes exceeds the leaf cap of 30720'],
            (new CheckSkillSize())($path),
        );
    }

    public function testLeafAtTheLeafCapPasses(): void
    {
        $path = $this->writeSkillMd(self::HEAD . "Body.\n");
        $this->writeLeaf('small.md', $this->contentOfBytes(30720));

        self::assertSame([], (new CheckSkillSize())($path));
    }

    public function testFileOverTheByteCapIsReportedWithoutScanningItsLines(): void
    {
        $path = $this->writeSkillMd(str_repeat('a', 2500) . "\n" . $this->contentOfBytes(30720));

        self::assertSame(['SKILL.md: 33221 bytes exceeds the cap of 30720'], (new CheckSkillSize())($path));
    }

    public function testUnreadableFileIsReported(): void
    {
        $path = $this->writeSkillMd(self::HEAD . "Body.\n");
        chmod($path, 0o000);
        if (is_readable($path)) {
            chmod($path, 0o644);
            self::markTestSkipped('File permissions are not enforced for this user.');
        }

        try {
            self::assertSame(['cannot read file: SKILL.md'], (new CheckSkillSize())($path));
        } finally {
            chmod($path, 0o644);
        }
    }

    public function testSkillMdWithoutLeavesMayExceedTheIndexCap(): void
    {
        $path = $this->writeSkillMd($this->contentOfBytes(20000));

        self::assertSame([], (new CheckSkillSize())($path));
    }

    public function testSkillMdWithoutLeavesOverTheFileCapFails(): void
    {
        $path = $this->writeSkillMd($this->contentOfBytes(30721));

        self::assertSame(['SKILL.md: 30721 bytes exceeds the cap of 30720'], (new CheckSkillSize())($path));
    }

    public function testLeafOverTheLeafCapFails(): void
    {
        $path = $this->writeSkillMd(self::HEAD . "Body.\n");
        $this->writeLeaf('big.md', $this->contentOfBytes(30721));
        $this->writeLeaf('small.md', $this->contentOfBytes(30720));

        self::assertSame(
            ['reference/big.md: 30721 bytes exceeds the leaf cap of 30720'],
            (new CheckSkillSize())($path),
        );
    }

    public function testMissingFileIsReported(): void
    {
        $errors = (new CheckSkillSize())($this->project->path('skills/none/SKILL.md'));

        self::assertCount(1, $errors);
        self::assertStringContainsString('file does not exist', $errors[0]);
    }

    private function writeSkillMd(string $content): string
    {
        return $this->project->writeFile('skills/some-skill/SKILL.md', $content);
    }

    private function writeLeaf(string $name, string $content): void
    {
        $this->project->writeFile('skills/some-skill/reference/' . $name, $content);
    }

    /** Content of exactly $bytes bytes in lines of 100 characters. */
    private function contentOfBytes(int $bytes): string
    {
        $content = substr(str_repeat(str_repeat('c', 99) . "\n", intdiv($bytes, 100) + 1), 0, $bytes);
        self::assertSame($bytes, strlen($content));

        return $content;
    }
}
