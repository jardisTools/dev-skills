<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

final class InstallReport
{
    /** @var list<string> */
    private array $installedSkills = [];

    /** @var list<array{skill: string, backupPath: string}> */
    private array $backedUpSkills = [];

    private int $agentsFilesAggregated = 0;

    private ?string $agentsMdBackupPath = null;

    private bool $agentsMdHealed = false;

    private bool $agentsMdCreated = false;

    private ?InstallProfile $profile = null;

    /** @var list<string> */
    private array $removedBundledSkills = [];

    /** @var list<string> */
    private array $redirectedSkills = [];

    /** @var list<string> */
    private array $notices = [];

    /** @var list<string> */
    private array $warnings = [];

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    public function addWarningIfAny(?string $warning): void
    {
        if ($warning !== null && $warning !== '') {
            $this->warnings[] = $warning;
        }
    }

    public function addNoticeIfAny(?string $notice): void
    {
        if ($notice !== null && $notice !== '') {
            $this->notices[] = $notice;
        }
    }

    public function addRedirectedSkill(string $oldName): void
    {
        $this->redirectedSkills[] = $oldName;
    }

    public function addInstalledSkill(string $name): void
    {
        $this->installedSkills[] = $name;
    }

    public function addRemovedBundledSkill(string $name): void
    {
        $this->removedBundledSkills[] = $name;
    }

    public function addBackedUpSkill(string $name, string $backupPath): void
    {
        $this->backedUpSkills[] = ['skill' => $name, 'backupPath' => $backupPath];
    }

    public function addBackedUpSkillIfAny(string $name, ?string $backupPath): void
    {
        if ($backupPath !== null) {
            $this->addBackedUpSkill($name, $backupPath);
        }
    }

    public function setAgentsFilesAggregated(int $count): void
    {
        $this->agentsFilesAggregated = $count;
    }

    public function setAgentsMdBackupPath(?string $path): void
    {
        $this->agentsMdBackupPath = $path;
    }

    public function setAgentsMdHealed(bool $healed): void
    {
        $this->agentsMdHealed = $healed;
    }

    public function setAgentsMdCreated(bool $created): void
    {
        $this->agentsMdCreated = $created;
    }

    public function setProfile(InstallProfile $profile): void
    {
        $this->profile = $profile;
    }

    /**
     * The installation profile this run resolved; `null` before the skills part has run.
     */
    public function profile(): ?InstallProfile
    {
        return $this->profile;
    }

    public function installedSkillCount(): int
    {
        return count($this->installedSkills);
    }

    /**
     * @return list<string>
     */
    public function installedSkills(): array
    {
        return $this->installedSkills;
    }

    /**
     * @return list<array{skill: string, backupPath: string}>
     */
    public function backedUpSkills(): array
    {
        return $this->backedUpSkills;
    }

    public function agentsFilesAggregated(): int
    {
        return $this->agentsFilesAggregated;
    }

    public function agentsMdBackupPath(): ?string
    {
        return $this->agentsMdBackupPath;
    }

    public function agentsMdHealed(): bool
    {
        return $this->agentsMdHealed;
    }

    /**
     * True when this run wrote AGENTS.md although no such file was there before.
     */
    public function agentsMdCreated(): bool
    {
        return $this->agentsMdCreated;
    }

    /**
     * @return list<string>
     */
    public function removedBundledSkills(): array
    {
        return $this->removedBundledSkills;
    }

    /**
     * Old skill names that were left behind as redirect skills (not counted as installed skills).
     *
     * @return list<string>
     */
    public function redirectedSkills(): array
    {
        return $this->redirectedSkills;
    }

    /**
     * Informational messages for the user, e.g. the migration hint after an update.
     *
     * @return list<string>
     */
    public function notices(): array
    {
        return $this->notices;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
