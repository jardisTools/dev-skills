<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\AgentsDescriptor;
use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Handler\Install\AggregateAgentsMd;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Handler\Install\BuildManagedBlock;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class AggregateAgentsMdTest extends TestCase
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

    public function testWritesManagedAgentsMd(): void
    {
        $descriptors = [
            new AgentsDescriptor('jardisadapter/cache', "# cache\nCache rules."),
            new AgentsDescriptor('jardissupport/data', "# data\nData rules."),
        ];

        $result = (new AggregateAgentsMd(new Filesystem()))($descriptors, $this->project->root);

        self::assertSame(2, $result->aggregatedCount);
        self::assertNull($result->backupPath);
        $content = file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringContainsString('BEGIN jardis/dev-skills', $content);
        self::assertStringContainsString('END jardis/dev-skills', $content);
        self::assertStringContainsString('source: jardisadapter/cache', $content);
        self::assertStringContainsString('Cache rules.', $content);
        self::assertStringContainsString('Data rules.', $content);
    }

    public function testReturnsZeroAndSkipsFileWhenNoDescriptors(): void
    {
        $result = (new AggregateAgentsMd(new Filesystem()))([], $this->project->root);

        self::assertSame(0, $result->aggregatedCount);
        self::assertNull($result->backupPath);
        self::assertFileDoesNotExist($this->project->path('AGENTS.md'));
    }

    public function testBacksUpExistingUserAgentsMdOnFirstRun(): void
    {
        $userContent = "# My hand-written AGENTS\n\nRules here.\n";
        $this->project->writeFile('AGENTS.md', $userContent);

        $descriptors = [new AgentsDescriptor('jardisadapter/cache', '# cache')];
        $result = (new AggregateAgentsMd(new Filesystem()))($descriptors, $this->project->root);

        self::assertSame($this->project->path('AGENTS.md.backup'), $result->backupPath);
        self::assertFileExists($this->project->path('AGENTS.md.backup'));
        self::assertSame($userContent, file_get_contents($this->project->path('AGENTS.md.backup')));

        $written = file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringStartsWith('# My hand-written AGENTS', $written);
        self::assertStringContainsString(AnalyzeAgentsMd::HEADER, $written);
        self::assertStringContainsString(AnalyzeAgentsMd::FOOTER, $written);
    }

    public function testReplacesManagedBlockInPlaceOnReRun(): void
    {
        $existing = "# User top\n\n"
            . AnalyzeAgentsMd::HEADER . "\nold managed body\n" . AnalyzeAgentsMd::FOOTER
            . "\n\n# User bottom\n";
        $this->project->writeFile('AGENTS.md', $existing);

        $descriptors = [new AgentsDescriptor('jardisadapter/cache', 'fresh content')];
        $result = (new AggregateAgentsMd(new Filesystem()))($descriptors, $this->project->root);

        self::assertNull($result->backupPath);
        self::assertFileDoesNotExist($this->project->path('AGENTS.md.backup'));

        $written = file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringStartsWith("# User top\n\n", $written);
        self::assertStringEndsWith("# User bottom\n", $written);
        self::assertStringContainsString('fresh content', $written);
        self::assertStringNotContainsString('old managed body', $written);
    }

    public function testHealsDuplicateManagedBlockIntoSingleBlock(): void
    {
        // Nested duplicate (BEGIN BEGIN END END) — the foundation regression.
        $existing = "# User top\n\n"
            . AnalyzeAgentsMd::HEADER . "\n"
            . AnalyzeAgentsMd::HEADER . "\nstale body\n"
            . AnalyzeAgentsMd::FOOTER . "\n"
            . AnalyzeAgentsMd::FOOTER
            . "\n\n# User bottom\n";
        $this->project->writeFile('AGENTS.md', $existing);

        $descriptors = [new AgentsDescriptor('jardisadapter/cache', 'fresh content')];
        $result = (new AggregateAgentsMd(new Filesystem()))($descriptors, $this->project->root);

        self::assertTrue($result->healedDuplicateBlock);
        self::assertNull($result->backupPath);

        $written = file_get_contents($this->project->path('AGENTS.md'));
        self::assertSame(1, substr_count($written, AnalyzeAgentsMd::HEADER));
        self::assertSame(1, substr_count($written, AnalyzeAgentsMd::FOOTER));
        self::assertStringStartsWith("# User top\n\n", $written);
        self::assertStringEndsWith("# User bottom\n", $written);
        self::assertStringContainsString('fresh content', $written);
        self::assertStringNotContainsString('stale body', $written);
    }

    public function testHealingIsIdempotentOnReRun(): void
    {
        $existing = "# User top\n\n"
            . AnalyzeAgentsMd::HEADER . "\n"
            . AnalyzeAgentsMd::HEADER . "\nstale body\n"
            . AnalyzeAgentsMd::FOOTER . "\n"
            . AnalyzeAgentsMd::FOOTER
            . "\n\n# User bottom\n";
        $this->project->writeFile('AGENTS.md', $existing);

        $descriptors = [new AgentsDescriptor('jardisadapter/cache', 'fresh content')];
        $aggregate = new AggregateAgentsMd(new Filesystem());

        $first = $aggregate($descriptors, $this->project->root);
        self::assertTrue($first->healedDuplicateBlock);
        $afterFirst = file_get_contents($this->project->path('AGENTS.md'));

        $second = $aggregate($descriptors, $this->project->root);
        self::assertFalse($second->healedDuplicateBlock);
        $afterSecond = file_get_contents($this->project->path('AGENTS.md'));

        self::assertSame($afterFirst, $afterSecond);
    }

    public function testThrowsOnCorruptMarkers(): void
    {
        $this->project->writeFile(
            'AGENTS.md',
            "head\n" . AnalyzeAgentsMd::HEADER . "\nno footer here\n",
        );

        $this->expectException(InstallFailedException::class);
        (new AggregateAgentsMd(new Filesystem()))(
            [new AgentsDescriptor('jardisadapter/cache', 'x')],
            $this->project->root,
        );
    }

    public function testWritesManagedBlockWithPointerWhenCatalogInstalledAndNoDescriptors(): void
    {
        // Early-return must NOT fire when descriptors are empty but catalog is installed.
        $result = (new AggregateAgentsMd(new Filesystem()))([], $this->project->root, true);

        self::assertSame(0, $result->aggregatedCount);
        self::assertNull($result->backupPath);
        $content = file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringContainsString(AnalyzeAgentsMd::HEADER, $content);
        self::assertStringContainsString(AnalyzeAgentsMd::FOOTER, $content);
        self::assertStringContainsString(BuildManagedBlock::CATALOG_POINTER, $content);
    }

    public function testSkipsFileWhenNoDescriptorsAndCatalogNotInstalled(): void
    {
        // Existing early-return behaviour must be preserved when catalogInstalled = false.
        $result = (new AggregateAgentsMd(new Filesystem()))([], $this->project->root, false);

        self::assertSame(0, $result->aggregatedCount);
        self::assertNull($result->backupPath);
        self::assertFileDoesNotExist($this->project->path('AGENTS.md'));
    }

    public function testCatalogPointerIsIdempotentOnReRun(): void
    {
        $descriptors = [new AgentsDescriptor('jardisadapter/cache', 'Cache rules.')];
        $aggregate   = new AggregateAgentsMd(new Filesystem());

        $aggregate($descriptors, $this->project->root, true);
        $afterFirst = file_get_contents($this->project->path('AGENTS.md'));

        $aggregate($descriptors, $this->project->root, true);
        $afterSecond = file_get_contents($this->project->path('AGENTS.md'));

        self::assertSame($afterFirst, $afterSecond);
        self::assertSame(1, substr_count($afterSecond, BuildManagedBlock::CATALOG_POINTER));
    }

    public function testTextOutsideMarkersIsByteEqualWithCrlf(): void
    {
        $pre = "# User top\r\n\r\nRules.\r\n\r\n";
        $post = "\r\n\r\n# User bottom\r\nEnd.\r\n";
        $existing = $pre
            . AnalyzeAgentsMd::HEADER . "\r\nold managed body\r\n" . AnalyzeAgentsMd::FOOTER
            . $post;
        $this->project->writeFile('AGENTS.md', $existing);

        $descriptors = [new AgentsDescriptor('jardisadapter/cache', 'fresh content')];
        $result = (new AggregateAgentsMd(new Filesystem()))($descriptors, $this->project->root, false, '# Router');

        self::assertNull($result->backupPath);
        self::assertFileDoesNotExist($this->project->path('AGENTS.md.backup'));

        $written = file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringStartsWith($pre . AnalyzeAgentsMd::HEADER, $written);
        self::assertStringEndsWith(AnalyzeAgentsMd::FOOTER . $post, $written);
        self::assertSame(1, substr_count($written, AnalyzeAgentsMd::HEADER));
        self::assertStringContainsString('fresh content', $written);
        self::assertStringNotContainsString('old managed body', $written);
    }

    public function testUserFileWithoutMarkersKeepsCrlfAboveTheBlock(): void
    {
        $this->project->writeFile('AGENTS.md', "# User\r\nRules.\r\n");

        (new AggregateAgentsMd(new Filesystem()))(
            [new AgentsDescriptor('jardisadapter/cache', 'x')],
            $this->project->root,
        );

        $written = file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringStartsWith("# User\r\nRules.\r\n\r\n" . AnalyzeAgentsMd::HEADER, $written);
        self::assertSame("# User\r\nRules.\r\n", file_get_contents($this->project->path('AGENTS.md.backup')));
    }

    public function testWarnsWithSizeAndLimitAboveCodexLimit(): void
    {
        $descriptors = [new AgentsDescriptor('jardisadapter/cache', str_repeat("Cache rules line.\n", 2500))];

        $result = (new AggregateAgentsMd(new Filesystem()))($descriptors, $this->project->root, false, '# Router');

        $size = filesize($this->project->path('AGENTS.md'));
        self::assertGreaterThan(32768, $size);
        self::assertNotNull($result->sizeWarning);
        self::assertStringContainsString((string) $size . ' bytes', $result->sizeWarning);
        self::assertStringContainsString('32768', $result->sizeWarning);
        self::assertStringContainsString('project_doc_max_bytes', $result->sizeWarning);
    }

    public function testNoWarningBelowCodexLimit(): void
    {
        $result = (new AggregateAgentsMd(new Filesystem()))(
            [new AgentsDescriptor('jardisadapter/cache', 'small')],
            $this->project->root,
            false,
            '# Router',
        );

        self::assertNull($result->sizeWarning);
    }

    public function testRouterLiesWithinFirst32KiBWithLargeVendorAgentsMd(): void
    {
        $descriptors = [
            new AgentsDescriptor('jardisadapter/cache', str_repeat("Cache rules line.\n", 2500)),
            new AgentsDescriptor('jardissupport/data', str_repeat("Data rules line.\n", 2500)),
        ];

        (new AggregateAgentsMd(new Filesystem()))(
            $descriptors,
            $this->project->root,
            true,
            "# Router\nROUTER-END-MARK",
        );

        $written = file_get_contents($this->project->path('AGENTS.md'));
        $routerEnd = strpos($written, 'ROUTER-END-MARK');
        self::assertNotFalse($routerEnd);
        self::assertLessThan(32768, $routerEnd + strlen('ROUTER-END-MARK'));
        self::assertLessThan(strpos($written, '# Jardis packages'), $routerEnd);
        self::assertLessThan(strpos($written, 'source: jardisadapter/cache'), $routerEnd);
    }

    public function testSecondRunWithRouterIsByteIdentical(): void
    {
        $this->project->writeFile('AGENTS.md', "# User top\n");
        $descriptors = [new AgentsDescriptor('jardisadapter/cache', 'Cache rules.')];
        $aggregate = new AggregateAgentsMd(new Filesystem());

        $aggregate($descriptors, $this->project->root, true, "# Router\nRoute here.");
        $afterFirst = file_get_contents($this->project->path('AGENTS.md'));

        $second = $aggregate($descriptors, $this->project->root, true, "# Router\nRoute here.");
        $afterSecond = file_get_contents($this->project->path('AGENTS.md'));

        self::assertSame($afterFirst, $afterSecond);
        self::assertNull($second->backupPath);
        self::assertSame(1, substr_count($afterSecond, 'Route here.'));
    }

    public function testWritesBlockWithRouterOnlyWhenNoDescriptorsAndNoCatalog(): void
    {
        $result = (new AggregateAgentsMd(new Filesystem()))([], $this->project->root, false, '# Router');

        self::assertSame(0, $result->aggregatedCount);
        $content = file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringContainsString('# Router', $content);
        self::assertStringContainsString(AnalyzeAgentsMd::FOOTER, $content);
    }

    public function testDirectoryAtAgentsMdTargetFailsAsCoreError(): void
    {
        $this->project->mkdir('AGENTS.md');

        $this->expectException(InstallFailedException::class);
        (new AggregateAgentsMd(new Filesystem()))(
            [new AgentsDescriptor('jardisadapter/cache', 'x')],
            $this->project->root,
            false,
            '# Router',
        );
    }

    public function testLinkLeadingOutOfTheProjectGetsNoBlockAndWarns(): void
    {
        $outside = new TempProject('dev-skills-outside-');
        try {
            $outside->writeFile('notes.md', "# Notes\n");
            self::assertTrue(symlink($outside->path('notes.md'), $this->project->path('AGENTS.md')));

            $result = (new AggregateAgentsMd(new Filesystem()))(
                [new AgentsDescriptor('jardisadapter/cache', '# cache')],
                $this->project->root,
                true,
                '# Router',
            );

            self::assertSame("# Notes\n", file_get_contents($outside->path('notes.md')));
            self::assertTrue(is_link($this->project->path('AGENTS.md')));
            self::assertFileDoesNotExist($this->project->path('AGENTS.md.backup'));
            self::assertFileDoesNotExist($outside->path('notes.md.backup'));
            self::assertSame(0, $result->aggregatedCount);
            self::assertNull($result->backupPath);
            self::assertFalse($result->agentsMdCreated);
            self::assertNull($result->sizeWarning);
            self::assertStringContainsString('AGENTS.md is a link', (string) $result->skippedWarning);
        } finally {
            $outside->cleanup();
        }
    }

    public function testDanglingLinkGetsNoBlockAndWarns(): void
    {
        self::assertTrue(symlink($this->project->path('nowhere.md'), $this->project->path('AGENTS.md')));

        $result = (new AggregateAgentsMd(new Filesystem()))(
            [new AgentsDescriptor('jardisadapter/cache', '# cache')],
            $this->project->root,
        );

        self::assertTrue(is_link($this->project->path('AGENTS.md')));
        self::assertFileDoesNotExist($this->project->path('nowhere.md'));
        self::assertFileDoesNotExist($this->project->path('AGENTS.md.backup'));
        self::assertFalse($result->agentsMdCreated);
        self::assertStringContainsString('AGENTS.md is a link', (string) $result->skippedWarning);
    }

    public function testLinkToClaudeMdInsideTheProjectGetsNoBlockAndWarns(): void
    {
        $claude = "# Claude rules\r\n\r\nKeep.\r\n";
        $this->project->writeFile('CLAUDE.md', $claude);
        self::assertTrue(symlink('CLAUDE.md', $this->project->path('AGENTS.md')));

        $result = (new AggregateAgentsMd(new Filesystem()))(
            [new AgentsDescriptor('jardisadapter/cache', '# cache')],
            $this->project->root,
        );

        self::assertSame($claude, file_get_contents($this->project->path('CLAUDE.md')));
        self::assertTrue(is_link($this->project->path('AGENTS.md')));
        self::assertFileDoesNotExist($this->project->path('AGENTS.md.backup'));
        self::assertFileDoesNotExist($this->project->path('CLAUDE.md.backup'));
        self::assertSame(0, $result->aggregatedCount);
        self::assertStringContainsString('regular file', (string) $result->skippedWarning);
        self::assertStringContainsString('`@AGENTS.md`', (string) $result->skippedWarning);
    }
}
