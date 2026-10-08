<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Unit\Handler\Shell;

use JardisTools\DevSkills\Handler\Shell\SummarizeLeftUnchanged;
use PHPUnit\Framework\TestCase;

/**
 * Unit: a pure function of its argument.
 */
final class SummarizeLeftUnchangedTest extends TestCase
{
    public function testNoFileYieldsNoNotice(): void
    {
        self::assertNull((new SummarizeLeftUnchanged())([]));
    }

    public function testOneFileYieldsOneLine(): void
    {
        self::assertSame(
            '1 agent file(s) under .claude/agents are not reviewer shells of the plugin'
            . ' and were left unchanged (a.md)',
            (new SummarizeLeftUnchanged())(['.claude/agents/a.md']),
        );
    }

    public function testNineteenFilesYieldOneLineWithShortenedNames(): void
    {
        $paths = [];
        foreach (range(1, 19) as $i) {
            $paths[] = ".claude/agents/r{$i}.md";
        }

        $notice = (new SummarizeLeftUnchanged())($paths);

        self::assertNotNull($notice);
        self::assertStringNotContainsString("\n", $notice);
        self::assertStringStartsWith('19 agent file(s) under .claude/agents', $notice);
        self::assertStringEndsWith('(r1.md, r2.md, r3.md, r4.md, r5.md, +14 more)', $notice);
    }
}
