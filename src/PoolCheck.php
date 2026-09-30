<?php

declare(strict_types=1);

namespace JardisTools\DevSkills;

use JardisTools\DevSkills\Data\PoolCheckResult;
use JardisTools\DevSkills\Exception\PoolNotFoundException;
use JardisTools\DevSkills\Handler\Pool\CheckIndexBudget;
use JardisTools\DevSkills\Handler\Pool\CheckLinks;
use JardisTools\DevSkills\Handler\Pool\CheckPageBudget;
use JardisTools\DevSkills\Handler\Pool\CheckPageStructure;
use JardisTools\DevSkills\Handler\Pool\CheckPathRefs;
use JardisTools\DevSkills\Handler\Pool\CheckPlanBudget;
use JardisTools\DevSkills\Handler\Pool\CheckProgressHead;
use JardisTools\DevSkills\Handler\Pool\CheckSupersession;
use JardisTools\DevSkills\Handler\Pool\LoadPool;
use JardisTools\DevSkills\Handler\Pool\LoadVorhaben;

/**
 * Orchestrator of the pool check: loads the pool and the work folders (`docs/vorhaben/`) of a project, runs the
 * rules over them and collects the violations. Read-only; needs no Composer class, so scripts/pool-check.php can
 * run it from its own loader.
 */
final class PoolCheck
{
    public function __construct(
        private readonly LoadPool $loadPool = new LoadPool(),
        private readonly CheckPageStructure $checkPageStructure = new CheckPageStructure(),
        private readonly CheckLinks $checkLinks = new CheckLinks(),
        private readonly CheckSupersession $checkSupersession = new CheckSupersession(),
        private readonly CheckPathRefs $checkPathRefs = new CheckPathRefs(),
        private readonly CheckPageBudget $checkPageBudget = new CheckPageBudget(),
        private readonly CheckIndexBudget $checkIndexBudget = new CheckIndexBudget(),
        private readonly LoadVorhaben $loadVorhaben = new LoadVorhaben(),
        private readonly CheckProgressHead $checkProgressHead = new CheckProgressHead(),
        private readonly CheckPlanBudget $checkPlanBudget = new CheckPlanBudget(),
    ) {
    }

    /**
     * @param bool $rootExplicit true when the caller named the project root, which lets the path references be checked
     * @throws PoolNotFoundException when the project has no pool folder
     */
    public function __invoke(string $root, bool $rootExplicit = false): PoolCheckResult
    {
        $pool     = ($this->loadPool)($root);
        $vorhaben = ($this->loadVorhaben)($root);

        return new PoolCheckResult(
            [
                ...($this->checkPageStructure)($pool['pages']),
                ...($this->checkLinks)($root, $pool['files']),
                ...($this->checkSupersession)($pool['files']),
                ...($this->checkPathRefs)($root, $rootExplicit, $pool['files']),
                ...($this->checkPageBudget)($pool['pages']),
                ...($this->checkIndexBudget)($pool['index']),
                ...($this->checkProgressHead)($vorhaben),
                ...($this->checkPlanBudget)($vorhaben),
            ],
            count($pool['pages']),
        );
    }
}
