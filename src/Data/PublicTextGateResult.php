<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * Outcome of the public-text gate: `$error` is set when the gate is red for
 * a configuration reason (denylist required but empty), `$regexOnly` tells
 * that no denylist terms were applied, `$violations` carry location and kind
 * only. The exit code follows from error and violations.
 */
final class PublicTextGateResult
{
    /**
     * @param list<PublicTextViolation> $violations
     */
    public function __construct(
        public readonly ?string $error,
        public readonly bool $regexOnly,
        public readonly int $scanned,
        public readonly array $violations,
    ) {
    }

    public function exitCode(): int
    {
        return $this->error === null && $this->violations === [] ? 0 : 1;
    }
}
