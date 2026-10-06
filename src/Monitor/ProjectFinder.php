<?php

declare(strict_types=1);

namespace ComposerStore\Monitor;

/**
 * The Composer projects in a directory: the directory itself when it holds a composer.json, otherwise
 * the directories up to three levels below it that do. A project's own subdirectories are not searched,
 * and neither are vendor/, node_modules/ and hidden directories.
 */
final class ProjectFinder
{
    private const MAX_DEPTH = 3;
    private const SKIPPED = ['vendor', 'node_modules'];

    /**
     * @return list<string> project directories, sorted
     */
    public static function find(string $root): array
    {
        $projects = [];
        self::search(rtrim($root, '/\\'), 0, $projects);
        sort($projects, SORT_STRING);

        return $projects;
    }

    /**
     * @param list<string> $projects
     */
    private static function search(string $dir, int $depth, array &$projects): void
    {
        if (is_file($dir . '/composer.json')) {
            $projects[] = $dir;

            return;
        }
        if ($depth >= self::MAX_DEPTH) {
            return;
        }
        foreach (@scandir($dir) ?: [] as $name) {
            if ($name[0] === '.' || in_array($name, self::SKIPPED, true)) {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                self::search($path, $depth + 1, $projects);
            }
        }
    }
}
