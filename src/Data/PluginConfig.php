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
        public GitRulesMode $gitRules = GitRulesMode::Strict,
        public ?string $gitRulesWarning = null,
        public ?InstallProfile $profile = null,
        public ?string $profileWarning = null,
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
            $this->profile,
            $this->profileWarning,
        );
    }

    /**
     * The same configuration with the `git-rules` stance (`Strict` = the router states the gates of the human,
     * `Delegated` = the session commits itself, `Off` = no git rules in the router) and the warning about
     * its raw value.
     */
    public function withGitRules(GitRulesMode $gitRules, ?string $warning): self
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
            $this->profile,
            $this->profileWarning,
        );
    }

    /**
     * The same configuration with the explicitly configured installation profile (`null` = not set, the
     * profile is resolved from the project) and the warning about its raw value.
     */
    public function withProfile(?InstallProfile $profile, ?string $warning): self
    {
        return new self(
            $this->installAll,
            $this->mandatoryOnly,
            $this->includeGlobs,
            $this->excludeGlobs,
            $this->warning,
            $this->processDocs,
            $this->processDocsWarning,
            $this->gitRules,
            $this->gitRulesWarning,
            $profile,
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
