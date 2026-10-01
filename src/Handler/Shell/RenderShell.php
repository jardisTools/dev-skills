<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Shell;

use Closure;
use JardisTools\DevSkills\Data\ReviewerSource;
use JardisTools\DevSkills\Data\ShellFormat;

/**
 * The one place that branches on the shell format: hands the source to the renderer of that format.
 */
final class RenderShell
{
    /**
     * @param Closure(ReviewerSource): string $renderClaude
     * @param Closure(ReviewerSource): string $renderCodex
     * @param Closure(ReviewerSource): string $renderCursor
     * @param Closure(ReviewerSource): string $renderCopilot
     * @param Closure(ReviewerSource): string $renderGemini
     */
    public function __construct(
        private readonly Closure $renderClaude,
        private readonly Closure $renderCodex,
        private readonly Closure $renderCursor,
        private readonly Closure $renderCopilot,
        private readonly Closure $renderGemini,
    ) {
    }

    public function __invoke(ReviewerSource $source, ShellFormat $format): string
    {
        return match ($format) {
            ShellFormat::Claude => ($this->renderClaude)($source),
            ShellFormat::Codex => ($this->renderCodex)($source),
            ShellFormat::Cursor => ($this->renderCursor)($source),
            ShellFormat::Copilot => ($this->renderCopilot)($source),
            ShellFormat::Gemini => ($this->renderGemini)($source),
        };
    }
}
