<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use JardisTools\DevSkills\Exception\PoolNotFoundException;
use JardisTools\DevSkills\Handler\Pool\FormatReport;
use JardisTools\DevSkills\PoolCheck;
use JardisTools\DevSkills\Tests\Support\PoolFixture;
use JardisTools\DevSkills\Tests\Support\RunScript;
use JardisTools\DevSkills\Tests\Support\TempProject;
use JardisTools\DevSkills\Tests\Support\TreeSnapshot;
use PHPUnit\Framework\TestCase;

final class PoolCheckTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../scripts/pool-check.php';

    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testOneLineWithFileAndLinePerViolationPlusSummary(): void
    {
        PoolFixture::install($this->project, 'red-pathrefs');

        $lines = (new FormatReport())((new PoolCheck())($this->project->root, true));

        self::assertCount(3, $lines);
        self::assertMatchesRegularExpression('#^\.claude/wissen/refs-page\.md:\d+ path-dead .+$#', $lines[0]);
        self::assertMatchesRegularExpression('#^\.claude/wissen/refs-page\.md:\d+ line-dead .+$#', $lines[1]);
        self::assertSame('2 violation(s) in 1 file(s) (1 page(s) checked).', $lines[2]);
    }

    public function testCleanPoolEndsWithOneSummaryLine(): void
    {
        PoolFixture::install($this->project, 'green');

        $lines = (new FormatReport())((new PoolCheck())($this->project->root, true));

        self::assertSame(['Pool clean (2 page(s) checked).'], $lines);
    }

    public function testEveryRedFixtureFailsOnItsOwnRule(): void
    {
        $expected = [
            'red-structure'   => ['section-foreign', 'section-missing', 'section-order'],
            'red-links'       => ['link-dead'],
            'red-supersession' => ['edge-dead'],
            'red-pathrefs'    => ['line-dead', 'path-dead'],
            'red-page-budget' => ['page-bytes', 'page-lines'],
            'red-index-budget' => ['index-bytes'],
        ];

        foreach ($expected as $case => $rules) {
            $project = new TempProject();
            try {
                PoolFixture::install($project, $case);
                $found = array_values(array_unique(array_map(
                    static fn ($v): string => $v->rule,
                    (new PoolCheck())($project->root, true)->violations,
                )));
                sort($found);

                self::assertSame($rules, $found, $case);
            } finally {
                $project->cleanup();
            }
        }
    }

    public function testChecksEveryVorhabenFolder(): void
    {
        $expected = [
            'vorhaben-green'    => [],
            'vorhaben-red-head' => [
                'docs/vorhaben/decisions-form/PROGRESS.md' => ['head-decisions'],
                'docs/vorhaben/decisions/PROGRESS.md'      => ['head-decisions'],
                'docs/vorhaben/key-order/PROGRESS.md'      => ['head-key', 'head-key'],
                'docs/vorhaben/long/PROGRESS.md'           => ['progress-lines'],
                'docs/vorhaben/missing/PROGRESS.md'        => ['head-missing'],
                'docs/vorhaben/phase/PROGRESS.md'          => ['head-phase'],
                'docs/vorhaben/position/PROGRESS.md'       => ['head-position'],
                'docs/vorhaben/stage-before/PROGRESS.md'   => ['head-stage'],
                'docs/vorhaben/stage-from/PROGRESS.md'     => ['head-stage'],
            ],
            'vorhaben-red-plan' => [
                'docs/vorhaben/plan-bytes/PLAN.md'               => ['plan-bytes'],
                'docs/vorhaben/section-long/PLAN.md'             => ['plan-section-lines'],
                'docs/vorhaben/stage-plan-bytes/PLAN-E1.md'      => ['plan-bytes'],
                'docs/vorhaben/stage-plan-long/PLAN-E1.md'       => ['plan-lines'],
            ],
        ];

        foreach ($expected as $case => $byFile) {
            $project = new TempProject();
            try {
                PoolFixture::install($project, $case);
                $found = [];
                foreach ((new PoolCheck())($project->root, true)->violations as $violation) {
                    $found[$violation->file][] = $violation->rule;
                }
                ksort($found);
                ksort($byFile);

                self::assertSame($byFile, $found, $case);
            } finally {
                $project->cleanup();
            }
        }
    }

    public function testPageTemplateKeepsTheFiveSectionsAndTheCaps(): void
    {
        $templates = __DIR__ . '/../../skills/knowledge-maintain-pool/templates/';
        $page      = (string) file_get_contents($templates . 'themenseite.md');
        $index     = (string) file_get_contents($templates . 'INDEX.md');

        // Two states of the pool, both must pass the check without a single violation (no placeholder edge):
        // the templates as they ship (page under its example id), and the scaffold exactly as
        // process-concept creates it: INDEX.md without the example line, themenseite.md copied under its own name.
        $scaffoldIndex = implode("\n", array_filter(
            explode("\n", $index),
            static fn (string $line): bool => !str_contains($line, '[[example-topic]]'),
        ));
        self::assertNotSame($index, $scaffoldIndex, 'the index template carries the example line the scaffold drops');

        $states = [
            'templates as shipped' => ['example-topic.md' => $page, 'INDEX.md' => $index],
            'scaffold as created'  => ['themenseite.md' => $page, 'INDEX.md' => $scaffoldIndex],
        ];

        $found = [];
        foreach ($states as $label => $files) {
            $project = new TempProject();
            try {
                foreach ($files as $name => $content) {
                    $project->writeFile('.claude/wissen/' . $name, $content);
                }

                $rules = array_map(
                    static fn ($v): string => $v->file . ':' . $v->line . ' ' . $v->rule . ':' . $v->message,
                    (new PoolCheck())($project->root, true)->violations,
                );

                $found[$label] = $rules;
            } finally {
                $project->cleanup();
            }
        }

        self::assertSame(['templates as shipped' => [], 'scaffold as created' => []], $found);
    }

    public function testNoVorhabenFolderIsNoViolation(): void
    {
        PoolFixture::install($this->project, 'green');
        self::assertDirectoryDoesNotExist($this->project->path('docs/vorhaben'));

        $result = (new PoolCheck())($this->project->root, true);

        self::assertSame([], $result->violations);

        $this->project->mkdir('docs/vorhaben');
        $this->project->writeFile('docs/vorhaben/no-files/notes.txt', "not a work file\n");

        self::assertSame([], (new PoolCheck())($this->project->root, true)->violations);
    }

    public function testVorhabenFolderBehindLinkIsNotFollowed(): void
    {
        $outside = new TempProject();
        try {
            $outside->writeFile('demo/PROGRESS.md', "# Progress\n\n## Notes\n");
            PoolFixture::install($this->project, 'green');
            $this->project->mkdir('docs');
            symlink($outside->root, $this->project->path('docs/vorhaben'));

            self::assertSame([], (new PoolCheck())($this->project->root, true)->violations);
        } finally {
            $outside->cleanup();
        }
    }

    public function testExitNonZeroOnViolationAndZeroOnGreen(): void
    {
        PoolFixture::install($this->project, 'green');
        $green = RunScript::run(self::SCRIPT, $this->project->root, ['--root=' . $this->project->root]);

        self::assertSame(0, $green['exit']);
        self::assertSame("Pool clean (2 page(s) checked).\n", $green['stdout']);

        $red = new TempProject();
        try {
            PoolFixture::install($red, 'red-links');
            $result = RunScript::run(self::SCRIPT, $red->root, ['--root=' . $red->root]);

            self::assertSame(1, $result['exit']);
            self::assertStringContainsString(' link-dead ', $result['stdout']);
        } finally {
            $red->cleanup();
        }
    }

    public function testExitTwoWithoutPoolOrWithBadArgument(): void
    {
        $noPool = RunScript::run(self::SCRIPT, $this->project->root);
        $badArg = RunScript::run(self::SCRIPT, $this->project->root, ['--nope']);

        self::assertSame(2, $noPool['exit']);
        self::assertStringContainsString('no knowledge pool', $noPool['stderr']);
        self::assertSame(2, $badArg['exit']);
        self::assertStringContainsString('usage:', $badArg['stderr']);
    }

    public function testNeverWritesAnything(): void
    {
        foreach (['green', 'red-structure', 'red-links', 'red-pathrefs'] as $case) {
            $project = new TempProject();
            try {
                PoolFixture::install($project, $case);
                $before = TreeSnapshot::of($project->root);

                (new PoolCheck())($project->root, true);
                RunScript::run(self::SCRIPT, $project->root, ['--root=' . $project->root]);

                self::assertSame($before, TreeSnapshot::of($project->root), $case);
            } finally {
                $project->cleanup();
            }
        }
    }

    public function testNoLinkIsFollowed(): void
    {
        $outside = new TempProject();
        try {
            $outside->writeFile('secret.md', "## Stand\n\n- [[ghost]]\n");
            PoolFixture::install($this->project, 'green');
            symlink($outside->path('secret.md'), $this->project->path('.claude/wissen/linked.md'));
            symlink($outside->root, $this->project->path('linked-dir'));
            $this->project->writeFile('.claude/wissen/via-link.md', "- [x](../../linked-dir/secret.md) `linked-dir/secret.md`\n");

            $result = (new PoolCheck())($this->project->root, true);

            $rules = array_map(static fn ($v): string => $v->rule . '@' . $v->file, $result->violations);
            self::assertContains('link-dead@.claude/wissen/via-link.md', $rules);
            self::assertContains('path-dead@.claude/wissen/via-link.md', $rules);
            self::assertSame([], array_filter($rules, static fn (string $r): bool => str_contains($r, 'linked.md')));
            self::assertSame(3, $result->pages);
        } finally {
            $outside->cleanup();
        }
    }

    public function testPoolFolderBehindLinkCountsAsMissing(): void
    {
        $outside = new TempProject();
        try {
            PoolFixture::install($outside, 'green');
            $this->project->mkdir('.claude');
            symlink($outside->path('.claude/wissen'), $this->project->path('.claude/wissen'));

            $this->expectException(PoolNotFoundException::class);

            (new PoolCheck())($this->project->root, true);
        } finally {
            $outside->cleanup();
        }
    }
}
