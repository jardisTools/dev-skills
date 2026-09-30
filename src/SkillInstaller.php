<?php

declare(strict_types=1);

namespace JardisTools\DevSkills;

use Closure;
use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\AgentsDescriptor;
use JardisTools\DevSkills\Data\AggregateAgentsResult;
use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Discovery\ScanAgentsFiles;
use JardisTools\DevSkills\Handler\Install\AggregateAgentsMd;
use JardisTools\DevSkills\Handler\Install\IsCatalogInstalled;
use JardisTools\DevSkills\Handler\Install\RecordAgentsAggregation;

final class SkillInstaller
{
    private readonly InstallSkills $installSkills;

    /** @var Closure(string): list<AgentsDescriptor> */
    private readonly Closure $scanAgentsFiles;

    /** @var Closure(list<SkillDescriptor>): bool */
    private readonly Closure $isCatalogInstalled;

    /** @var Closure(list<AgentsDescriptor>, string, bool): AggregateAgentsResult */
    private readonly Closure $aggregateAgentsMd;

    /** @var Closure(InstallReport, AggregateAgentsResult): void */
    private readonly Closure $recordAgentsAggregation;

    public function __construct(
        ?PluginConfig $config = null,
        ?Filesystem $filesystem = null,
        ?string $pluginRoot = null,
    ) {
        $fs = $filesystem ?? new Filesystem();

        $this->installSkills = new InstallSkills(
            $config ?? PluginConfig::none(),
            $pluginRoot ?? dirname(__DIR__),
            $fs,
        );
        $this->scanAgentsFiles = (new ScanAgentsFiles())->__invoke(...);
        $this->isCatalogInstalled = (new IsCatalogInstalled())->__invoke(...);
        $this->aggregateAgentsMd = (new AggregateAgentsMd($fs))->__invoke(...);
        $this->recordAgentsAggregation = (new RecordAgentsAggregation())->__invoke(...);
    }

    public function __invoke(string $projectRoot, string $vendorDir, string $pluginVersion = '0.0.0'): InstallReport
    {
        $report = new InstallReport();

        $keptBundled = ($this->installSkills)($projectRoot, $vendorDir, $report, $pluginVersion);

        $result = ($this->aggregateAgentsMd)(
            ($this->scanAgentsFiles)($vendorDir),
            $projectRoot,
            ($this->isCatalogInstalled)($keptBundled),
        );
        ($this->recordAgentsAggregation)($report, $result);

        return $report;
    }
}
