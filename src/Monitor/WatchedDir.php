<?php

declare(strict_types=1);

namespace ComposerStore\Monitor;

/**
 * The directory store:monitor and store:dashboard watch: as given, or as answered when they ask.
 */
final class WatchedDir
{
    /**
     * What to suggest when asking: ~/Developer, where Macs keep projects, when there is one;
     * otherwise the current directory.
     */
    public static function suggestion(?string $home = null, ?string $cwd = null): string
    {
        $home ??= self::home();
        if ($home !== null && is_dir($home . '/Developer')) {
            return '~/Developer';
        }
        $cwd ??= getcwd();

        return is_string($cwd) && $cwd !== '' ? $cwd : '.';
    }

    /**
     * The real path of the directory, with a leading ~ standing for the home directory, or null when
     * it is not a directory.
     */
    public static function resolve(string $path, ?string $home = null): ?string
    {
        $path = trim($path);
        $home ??= self::home();
        if ($home !== null && ($path === '~' || str_starts_with($path, '~/') || str_starts_with($path, '~\\'))) {
            $path = $home . substr($path, 1);
        }
        $dir = $path === '' ? false : realpath($path);

        return $dir !== false && is_dir($dir) ? $dir : null;
    }

    private static function home(): ?string
    {
        foreach (['HOME', 'USERPROFILE'] as $name) {
            $home = getenv($name);
            if (is_string($home) && $home !== '') {
                return rtrim($home, '/\\');
            }
        }

        return null;
    }
}
