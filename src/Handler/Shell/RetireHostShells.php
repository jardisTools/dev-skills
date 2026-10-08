<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Shell;

use Closure;
use JardisTools\DevSkills\Data\Host;
use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestState;
use JardisTools\DevSkills\Data\ShellFormat;
use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Handler\Manifest\ResolvePluginVersion;

/**
 * Add-on of an install run: the config is the source of truth for `hosts`, so the reviewer shells of a
 * host that is not listed (any more) are taken out — and only those the manifest lists as shells of the
 * plugin. A foreign file in the same folder stays, a link or a path behind one is left alone, and a
 * folder that is empty afterwards goes. The removed shells are forgotten in the manifest, so a later run
 * does not look for them again. Without a manifest note nothing happens.
 */
final class RetireHostShells
{
    /**
     * @param Closure(string, string): \JardisTools\DevSkills\Data\ManifestReadResult $readManifest
     * @param Closure(string, list<string>): void $forgetSelfSetEntries
     * @param Closure(string, string): bool $isPathBehindLink
     * @param list<Host> $hosts the hosts that keep their shells
     */
    public function __construct(
        private readonly Closure $readManifest,
        private readonly Closure $forgetSelfSetEntries,
        private readonly Closure $isPathBehindLink,
        private readonly array $hosts = [Host::Claude],
    ) {
    }

    public function __invoke(string $projectRoot, string $vendorDir, InstallReport $report): void
    {
        $read = ($this->readManifest)($projectRoot . '/' . Manifest::FILE, ResolvePluginVersion::DEV_VERSION);
        if ($read->state !== ManifestState::Healthy) {
            return;
        }
        $realRoot = realpath($projectRoot);
        if ($realRoot === false) {
            return;
        }

        $kept = array_map(static fn (Host $host): ShellFormat => $host->shellFormat(), $this->hosts);
        $forget = [];
        foreach ($read->manifest->selfSet ?? [] as $path => $entry) {
            $path = (string) $path;
            $format = ShellFormat::fromPath($path);
            if (!$entry->fileCreated || $format === null || in_array($format, $kept, true)) {
                continue;
            }

            $target = $realRoot . '/' . $path;
            $real = realpath($target);
            if (
                ($this->isPathBehindLink)($realRoot, $target)
                || !is_file($target)
                || $real === false
                || !str_starts_with($real, $realRoot . '/')
            ) {
                continue;
            }
            if (!@unlink($target)) {
                throw new InstallFailedException(sprintf('Could not delete the reviewer shell "%s".', $target));
            }
            $forget[] = $path;

            $agents = dirname($target);
            if (@rmdir($agents)) {
                @rmdir(dirname($agents));
            }
        }

        if ($forget !== []) {
            ($this->forgetSelfSetEntries)($projectRoot, $forget);
        }
    }
}
