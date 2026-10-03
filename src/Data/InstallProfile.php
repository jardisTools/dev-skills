<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * The installation profile: `core` installs the skills that serve any PHP project, `jardis` adds the
 * skills for projects that use the Jardis builder and its generated code. Every bundle skill declares
 * its profile in the frontmatter field `profile`.
 */
enum InstallProfile: string
{
    case Core = 'core';
    case Jardis = 'jardis';
}
