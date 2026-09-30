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
