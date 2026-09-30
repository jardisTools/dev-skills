<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Handler\Install\ResolveTargets;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class ResolveTargetsTest extends TestCase
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

    public function testCreatesAndReturnsBothTargets(): void
    {
        $targets = (new ResolveTargets(new Filesystem()))($this->project->root);

        self::assertSame(
            [
                realpath($this->project->path('.claude/skills')),
                realpath($this->project->path('.agents/skills')),
            ],
            $targets,
        );
    }

    public function testSymlinkedTargetIsReturnedOnce(): void
    {
        $this->project->mkdir('.claude/skills');
        $this->project->mkdir('.agents');
        self::assertTrue(symlink($this->project->path('.claude/skills'), $this->project->path('.agents/skills')));

        $targets = (new ResolveTargets(new Filesystem()))($this->project->root);

        self::assertSame([realpath($this->project->path('.claude/skills'))], $targets);

        unlink($this->project->path('.agents/skills'));
    }
}
