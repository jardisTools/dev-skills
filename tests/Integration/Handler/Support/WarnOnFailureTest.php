<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Support;

use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Handler\Support\WarnOnFailure;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class WarnOnFailureTest extends TestCase
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

    public function testSuccessfulAddonRunsWithTheSameArgumentsAndAddsNoWarning(): void
    {
        $report = new InstallReport();
        $target = $this->project->path('addon-output.txt');

        $decorated = new WarnOnFailure($this->writeAddon($target), 'demo');
        $decorated($this->project->root, $this->project->path('vendor'), $report);

        self::assertSame($this->project->path('vendor'), file_get_contents($target));
        self::assertSame([], $report->warnings());
    }

    public function testRealObstacleBecomesAWarningInsteadOfAnException(): void
    {
        $report = new InstallReport();
        // A directory where the add-on wants to write a file: a real filesystem failure.
        $target = $this->project->mkdir('addon-output.txt');

        $decorated = new WarnOnFailure($this->writeAddon($target), 'demo');
        $decorated($this->project->root, $this->project->path('vendor'), $report);

        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('add-on "demo" failed and was skipped', $report->warnings()[0]);
        self::assertStringContainsString($target, $report->warnings()[0]);
        self::assertDirectoryExists($target);
    }

    /**
     * @return \Closure(string, string, InstallReport): void
     */
    private function writeAddon(string $target): \Closure
    {
        return static function (string $projectRoot, string $vendorDir, InstallReport $report) use ($target): void {
            if (@file_put_contents($target, $vendorDir) === false) {
                throw new \RuntimeException('Could not write ' . $target);
            }
        };
    }
}
