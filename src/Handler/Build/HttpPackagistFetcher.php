<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Build;

/**
 * Native-PHP implementation of the PackagistFetcher port.
 *
 * Uses file_get_contents with a configurable timeout via a stream context.
 * All errors (network, HTTP, JSON decode, unexpected shape) are silently
 * converted to null — no exception ever escapes this class.
 */
final class HttpPackagistFetcher implements PackagistFetcher
{
    private const BASE_URL = 'https://packagist.org/packages/list.json';

    public function __construct(private readonly int $timeoutSeconds = 5)
    {
    }

    /**
     * @return list<string>|null
     */
    public function fetchPackages(string $vendor): ?array
    {
        $url     = self::BASE_URL . '?vendor=' . rawurlencode($vendor);
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => $this->timeoutSeconds,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false || $response === '') {
            return null;
        }

        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $packageNames = $decoded['packageNames'] ?? null;

        if (!is_array($packageNames)) {
            return null;
        }

        /** @var list<string> $result */
        $result = [];

        foreach ($packageNames as $name) {
            if (is_string($name) && $name !== '') {
                $result[] = $name;
            }
        }

        return $result;
    }
}
