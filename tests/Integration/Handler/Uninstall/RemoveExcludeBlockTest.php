<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Uninstall;

use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\ProcessDocsMode;
use JardisTools\DevSkills\Data\UninstallReport;
use JardisTools\DevSkills\Tests\Support\AddonFactory;
use JardisTools\DevSkills\Tests\Support\GitRepo;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class RemoveExcludeBlockTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        GitRepo::init($this->project->root);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testRemovesWholeBlockKeepsForeignContent(): void
    {
        $exclude = $this->project->path('.git/info/exclude');
        $foreign = "# own\r\n*.log\r\n";
        $trailing = "tail-without-newline";
        file_put_contents($exclude, $foreign);

        foreach ([ProcessDocsMode::Committed, ProcessDocsMode::Local] as $mode) {
            AddonFactory::syncExcludeBlock($mode)($this->project->root, $this->project->path('vendor'), new InstallReport());
            self::assertStringContainsString('jardis/dev-skills', (string) file_get_contents($exclude));

            $report = new UninstallReport();
            AddonFactory::removeExcludeBlock()($this->project->root, null, $report);

            self::assertSame([], $report->warnings());
            self::assertSame($foreign, file_get_contents($exclude), 'block gone, backup line included');
        }

        file_put_contents($exclude, $foreign . $trailing);
        AddonFactory::syncExcludeBlock(ProcessDocsMode::Local)($this->project->root, $this->project->path('vendor'), new InstallReport());
        AddonFactory::removeExcludeBlock()($this->project->root, null, new UninstallReport());
        self::assertStringStartsWith($foreign . $trailing, (string) file_get_contents($exclude));
        self::assertStringNotContainsString('jardis', (string) file_get_contents($exclude));
    }

    public function testNothingToDoWithoutBlockOrRepository(): void
    {
        $exclude = $this->project->path('.git/info/exclude');
        file_put_contents($exclude, "*.log\n");
        $mtime = filemtime($exclude);
        AddonFactory::removeExcludeBlock()($this->project->root, null, new UninstallReport());
        self::assertSame("*.log\n", file_get_contents($exclude));
        self::assertSame($mtime, filemtime($exclude));

        $plain = new TempProject('dev-skills-nogit-');
        try {
            $report = new UninstallReport();
            AddonFactory::removeExcludeBlock()($plain->root, null, $report);
            self::assertSame([], $report->warnings());
            self::assertSame([], glob($plain->root . '/*') ?: []);
        } finally {
            $plain->cleanup();
        }
    }

    public function testCorruptMarkersThrowAndLeaveTheFileUntouched(): void
    {
        $exclude = $this->project->path('.git/info/exclude');
        $corrupt = "# END jardis/dev-skills\nkeep\n";
        file_put_contents($exclude, $corrupt);

        try {
            AddonFactory::removeExcludeBlock()($this->project->root, null, new UninstallReport());
            self::fail('corrupt markers must throw so the add-on decorator can warn');
        } catch (\JardisTools\DevSkills\Exception\InstallFailedException $failure) {
            self::assertStringContainsString('corrupt managed-block markers', $failure->getMessage());
        }
        self::assertSame($corrupt, file_get_contents($exclude));
    }
}
