<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Support;

use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Data\ProcessDocsMode;
use JardisTools\DevSkills\Handler\Install\BuildClaudeMdContent;
use JardisTools\DevSkills\Handler\Install\BuildExcludeLines;
use JardisTools\DevSkills\Handler\Install\BuildJsonMemberInsertion;
use JardisTools\DevSkills\Handler\Install\EnsureClaudeMdImport;
use JardisTools\DevSkills\Handler\Install\EnsureGeminiContext;
use JardisTools\DevSkills\Handler\Install\HasAgentsImport;
use JardisTools\DevSkills\Handler\Install\ListTrackedPaths;
use JardisTools\DevSkills\Handler\Install\PlanGeminiContextEdit;
use JardisTools\DevSkills\Handler\Install\ReplaceExcludeBlock;
use JardisTools\DevSkills\Handler\Install\ResolveGitDir;
use JardisTools\DevSkills\Handler\Install\SyncExcludeBlock;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\Handler\Manifest\RecordSelfSetEntry;
use JardisTools\DevSkills\Handler\Manifest\WriteManifest;
use JardisTools\DevSkills\Handler\Shell\BuildShellBody;
use JardisTools\DevSkills\Handler\Shell\EncodeTomlBasicString;
use JardisTools\DevSkills\Handler\Shell\EncodeTomlMultiline;
use JardisTools\DevSkills\Handler\Shell\ParseReviewerSource;
use JardisTools\DevSkills\Handler\Shell\RenderClaudeShell;
use JardisTools\DevSkills\Handler\Shell\RenderCodexShell;
use JardisTools\DevSkills\Handler\Shell\RenderCopilotShell;
use JardisTools\DevSkills\Handler\Shell\RenderCursorShell;
use JardisTools\DevSkills\Handler\Shell\RenderFrontmatterShell;
use JardisTools\DevSkills\Handler\Shell\RenderGeminiShell;
use JardisTools\DevSkills\Handler\Shell\RenderShell;
use JardisTools\DevSkills\Handler\Shell\WriteReviewerShells;
use JardisTools\DevSkills\Handler\Support\DetectLineEnding;
use JardisTools\DevSkills\Handler\Support\IsLinkLeavingProject;
use JardisTools\DevSkills\Handler\Support\RunGit;
use JardisTools\DevSkills\Handler\Support\ScanJsonArray;
use JardisTools\DevSkills\Handler\Support\ScanJsonObject;
use JardisTools\DevSkills\Handler\Support\SkipJsonValue;
use JardisTools\DevSkills\Handler\Uninstall\IsEmptyGeminiScaffold;
use JardisTools\DevSkills\Handler\Uninstall\RemoveClaudeMdImport;
use JardisTools\DevSkills\Handler\Uninstall\RemoveExcludeBlock;
use JardisTools\DevSkills\Handler\Uninstall\RemoveGeminiContext;
use JardisTools\DevSkills\Handler\Uninstall\RemoveReviewerShells;
use JardisTools\DevSkills\Handler\Validate\ParseSkillFrontmatter;
use JardisTools\DevSkills\Handler\Uninstall\ReverseTextEdit;
use JardisTools\DevSkills\Handler\Uninstall\StripClaudeMdImport;

/**
 * Wires the CLAUDE.md and Gemini add-on handlers the way the installer does, for handler tests.
 */
final class AddonFactory
{
    public static function ensureClaudeMd(): EnsureClaudeMdImport
    {
        return new EnsureClaudeMdImport(
            (new AnalyzeAgentsMd())->__invoke(...),
            (new HasAgentsImport())->__invoke(...),
            (new DetectLineEnding())->__invoke(...),
            (new BuildClaudeMdContent())->__invoke(...),
            self::recordSelfSet(),
            (new IsLinkLeavingProject())->__invoke(...),
        );
    }

    public static function ensureGemini(): EnsureGeminiContext
    {
        $skipValue = (new SkipJsonValue())->__invoke(...);

        return new EnsureGeminiContext(
            (new PlanGeminiContextEdit(
                (new DetectLineEnding())->__invoke(...),
                (new ScanJsonObject($skipValue))->__invoke(...),
                (new ScanJsonArray($skipValue))->__invoke(...),
                (new BuildJsonMemberInsertion())->__invoke(...),
            ))->__invoke(...),
            self::recordSelfSet(),
            (new IsLinkLeavingProject())->__invoke(...),
        );
    }

    public static function removeClaudeMd(): RemoveClaudeMdImport
    {
        return new RemoveClaudeMdImport(
            (new AnalyzeAgentsMd())->__invoke(...),
            (new DetectLineEnding())->__invoke(...),
            (new StripClaudeMdImport())->__invoke(...),
            (new IsLinkLeavingProject())->__invoke(...),
        );
    }

    public static function removeGemini(): RemoveGeminiContext
    {
        return new RemoveGeminiContext(
            (new ReverseTextEdit())->__invoke(...),
            (new IsEmptyGeminiScaffold())->__invoke(...),
            (new IsLinkLeavingProject())->__invoke(...),
        );
    }

    public static function parseReviewerSource(): ParseReviewerSource
    {
        return new ParseReviewerSource((new ParseSkillFrontmatter())->__invoke(...));
    }

    public static function renderShell(): RenderShell
    {
        $encodeBasicString = (new EncodeTomlBasicString())->__invoke(...);
        $buildBody = (new BuildShellBody())->__invoke(...);
        $frontmatter = (new RenderFrontmatterShell())->__invoke(...);

        return new RenderShell(
            (new RenderClaudeShell($frontmatter, $buildBody))->__invoke(...),
            (new RenderCodexShell(
                $encodeBasicString,
                (new EncodeTomlMultiline($encodeBasicString))->__invoke(...),
                $buildBody,
            ))->__invoke(...),
            (new RenderCursorShell($frontmatter, $buildBody))->__invoke(...),
            (new RenderCopilotShell($frontmatter, $buildBody))->__invoke(...),
            (new RenderGeminiShell($frontmatter, $buildBody))->__invoke(...),
        );
    }

    public static function writeReviewerShells(string $pluginRoot): WriteReviewerShells
    {
        return new WriteReviewerShells(
            $pluginRoot,
            self::parseReviewerSource()->__invoke(...),
            self::renderShell()->__invoke(...),
            (new ReadManifest())->__invoke(...),
            self::recordSelfSet(),
        );
    }

    public static function removeReviewerShells(): RemoveReviewerShells
    {
        return new RemoveReviewerShells();
    }

    public static function syncExcludeBlock(ProcessDocsMode $mode): SyncExcludeBlock
    {
        $runGit = (new RunGit())->__invoke(...);

        return new SyncExcludeBlock(
            $mode,
            (new ResolveGitDir($runGit))->__invoke(...),
            (new ReadManifest())->__invoke(...),
            (new BuildExcludeLines())->__invoke(...),
            self::replaceExcludeBlock(),
            (new ListTrackedPaths($runGit))->__invoke(...),
        );
    }

    public static function removeExcludeBlock(): RemoveExcludeBlock
    {
        return new RemoveExcludeBlock(
            (new ResolveGitDir((new RunGit())->__invoke(...)))->__invoke(...),
            self::replaceExcludeBlock(),
        );
    }

    /**
     * @return \Closure(string, ?list<string>): string
     */
    private static function replaceExcludeBlock(): \Closure
    {
        return (new ReplaceExcludeBlock((new DetectLineEnding())->__invoke(...)))->__invoke(...);
    }

    /**
     * @return \Closure(string, string, \JardisTools\DevSkills\Data\SelfSetEntry): void
     */
    private static function recordSelfSet(): \Closure
    {
        return (new RecordSelfSetEntry(
            (new ReadManifest())->__invoke(...),
            (new WriteManifest())->__invoke(...),
        ))->__invoke(...);
    }
}
