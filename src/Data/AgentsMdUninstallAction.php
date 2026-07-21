<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

enum AgentsMdUninstallAction: string
{
    case FileDeleted = 'file_deleted';
    case BlockStripped = 'block_stripped';
    case Untouched = 'untouched';
    case Corrupt = 'corrupt';
}
