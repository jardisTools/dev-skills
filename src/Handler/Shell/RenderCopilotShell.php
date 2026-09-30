<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Shell;

use Closure;
use JardisTools\DevSkills\Data\ReviewerSource;

/**
 * The reviewer shell for GitHub Copilot: frontmatter head (`name`, `description`), then the pointer to the source.
 */
final class RenderCopilotShell
{
    /**
     * @param Closure(ReviewerSource, string): string $renderFrontmatter
     * @param Closure(ReviewerSource): string         $buildBody
     */
    public function __construct(
        private readonly Closure $renderFrontmatter,
        private readonly Closure $buildBody,
    ) {
    }

    public function __invoke(ReviewerSource $source): string
    {
        return ($this->renderFrontmatter)($source, ($this->buildBody)($source));
    }
}
