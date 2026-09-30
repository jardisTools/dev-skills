<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Support;

use JardisTools\DevSkills\Handler\Support\IsGlobalContext;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class IsGlobalContextTest extends TestCase
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

    public function testWorkingDirectoryEqualToComposerHomeIsGlobal(): void
    {
        $home = $this->project->mkdir('home');

        self::assertTrue((new IsGlobalContext())($home, $home));
        self::assertTrue((new IsGlobalContext())($home . '/../home', $home));
    }

    public function testProjectDirectoryIsNotGlobal(): void
    {
        $home = $this->project->mkdir('home');
        $projectDir = $this->project->mkdir('project');

        self::assertFalse((new IsGlobalContext())($projectDir, $home));
    }

    public function testMissingOrUnusableComposerHomeIsNotGlobal(): void
    {
        $projectDir = $this->project->mkdir('project');

        self::assertFalse((new IsGlobalContext())($projectDir, null));
        self::assertFalse((new IsGlobalContext())($projectDir, ''));
        self::assertFalse((new IsGlobalContext())($projectDir, $this->project->path('does-not-exist')));
    }
}
