<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Shell;

use JardisTools\DevSkills\Data\ReviewerSource;

/**
 * The shared shape of the four Markdown shells: a frontmatter block with `name` and `description`
 * (the description as a double-quoted YAML string, so any text stays valid), then the body. No model
 * field is ever written: the shell inherits the model of the tool.
 */
final class RenderFrontmatterShell
{
    public function __invoke(ReviewerSource $source, string $body): string
    {
        return "---\n"
            . 'name: ' . $source->name . "\n"
            . 'description: ' . json_encode(
                $source->description,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n"
            . "---\n\n"
            . $body . "\n";
    }
}
