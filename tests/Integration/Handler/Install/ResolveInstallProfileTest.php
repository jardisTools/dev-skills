<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\InstallProfile;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Handler\Install\ResolveInstallProfile;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class ResolveInstallProfileTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject('dev-skills-profile-');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testConfiguredCoreWinsOverEverything(): void
    {
        $this->project->writeFile('vendor/jardisadapter/cache/composer.json', '{}');

        $resolved = $this->resolve(PluginConfig::all()->withProfile(InstallProfile::Core, null), new Manifest(1, '1.6.0'));

        self::assertSame(InstallProfile::Core, $resolved->profile);
        self::assertSame($resolved->profile, $resolved->manifestProfile);
    }

    public function testConfiguredJardisWinsOverAnEmptyVendorTree(): void
    {
        $resolved = $this->resolve(PluginConfig::all()->withProfile(InstallProfile::Jardis, null));

        self::assertSame(InstallProfile::Jardis, $resolved->profile);
        self::assertSame($resolved->profile, $resolved->manifestProfile);
    }

    public function testManifestWithoutProfileKeepsEverythingAndIsNotRecorded(): void
    {
        $resolved = $this->resolve(PluginConfig::all(), new Manifest(1, '1.6.0'));

        self::assertSame(InstallProfile::Jardis, $resolved->profile);
        self::assertNull($resolved->manifestProfile, 'a kept installation stays "from before", detection never strips it');
    }

    public function testManifestWithProfileIsResolvedAnew(): void
    {
        $manifest = new Manifest(1, '1.7.0', [], [], InstallProfile::Jardis);

        self::assertSame(InstallProfile::Core, $this->resolve(PluginConfig::all(), $manifest)->profile);

        $this->project->writeFile('vendor/jardisadapter/cache/composer.json', '{}');
        $manifest = new Manifest(1, '1.7.0', [], [], InstallProfile::Core);

        self::assertSame(InstallProfile::Jardis, $this->resolve(PluginConfig::all(), $manifest)->profile);
    }

    public function testFreshProjectWithAForeignPackageIsCore(): void
    {
        $this->project->writeFile('vendor/acme/x/composer.json', '{}');

        $resolved = $this->resolve(PluginConfig::all());

        self::assertSame(InstallProfile::Core, $resolved->profile);
        self::assertSame($resolved->profile, $resolved->manifestProfile);
    }

    public function testFreshProjectWithAJardisPackageIsJardis(): void
    {
        $this->project->writeFile('vendor/jardisadapter/cache/composer.json', '{}');

        self::assertSame(InstallProfile::Jardis, $this->resolve(PluginConfig::all())->profile);
    }

    public function testFreshProjectWithAJardisCorePackageIsJardis(): void
    {
        $this->project->writeFile('vendor/jardiscore/kernel/composer.json', '{}');

        self::assertSame(InstallProfile::Jardis, $this->resolve(PluginConfig::all())->profile);
    }

    public function testFreshProjectWithOnlyThePluginItselfIsCore(): void
    {
        $this->project->writeFile('vendor/jardis/dev-skills/composer.json', '{}');

        self::assertSame(InstallProfile::Core, $this->resolve(PluginConfig::all())->profile);
    }

    public function testFreshProjectWithoutVendorDirectoryIsCore(): void
    {
        self::assertSame(InstallProfile::Core, $this->resolve(PluginConfig::all())->profile);
    }

    private function resolve(PluginConfig $config, ?Manifest $previous = null): \JardisTools\DevSkills\Data\ResolvedInstallProfile
    {
        return (new ResolveInstallProfile())($config, $previous, $this->project->path('vendor'));
    }
}
