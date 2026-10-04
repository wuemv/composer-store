<?php

declare(strict_types=1);

namespace ComposerStore\Link;

use ComposerStore\Store\Store;

/**
 * Recreates a store entry's file tree at a vendor/ path: real directories, and a hard link to the
 * store for every file. Never symlinks: PHP resolves symlinks in __DIR__, which would point packages
 * at the store instead of the project.
 */
final class Linker
{
    /**
     * Fills $target, which must not exist, with the tree at $source. Every file is hard-linked except
     * the paths in $copyPaths, which get their own copy. Composer chmods package binaries in place, and
     * through a hard link that would change the store's file for every project.
     *
     * The tree is built next to $target and renamed into place, so $target is never half-linked.
     *
     * @param array<string> $copyPaths paths relative to $source, e.g. the package's `bin` entries
     *
     * @throws LinkException when a hard link cannot be created; nothing is left at $target
     */
    public function link(string $source, string $target, array $copyPaths = []): void
    {
        $copy = array_fill_keys(array_map(self::normalizePath(...), $copyPaths), true);

        $this->build($source, $target, static function (string $from, string $to, string $relative) use ($copy): void {
            if (isset($copy[$relative])) {
                self::copyFile($from, $to);
            } elseif (!@link($from, $to)) {
                throw new LinkException(sprintf('cannot hard-link %s to %s: %s', $from, $to, self::lastError()));
            }
        });
    }

    /**
     * Same as link(), but every file is copied.
     */
    public function copy(string $source, string $target): void
    {
        $this->build($source, $target, static function (string $from, string $to): void {
            self::copyFile($from, $to);
        });
    }

    /**
     * @param callable(string, string, string): void $placeFile
     */
    private function build(string $source, string $target, callable $placeFile): void
    {
        if (file_exists($target) || is_link($target)) {
            throw new \RuntimeException($target . ' already exists');
        }
        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0777, true) && !is_dir($parent)) {
            throw new \RuntimeException('Cannot create ' . $parent . ': ' . self::lastError());
        }

        $temp = sprintf('%s/.%s.composer-store-%s', $parent, basename($target), bin2hex(random_bytes(4)));
        try {
            self::makeDir($temp);
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($entries as $info) {
                if (!$info instanceof \SplFileInfo) {
                    continue;
                }
                $from = $info->getPathname();
                $relative = self::normalizePath(substr($from, strlen($source) + 1));
                $to = $temp . '/' . $relative;
                if ($info->isLink()) {
                    // Part of the package itself: reproduce it as Composer's extraction would.
                    self::copySymlink($from, $to);
                } elseif ($info->isDir()) {
                    self::makeDir($to);
                } else {
                    $placeFile($from, $to, $relative);
                }
            }
            if (!@rename($temp, $target)) {
                throw new \RuntimeException(sprintf('Cannot move %s to %s: %s', $temp, $target, self::lastError()));
            }
        } catch (\Throwable $e) {
            Store::removeTree($temp);
            throw $e;
        }
    }

    private static function copyFile(string $from, string $to): void
    {
        if (!@copy($from, $to)) {
            throw new \RuntimeException(sprintf('Cannot copy %s to %s: %s', $from, $to, self::lastError()));
        }
        $perms = @fileperms($from);
        if ($perms !== false) {
            @chmod($to, $perms & 0777);
        }
    }

    private static function copySymlink(string $from, string $to): void
    {
        $link = @readlink($from);
        if ($link === false || !@symlink($link, $to)) {
            throw new \RuntimeException(sprintf('Cannot copy the symlink %s to %s: %s', $from, $to, self::lastError()));
        }
    }

    private static function makeDir(string $dir): void
    {
        if (!@mkdir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir . ': ' . self::lastError());
        }
    }

    /**
     * `./bin\tool` and `bin//tool` both become `bin/tool`.
     */
    private static function normalizePath(string $path): string
    {
        $parts = array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn (string $part): bool => $part !== '' && $part !== '.'
        );

        return implode('/', $parts);
    }

    private static function lastError(): string
    {
        return error_get_last()['message'] ?? 'unknown error';
    }
}
