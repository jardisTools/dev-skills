<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

final readonly class PluginConfig
{
    /** Skill groups that are always installed, whatever `bundled-skills` says. */
    public const MANDATORY_GLOBS = ['foundation-*', 'process-*'];

    public const MANDATORY_NOTICE = 'mandatory groups foundation-*/process-* are always installed';

    /**
     * @param list<string> $includeGlobs
     * @param list<string> $excludeGlobs
     */
    public function __construct(
        public bool $installAll,
        public bool $mandatoryOnly,
        public array $includeGlobs,
        public array $excludeGlobs,
        public ?string $warning,
        public ProcessDocsMode $processDocs = ProcessDocsMode::Committed,
        public ?string $processDocsWarning = null,
        public bool $gitRules = true,
        public ?string $gitRulesWarning = null,
    ) {
    }

    /**
     * The same configuration with the `process-docs` mode and the warning about its raw value.
     */
    public function withProcessDocs(ProcessDocsMode $mode, ?string $warning): self
    {
        return new self(
            $this->installAll,
            $this->mandatoryOnly,
            $this->includeGlobs,
            $this->excludeGlobs,
            $this->warning,
            $mode,
            $warning,
            $this->gitRules,
            $this->gitRulesWarning,
        );
    }

    /**
     * The same configuration with the `git-rules` switch (`true` = the router states the git rules)
     * and the warning about its raw value.
     */
    public function withGitRules(bool $gitRules, ?string $warning): self
    {
        return new self(
            $this->installAll,
            $this->mandatoryOnly,
            $this->includeGlobs,
            $this->excludeGlobs,
            $this->warning,
            $this->processDocs,
            $this->processDocsWarning,
            $gitRules,
            $warning,
        );
    }

    /**
     * Only the mandatory groups; used for `false`, `[]` and invalid values.
     */
    public static function onlyMandatory(?string $warning = null): self
    {
        return new self(false, true, [], [], $warning);
    }

    public static function all(): self
    {
        return new self(true, false, [], [], null);
    }

    /**
     * @param list<string> $include
     * @param list<string> $exclude
     */
    public static function filtered(array $include, array $exclude): self
    {
        return new self(false, false, $include, $exclude, null);
    }

    public static function invalid(string $reason): self
    {
        return self::onlyMandatory(sprintf('%s Treated as bundled-skills=false: %s.', $reason, self::MANDATORY_NOTICE));
    }
}
