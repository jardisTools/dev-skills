<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

final class UninstallReport
{
    /** @var list<string> */
    private array $removedSkills = [];

    private AgentsMdUninstallAction $agentsMdAction = AgentsMdUninstallAction::Untouched;

    public function addRemovedSkill(string $name): void
    {
        $this->removedSkills[] = $name;
    }

    public function setAgentsMdAction(AgentsMdUninstallAction $action): void
    {
        $this->agentsMdAction = $action;
    }

    /**
     * @return list<string>
     */
    public function removedSkills(): array
    {
        return $this->removedSkills;
    }

    public function removedSkillCount(): int
    {
        return count($this->removedSkills);
    }

    public function agentsMdAction(): AgentsMdUninstallAction
    {
        return $this->agentsMdAction;
    }
}
