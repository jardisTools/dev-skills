<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Pool;

use JardisTools\DevSkills\Data\PoolPage;
use JardisTools\DevSkills\Exception\PoolNotFoundException;

/**
 * Loads the pool of a project: `<root>/.claude/wissen/INDEX.md` and every other `*.md` directly in that
 * folder, each parsed by ParsePage, in name order. A link is never followed: a pool folder behind a link
 * counts as missing, a linked file is left out. Throws PoolNotFoundException when there is no pool folder.
 */
final class LoadPool
{
    public const POOL_DIR = '.claude/wissen';

    public function __construct(
        private readonly ResolvePath $resolvePath = new ResolvePath(),
        private readonly ParsePage $parsePage = new ParsePage(),
    ) {
    }

    /**
     * @return array{index: PoolPage|null, pages: list<PoolPage>, files: list<PoolPage>}
     */
    public function __invoke(string $root): array
    {
        $poolDir = ($this->resolvePath)($root, self::POOL_DIR);
        if ($poolDir === null || !is_dir($poolDir)) {
            throw new PoolNotFoundException(
                sprintf('no knowledge pool at %s (missing or behind a link)', self::POOL_DIR),
            );
        }

        $names = scandir($poolDir);
        $names = $names === false ? [] : $names;
        sort($names);

        $index = null;
        $pages = [];
        foreach ($names as $name) {
            $path = $poolDir . '/' . $name;
            if (!str_ends_with($name, '.md') || is_link($path) || !is_file($path)) {
                continue;
            }
            $page = ($this->parsePage)($path, self::POOL_DIR . '/' . $name);
            if ($name === 'INDEX.md') {
                $index = $page;
            } else {
                $pages[] = $page;
            }
        }

        return ['index' => $index, 'pages' => $pages, 'files' => $index === null ? $pages : [...$pages, $index]];
    }
}
