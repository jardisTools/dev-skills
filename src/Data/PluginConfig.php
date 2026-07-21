<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

final readonly class PluginConfig
{
    /**
     * @param list<string> $includeGlobs
     * @param list<string> $excludeGlobs
     */
    public function __construct(
        public bool $installAll,
        public bool $installNone,
        public array $includeGlobs,
        public array $excludeGlobs,
        public ?string $warning,
    ) {
    }

    public static function none(): self
    {
        return new self(false, true, [], [], null);
    }

    /**
     * Default-on: the catalog skill plus the two consumer-lifecycle skills
     * (`jardis-start-here`, `jardis-mcp-consumer`) are installed even when the
     * user omits `bundled-skills` from their composer.json extra. All other
     * bundle skills stay off; the user can still opt out via
     * exclude: ['jardis-catalog', 'jardis-start-here', 'jardis-mcp-consumer'].
     */
    public static function defaultOn(): self
    {
        return new self(false, false, ['jardis-catalog', 'jardis-start-here', 'jardis-mcp-consumer'], [], null);
    }

    public static function all(): self
    {
        return new self(true, false, [], [], null);
    }

    /**
     * @param list<string> $include
     * @param list<string> $exclude
     */
    public static function filtered(array $include, array $exclude): self
    {
        return new self(false, false, $include, $exclude, null);
    }

    public static function invalid(string $reason): self
    {
        return new self(false, true, [], [], $reason);
    }
}
