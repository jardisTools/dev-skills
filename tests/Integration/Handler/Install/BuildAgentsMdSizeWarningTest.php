<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Handler\Install\BuildAgentsMdSizeWarning;
use PHPUnit\Framework\TestCase;

final class BuildAgentsMdSizeWarningTest extends TestCase
{
    public function testNoWarningAtTheLimit(): void
    {
        self::assertNull((new BuildAgentsMdSizeWarning())(32768));
    }

    public function testNoWarningBelowTheLimit(): void
    {
        self::assertNull((new BuildAgentsMdSizeWarning())(1));
    }

    public function testWarnsOneByteAboveTheLimitWithSizeAndLimit(): void
    {
        $warning = (new BuildAgentsMdSizeWarning())(32769);

        self::assertNotNull($warning);
        self::assertStringContainsString('32769 bytes', $warning);
        self::assertStringContainsString('32768 bytes', $warning);
        self::assertStringContainsString('project_doc_max_bytes', $warning);
    }
}
