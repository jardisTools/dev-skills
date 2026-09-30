<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\InstallAddons;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class InstallAddonsTest extends TestCase
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

    public function testWithoutAddonsNothingHappens(): void
    {
        $report = new InstallReport();

        (new InstallAddons())($this->project->root, $this->project->path('vendor'), $report);

        self::assertSame([], $report->warnings());
        self::assertSame([], glob($this->project->root . '/*') ?: []);
    }

    public function testAFailingAddonWarnsAndTheNextAddonStillRuns(): void
    {
        $report = new InstallReport();
        $blocked = $this->project->mkdir('first.txt');
        $second = $this->project->path('second.txt');

        $addons = new InstallAddons([
            'first' => static function () use ($blocked): void {
                if (@file_put_contents($blocked, 'x') === false) {
                    throw new \RuntimeException('cannot write ' . $blocked);
                }
            },
            'second' => static function () use ($second): void {
                file_put_contents($second, 'done');
            },
        ]);
        $addons($this->project->root, $this->project->path('vendor'), $report);

        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('add-on "first"', $report->warnings()[0]);
        self::assertSame('done', file_get_contents($second));
    }
}
