<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * One contiguous text change: `length` bytes at `offset` are replaced by `replacement`
 * (length 0 is a pure insertion).
 */
final class TextEdit
{
    public function __construct(
        public readonly int $offset,
        public readonly int $length,
        public readonly string $replacement,
    ) {
    }
}
