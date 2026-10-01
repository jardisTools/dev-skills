<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\ProcessDocsMode;
use JardisTools\DevSkills\Handler\Install\ResolveGitDir;
use JardisTools\DevSkills\Handler\Support\RunGit;
use JardisTools\DevSkills\Tests\Support\AddonFactory;
use JardisTools\DevSkills\Tests\Support\GitRepo;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class ResolveGitDirTest extends TestCase
{
    private TempProject $project;
    private TempProject $worktree;

    protected function setUp(): void
    {
        $this->project = new TempProject('dev-skills-repo-');
        $this->worktree = new TempProject('dev-skills-worktree-');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
        $this->worktree->cleanup();
    }

    public function testResolvesTheExcludeFileOfARegularRepository(): void
    {
        GitRepo::init($this->project->root);

        self::assertSame(
            realpath($this->project->root) . '/.git/info/exclude',
            $this->resolve($this->project->root),
        );
    }

    public function testResolvesExcludeFileInWorktreeWhereDotGitIsAFile(): void
    {
        GitRepo::init($this->project->root);
        $this->project->writeFile('src/own.php', 'x');
        GitRepo::commitAll($this->project->root);
        GitRepo::run($this->project->root, 'worktree', 'add', '-q', '-b', 'linked', $this->worktree->root);
        self::assertFileExists($this->worktree->path('.git'));
        self::assertTrue(is_file($this->worktree->path('.git')), '.git of a linked work tree is a file');

        $exclude = $this->resolve($this->worktree->root);

        self::assertSame(realpath($this->project->root) . '/.git/info/exclude', $exclude, 'the shared Git directory');

        // The block written there takes effect in the work tree, as `git status` shows.
        $this->worktree->writeFile('.claude/.jardis-backup/b/SKILL.md', 'x');
        $this->worktree->writeFile('docs/vorhaben/plan.md', 'x');
        self::assertContains('docs/vorhaben/plan.md', GitRepo::visiblePaths($this->worktree->root));

        $report = new InstallReport();
        AddonFactory::syncExcludeBlock(ProcessDocsMode::Local)($this->worktree->root, $this->worktree->path('vendor'), $report);

        self::assertSame([], $report->warnings());
        self::assertFileExists($exclude);
        self::assertSame([], GitRepo::visiblePaths($this->worktree->root));
    }

    public function testNoPathWithoutRepository(): void
    {
        $location = (new ResolveGitDir((new RunGit())->__invoke(...)))($this->project->root);

        self::assertNull($location->path);
        self::assertNull($location->enclosingWorkTree);
    }

    public function testNoPathBelowTheTopOfALargerRepositoryAndNamesItsWorkTree(): void
    {
        GitRepo::init($this->project->root);
        $inner = $this->project->mkdir('packages/app');

        $location = (new ResolveGitDir((new RunGit())->__invoke(...)))($inner);

        self::assertNull($location->path, 'patterns would be relative to another directory');
        self::assertSame(realpath($this->project->root), realpath((string) $location->enclosingWorkTree));
    }

    private function resolve(string $projectRoot): ?string
    {
        return (new ResolveGitDir((new RunGit())->__invoke(...)))($projectRoot)->path;
    }
}
