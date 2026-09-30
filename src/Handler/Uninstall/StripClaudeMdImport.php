<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Uninstall;

/**
 * The CLAUDE.md text without the plugin's import block: what stood before and after the
 * block, minus the line break behind the block and the blank line the install put in front
 * of it. Everything else is byte for byte what the user had.
 */
final class StripClaudeMdImport
{
    public function __invoke(string $preBlock, string $postBlock, string $eol): string
    {
        if (str_starts_with($postBlock, $eol)) {
            $postBlock = substr($postBlock, strlen($eol));
        }
        if (str_ends_with($preBlock, $eol . $eol)) {
            $preBlock = substr($preBlock, 0, -strlen($eol));
        }

        return $preBlock . $postBlock;
    }
}
