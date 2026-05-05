<?php

declare(strict_types=1);

namespace JardisTools\DevSkills;

use Closure;
use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\AgentsMdUninstallAction;
use JardisTools\DevSkills\Data\UninstallReport;
use JardisTools\DevSkills\Handler\Uninstall\RemoveAggregatedAgentsMd;
use JardisTools\DevSkills\Handler\Uninstall\RemoveJardisSkills;

final class SkillUninstaller
{
    /** @var Closure(string): list<string> */
    private readonly Closure $removeJardisSkills;

    /** @var Closure(string): AgentsMdUninstallAction */
    private readonly Closure $removeAggregatedAgentsMd;

    public function __construct(?Filesystem $filesystem = null)
    {
        $fs = $filesystem ?? new Filesystem();

        $this->removeJardisSkills = (new RemoveJardisSkills($fs))->__invoke(...);
        $this->removeAggregatedAgentsMd = (new RemoveAggregatedAgentsMd())->__invoke(...);
    }

    public function __invoke(string $projectRoot): UninstallReport
    {
        $report = new UninstallReport();

        foreach (($this->removeJardisSkills)($projectRoot) as $name) {
            $report->addRemovedSkill($name);
        }

        $report->setAgentsMdAction(($this->removeAggregatedAgentsMd)($projectRoot));

        return $report;
    }
}
