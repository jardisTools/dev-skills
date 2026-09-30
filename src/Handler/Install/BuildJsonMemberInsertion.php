<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Data\TextEdit;

/**
 * Builds the text insertion that adds one member to a JSON object without touching
 * any other character. The member goes right behind the opening brace and takes
 * over the layout of the object: line break and indentation of a multi-line object,
 * inline otherwise, with the file's own line ending.
 */
final class BuildJsonMemberInsertion
{
    private const INDENT_STEP = '  ';

    /**
     * @param int    $open        offset of the object's opening brace
     * @param int    $memberCount number of members the object has now
     * @param string $member      the member as JSON text, e.g. `"context": {}`
     */
    public function __invoke(string $json, int $open, int $memberCount, string $member, string $eol): TextEdit
    {
        $comma = $memberCount > 0 ? ',' : '';

        if (preg_match('/\G[ \t]*\r?\n([ \t]*)/', $json, $layout, 0, $open + 1) !== 1) {
            return new TextEdit($open + 1, 0, $member . $comma);
        }

        $indent = $memberCount > 0 ? $layout[1] : $this->lineIndent($json, $open) . self::INDENT_STEP;

        return new TextEdit($open + 1, 0, $eol . $indent . $member . $comma);
    }

    private function lineIndent(string $json, int $offset): string
    {
        $lineStart = strrpos(substr($json, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;

        return substr($json, $lineStart, strspn($json, " \t", $lineStart));
    }
}
