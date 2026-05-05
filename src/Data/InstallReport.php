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

    /** @var list<string> */
    private array $removedBundledSkills = [];

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

    public function setAgentsFilesAggregated(int $count): void
    {
        $this->agentsFilesAggregated = $count;
    }

    public function setAgentsMdBackupPath(string $path): void
    {
        $this->agentsMdBackupPath = $path;
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

    /**
     * @return list<string>
     */
    public function removedBundledSkills(): array
    {
        return $this->removedBundledSkills;
    }
}
