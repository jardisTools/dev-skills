<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * Whether the process documents and the files the plugin creates are meant for the commit
 * (`committed`, the default) or stay out of it through the local Git exclude file (`local`).
 */
enum ProcessDocsMode: string
{
    case Committed = 'committed';
    case Local = 'local';
}
