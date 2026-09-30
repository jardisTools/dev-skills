<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Pool;

use JardisTools\DevSkills\Data\PoolPage;

/**
 * Reads one pool file and collects what the rules look at: size, line count, the `ersetzt` list of the
 * frontmatter, every level-2 heading, `[[wiki]]` links, `[text](target)` links and inline code spans.
 * Frontmatter and fenced code blocks are skipped for everything but the size; a code span never counts
 * as a link.
 */
final class ParsePage
{
    private const CODE_SPAN = '/`([^`\n]+)`/';

    public function __invoke(string $absolutePath, string $displayPath): PoolPage
    {
        $content = (string) file_get_contents($absolutePath);
        $lines   = preg_split('/\r\n|\n|\r/', $content);
        $lines   = $lines === false ? [] : $lines;
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $bodyStart = $this->frontmatterEnd($lines);
        $headings  = [];
        $wikiLinks = [];
        $mdLinks   = [];
        $codeSpans = [];
        $fenced    = false;

        for ($i = $bodyStart; $i < count($lines); $i++) {
            $line = $lines[$i];
            $no   = $i + 1;
            if (preg_match('/^ {0,3}(```|~~~)/', $line) === 1) {
                $fenced = !$fenced;
                continue;
            }
            if ($fenced) {
                continue;
            }

            if (preg_match('/^##[ \t]+(\S.*?)[ \t]*$/', $line, $heading) === 1) {
                $headings[] = ['line' => $no, 'title' => $heading[1]];
            }
            if (preg_match_all(self::CODE_SPAN, $line, $spans) > 0) {
                foreach ($spans[1] as $span) {
                    $codeSpans[] = ['line' => $no, 'target' => trim($span)];
                }
            }

            $prose = (string) preg_replace(self::CODE_SPAN, ' ', $line);
            if (preg_match_all('/\[\[([^\[\]]+)\]\]/', $prose, $wikis) > 0) {
                foreach ($wikis[1] as $wiki) {
                    $target = trim(explode('#', explode('|', $wiki)[0])[0]);
                    if ($target !== '') {
                        $wikiLinks[] = ['line' => $no, 'target' => $target];
                    }
                }
            }
            if (preg_match_all('/\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $prose, $links) > 0) {
                foreach ($links[1] as $link) {
                    $mdLinks[] = ['line' => $no, 'target' => $link];
                }
            }
        }

        return new PoolPage(
            $displayPath,
            pathinfo($displayPath, PATHINFO_FILENAME),
            strlen($content),
            count($lines),
            $this->ersetzt($lines, $bodyStart),
            $headings,
            $wikiLinks,
            $mdLinks,
            $codeSpans,
        );
    }

    /**
     * Index of the first body line; 0 when the file has no closed frontmatter block.
     *
     * @param list<string> $lines
     */
    private function frontmatterEnd(array $lines): int
    {
        if (($lines[0] ?? '') !== '---') {
            return 0;
        }
        for ($i = 1; $i < count($lines); $i++) {
            if (trim($lines[$i]) === '---') {
                return $i + 1;
            }
        }

        return 0;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function ersetzt(array $lines, int $bodyStart): array
    {
        $ids = [];
        for ($i = 1; $i < $bodyStart - 1; $i++) {
            if (preg_match('/^ersetzt\s*:\s*(.*)$/', $lines[$i], $match) !== 1) {
                continue;
            }
            $value = trim($match[1]);
            if ($value !== '') {
                if (preg_match('/^\[(.*)\]$/', $value, $list) === 1) {
                    foreach (explode(',', $list[1]) as $item) {
                        $ids[] = trim($item, " \t\"'");
                    }
                }
                break;
            }
            for ($j = $i + 1; $j < $bodyStart - 1 && preg_match('/^\s*-\s+(.+)$/', $lines[$j], $item) === 1; $j++) {
                $ids[] = trim($item[1], " \t\"'");
            }
            break;
        }

        return array_values(array_filter($ids, static fn (string $id): bool => $id !== ''));
    }
}
