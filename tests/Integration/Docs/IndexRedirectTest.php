<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Docs;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * The landing page `docs/index.html` only forwards to `docs/overview.html`:
 * a meta refresh to an existing sibling file, a visible link, and no script.
 */
final class IndexRedirectTest extends TestCase
{
    private string $docs;
    private string $html;
    private DOMXPath $xpath;

    protected function setUp(): void
    {
        $this->docs = dirname(__DIR__, 3) . '/docs';
        self::assertFileExists($this->docs . '/index.html');
        $this->html = (string) file_get_contents($this->docs . '/index.html');

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>' . $this->html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->xpath = new DOMXPath($document);
    }

    public function testIndexRefreshesToOverview(): void
    {
        $refresh = $this->xpath->query("//meta[@http-equiv='refresh']");

        self::assertSame(1, $refresh->length);
        self::assertSame('0; url=overview.html', $refresh->item(0)?->attributes?->getNamedItem('content')?->nodeValue);
    }

    public function testRedirectTargetExists(): void
    {
        self::assertFileExists($this->docs . '/overview.html');
    }

    public function testIndexOffersVisibleRelativeLinkToOverview(): void
    {
        $link = $this->xpath->query("//body//a[@href='overview.html']");

        self::assertGreaterThanOrEqual(1, $link->length);
        self::assertNotSame('', trim($link->item(0)?->textContent ?? ''));
    }

    public function testIndexIsEnglishWithTitleAndWithoutScript(): void
    {
        self::assertSame(1, $this->xpath->query("//html[@lang='en']")->length);
        self::assertNotSame('', trim($this->xpath->query('//title')->item(0)?->textContent ?? ''));
        self::assertSame(0, $this->xpath->query('//script')->length);
        self::assertStringNotContainsStringIgnoringCase('<script', $this->html);
    }
}
