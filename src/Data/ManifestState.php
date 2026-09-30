<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * Health of the managed-skills manifest on disk.
 */
enum ManifestState
{
    case Healthy;
    case Missing;
    case Defective;
    case TooNew;
}
