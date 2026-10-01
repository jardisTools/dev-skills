<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Docs;

use PHPUnit\Framework\TestCase;

/**
 * The English page `docs/overview.html` and the German page `docs/overview.de.html`
 * are the same page in two languages: the same element skeleton, the same style
 * block, the same symbols in the tool coverage matrix and the same literals in
 * every `<code>` element. Only the running text differs.
 */
final class OverviewParityTest extends TestCase
{
    private const MIN_MATRIX_ROWS = 10;
    private const MIN_CODE_ELEMENTS = 100;

    private OverviewPage $english;
    private OverviewPage $german;

    protected function setUp(): void
    {
        $docs = dirname(__DIR__, 3) . '/docs';

        $this->english = new OverviewPage($docs . '/overview.html');
        $this->german  = new OverviewPage($docs . '/overview.de.html');
    }

    public function testBodyHasTheSameElementSequenceOnBothPages(): void
    {
        self::assertSame($this->english->elementSequence(), $this->german->elementSequence());
    }

    public function testStyleBlockIsByteIdentical(): void
    {
        self::assertNotSame('', $this->english->styleBlock());
        self::assertSame($this->english->styleBlock(), $this->german->styleBlock());
    }

    public function testMatrixSymbolCellsAreCellForCellEqual(): void
    {
        $english = $this->english->matrixSymbolRows();

        self::assertGreaterThanOrEqual(self::MIN_MATRIX_ROWS, count($english));
        self::assertSame($english, $this->german->matrixSymbolRows());
    }

    public function testEveryCodeElementHasTheSameContentAtTheSamePlace(): void
    {
        $english = $this->english->codeContents();

        self::assertGreaterThan(self::MIN_CODE_ELEMENTS, count($english));
        self::assertSame($english, $this->german->codeContents());
    }
}
