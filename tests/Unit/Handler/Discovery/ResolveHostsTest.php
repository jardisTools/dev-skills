<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Unit\Handler\Discovery;

use JardisTools\DevSkills\Data\Host;
use JardisTools\DevSkills\Handler\Discovery\ResolveHosts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit: a pure function of its two arguments, no outside world.
 */
final class ResolveHostsTest extends TestCase
{
    public function testAbsentKeyYieldsOnlyClaudeWithoutWarning(): void
    {
        self::assertSame([[Host::Claude], null], (new ResolveHosts())(false, null));
    }

    public function testValidListYieldsTheHostsInEnumOrderWithoutDuplicates(): void
    {
        [$hosts, $warning] = (new ResolveHosts())(true, ['gemini', 'claude', 'gemini', 'codex']);

        self::assertSame([Host::Claude, Host::Codex, Host::Gemini], $hosts);
        self::assertNull($warning);
    }

    public function testEmptyListIsValidAndYieldsNoHost(): void
    {
        self::assertSame([[], null], (new ResolveHosts())(true, []));
    }

    public function testAllFiveNamesAreAccepted(): void
    {
        [$hosts] = (new ResolveHosts())(true, ['claude', 'codex', 'cursor', 'copilot', 'gemini']);

        self::assertSame(Host::cases(), $hosts);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidValues(): array
    {
        return [
            'string instead of list' => ['claude', 'got string instead of a list'],
            'bool' => [true, 'got bool instead of a list'],
            'null' => [null, 'got null instead of a list'],
            'map' => [['claude' => true], 'got array instead of a list'],
            'unknown name' => [['claude', 'vim'], 'entry "vim" is unknown'],
            'wrong case' => [['Claude'], 'entry "Claude" is unknown'],
            'non-string entry' => [[1], 'entry int is unknown'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValueWarnsClearlyAndFallsBackToClaude(mixed $raw, string $reason): void
    {
        [$hosts, $warning] = (new ResolveHosts())(true, $raw);

        self::assertSame([Host::Claude], $hosts);
        self::assertSame(
            'hosts must be a list of "claude", "codex", "cursor", "copilot", "gemini"; '
            . $reason . '. Treated as hosts=["claude"].',
            $warning,
        );
    }
}
