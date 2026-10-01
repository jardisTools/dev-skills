<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Docs;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * One overview page of `docs/` loaded for inspection: the raw HTML, the parsed
 * document and the read-only views the page tests compare (skill names, tool
 * coverage matrix, element skeleton, `<code>` literals, style block).
 */
final class OverviewPage
{
    private const CLASS_TEST = "contains(concat(' ', normalize-space(@class), ' '), ' %s ')";

    public readonly string $html;
    public readonly DOMXPath $xpath;

    public function __construct(string $file)
    {
        $html = file_get_contents($file);
        if ($html === false) {
            throw new RuntimeException('Could not read ' . $file);
        }

        $this->html = $html;

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->xpath = new DOMXPath($document);
    }

    /**
     * The sorted names on the skill map (`.sk .n`).
     *
     * @return list<string>
     */
    public function skillMapNames(): array
    {
        $query = '//*[' . sprintf(self::CLASS_TEST, 'sk') . ']//*[' . sprintf(self::CLASS_TEST, 'n') . ']';

        $names = [];
        foreach ($this->xpath->query($query) ?: [] as $node) {
            $names[] = trim($node->textContent);
        }
        sort($names);

        return $names;
    }

    /**
     * The header cells of the tool coverage matrix.
     *
     * @return list<string>
     */
    public function matrixHeaders(): array
    {
        $headers = [];
        foreach ($this->xpath->query($this->matrix() . '//tr[1]/th') ?: [] as $cell) {
            $headers[] = trim($cell->textContent);
        }

        return $headers;
    }

    /**
     * The five tool cells of every row of the matrix except header and guarantee row.
     *
     * @return list<list<string>>
     */
    public function matrixSymbolRows(): array
    {
        $rows = $this->xpath->query($this->matrix() . "//tr[not(contains(@class, 'guar'))][position() > 1]");

        $symbols = [];
        foreach ($rows ?: [] as $row) {
            $cells = [];
            foreach ($this->xpath->query('td[position() >= 2 and position() <= 6]', $row) ?: [] as $cell) {
                $cells[] = trim($cell->textContent);
            }
            $symbols[] = $cells;
        }

        return $symbols;
    }

    /**
     * The text of the legend under the matrix (`p.src` of section `s5`).
     */
    public function matrixLegend(): string
    {
        $legend = $this->xpath->query("//section[@id='s5']//p[contains(@class, 'src')]")?->item(0);

        return $legend === null ? '' : $legend->textContent;
    }

    /**
     * The text of the guarantee row of the matrix.
     */
    public function guaranteeRow(): string
    {
        $row = $this->xpath->query("//section[@id='s5']//tr[contains(@class, 'guar')]")?->item(0);

        return $row === null ? '' : $row->textContent;
    }

    /**
     * Element name, class and id of every element in the body, in document order.
     *
     * @return list<string>
     */
    public function elementSequence(): array
    {
        $sequence = [];
        foreach ($this->xpath->query('//body//*') ?: [] as $element) {
            if ($element instanceof DOMElement) {
                $sequence[] = $element->nodeName . '|' . $element->getAttribute('class') . '|' . $element->getAttribute('id');
            }
        }

        return $sequence;
    }

    /**
     * The content of every `<code>` element in the body, in document order.
     *
     * @return list<string>
     */
    public function codeContents(): array
    {
        $contents = [];
        foreach ($this->xpath->query('//body//code') ?: [] as $code) {
            $contents[] = $code->textContent;
        }

        return $contents;
    }

    /**
     * The `<style>` block with its tags, byte for byte.
     */
    public function styleBlock(): string
    {
        return preg_match('~<style>.*?</style>~s', $this->html, $match) === 1 ? $match[0] : '';
    }

    private function matrix(): string
    {
        return "//section[@id='s5']//table[" . sprintf(self::CLASS_TEST, 'mx') . ']';
    }
}
