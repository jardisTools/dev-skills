<?php

declare(strict_types=1);

namespace JardisTools\DevSkills;

use Composer\Composer;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;
use JardisTools\DevSkills\Data\AgentsMdUninstallAction;
use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\UninstallReport;
use JardisTools\DevSkills\Handler\Discovery\ReadPluginConfig;

final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const SELF_PACKAGE_NAME = 'jardis/dev-skills';

    private ?Composer $composer = null;
    private ?IOInterface $io = null;
    private ?SkillInstaller $installer = null;
    private ?SkillUninstaller $uninstaller = null;
    private ?PluginConfig $config = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;

        $extra = $composer->getPackage()->getExtra();
        $this->config = (new ReadPluginConfig())($extra);

        $this->installer = new SkillInstaller(config: $this->config);
        $this->uninstaller = new SkillUninstaller();
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * ScriptEvents run once per `composer install` / `composer update` command,
     * which is the only reliable trigger on first install (when jardis/* packages
     * are already unpacked before this plugin activates and therefore miss any
     * POST_PACKAGE_INSTALL event for themselves).
     *
     * PRE_PACKAGE_UNINSTALL fires per-package; we act only when the package
     * being removed is jardis/dev-skills itself.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onComposerRun',
            ScriptEvents::POST_UPDATE_CMD => 'onComposerRun',
            PackageEvents::PRE_PACKAGE_UNINSTALL => 'onPackageUninstall',
        ];
    }

    public function onComposerRun(ScriptEvent $event): void
    {
        if ($this->installer === null || $this->composer === null || $this->io === null) {
            return;
        }

        $projectRoot = (string) getcwd();
        $vendorDir = (string) $this->composer->getConfig()->get('vendor-dir');

        if ($this->config !== null && $this->config->warning !== null) {
            $this->io->writeError(sprintf(
                '<warning>jardis/dev-skills: %s</warning>',
                $this->config->warning,
            ));
        }

        $report = ($this->installer)($projectRoot, $vendorDir);

        $this->io->write($this->summarizeInstall($report));
        foreach ($report->removedBundledSkills() as $removed) {
            $this->io->writeError(sprintf(
                '<warning>jardis/dev-skills: bundled skill "%s" removed (no longer selected by config)</warning>',
                $removed,
            ));
        }
        foreach ($report->backedUpSkills() as $backup) {
            $this->io->writeError(sprintf(
                '<warning>jardis/dev-skills: existing skill "%s" moved to %s</warning>',
                $backup['skill'],
                $backup['backupPath'],
            ));
        }
        if ($report->agentsMdBackupPath() !== null) {
            $this->io->writeError(sprintf(
                '<warning>jardis/dev-skills: existing AGENTS.md moved to %s</warning>',
                $report->agentsMdBackupPath(),
            ));
        }
    }

    public function onPackageUninstall(PackageEvent $event): void
    {
        if ($this->uninstaller === null || $this->io === null) {
            return;
        }

        $operation = $event->getOperation();
        if (!$operation instanceof UninstallOperation) {
            return;
        }
        if ($operation->getPackage()->getName() !== self::SELF_PACKAGE_NAME) {
            return;
        }

        $report = ($this->uninstaller)((string) getcwd());

        $this->io->write($this->summarizeUninstall($report));
        if ($report->agentsMdAction() === AgentsMdUninstallAction::Corrupt) {
            $this->io->writeError(
                '<warning>jardis/dev-skills: AGENTS.md has corrupt markers; left untouched. Fix manually.</warning>',
            );
        }
    }

    private function summarizeInstall(InstallReport $report): string
    {
        return sprintf(
            '<info>Jardis Skills installed: %d skills, %d AGENTS.md aggregated.</info>'
            . ' See https://docs.jardis.io/en/skills',
            $report->installedSkillCount(),
            $report->agentsFilesAggregated(),
        );
    }

    private function summarizeUninstall(UninstallReport $report): string
    {
        $agents = match ($report->agentsMdAction()) {
            AgentsMdUninstallAction::FileDeleted => 'removed',
            AgentsMdUninstallAction::BlockStripped => 'block stripped (user content kept)',
            AgentsMdUninstallAction::Untouched => 'kept',
            AgentsMdUninstallAction::Corrupt => 'kept (corrupt markers)',
        };

        return sprintf(
            '<info>Jardis Skills removed: %d skills, AGENTS.md %s.</info>',
            $report->removedSkillCount(),
            $agents,
        );
    }
}
