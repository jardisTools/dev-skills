<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Uninstall;

use Closure;
use JardisTools\DevSkills\Data\AgentsMdAnalysis;
use JardisTools\DevSkills\Data\AgentsMdUninstallAction;
use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Exception\UninstallFailedException;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;

/**
 * Reverses the plugin's changes to AGENTS.md. Never through a link: when AGENTS.md is a link (out of the
 * project, dangling, or to a file inside it) or lies behind one, it is neither read, stripped, deleted nor
 * written; the action is SkippedLink and the caller warns (an old block may still stand behind the link).
 */
final class RemoveAggregatedAgentsMd
{
    /** @var Closure(string): AgentsMdAnalysis */
    private readonly Closure $analyze;

    /** @var Closure(string): bool */
    private readonly Closure $unlink;

    /** @var Closure(string, string): (int|false) */
    private readonly Closure $write;

    /**
     * @param Closure(string, string): bool $isPathBehindLink
     */
    public function __construct(
        private readonly Closure $isPathBehindLink,
        ?Closure $analyze = null,
        ?Closure $unlink = null,
        ?Closure $write = null,
    ) {
        $this->analyze = $analyze ?? (new AnalyzeAgentsMd())->__invoke(...);
        $this->unlink = $unlink ?? static fn (string $path): bool => @unlink($path);
        $this->write = $write ?? static fn (string $path, string $content): int|false
            => @file_put_contents($path, $content);
    }

    /**
     * Reverses the plugin's changes to <projectRoot>/AGENTS.md. User content
     * outside the managed block is always preserved. Corrupt marker states
     * are reported but the file is left untouched (defensive on uninstall). A link is skipped.
     */
    public function __invoke(string $projectRoot): AgentsMdUninstallAction
    {
        $target = $projectRoot . '/AGENTS.md';
        if (($this->isPathBehindLink)($projectRoot, $target)) {
            return AgentsMdUninstallAction::SkippedLink;
        }

        if (!is_file($target)) {
            return AgentsMdUninstallAction::Untouched;
        }

        try {
            $analysis = ($this->analyze)($target);
        } catch (InstallFailedException) {
            return AgentsMdUninstallAction::Corrupt;
        }

        if (!$analysis->hasManagedBlock) {
            return AgentsMdUninstallAction::Untouched;
        }

        if (trim($analysis->preBlock) === '' && trim($analysis->postBlock) === '') {
            if (!($this->unlink)($target)) {
                throw new UninstallFailedException(sprintf(
                    'Could not delete AGENTS.md at "%s".',
                    $target,
                ));
            }

            return AgentsMdUninstallAction::FileDeleted;
        }

        $remaining = rtrim($analysis->preBlock . $analysis->postBlock, "\n") . "\n";
        if (($this->write)($target, $remaining) === false) {
            throw new UninstallFailedException(sprintf(
                'Could not strip managed block from AGENTS.md at "%s".',
                $target,
            ));
        }

        return AgentsMdUninstallAction::BlockStripped;
    }
}
