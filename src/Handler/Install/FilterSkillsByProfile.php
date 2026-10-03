<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\InstallProfile;
use JardisTools\DevSkills\Data\SkillDescriptor;

/**
 * Drops the bundle skills of the `jardis` profile when the profile is `core`. A skill whose SKILL.md
 * declares `profile: jardis` is dropped; one that declares `core`, nothing valid or cannot be read
 * stays, so a defect never removes a skill. The `jardis` profile keeps everything.
 */
final class FilterSkillsByProfile
{
    /**
     * @param Closure(string): ?array{fields: array<string, string|list<string>>, body: string} $parseFrontmatter
     */
    public function __construct(
        private readonly Closure $parseFrontmatter,
    ) {
    }

    /**
     * @param list<SkillDescriptor> $bundled
     * @return list<SkillDescriptor>
     */
    public function __invoke(array $bundled, InstallProfile $profile): array
    {
        if ($profile === InstallProfile::Jardis) {
            return $bundled;
        }

        return array_values(array_filter(
            $bundled,
            fn (SkillDescriptor $skill): bool => $this->declaredProfile($skill) !== InstallProfile::Jardis->value,
        ));
    }

    private function declaredProfile(SkillDescriptor $skill): ?string
    {
        $content = @file_get_contents($skill->sourceDir . '/SKILL.md');
        $parsed = $content === false ? null : ($this->parseFrontmatter)($content);
        $profile = $parsed['fields']['profile'] ?? null;

        return is_string($profile) ? $profile : null;
    }
}
