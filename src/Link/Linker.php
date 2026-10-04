<?php

declare(strict_types=1);

namespace ComposerStore\Link;

use ComposerStore\Store\Store;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

/**
 * Recreates a store entry's file tree at a vendor/ path, with real directories and, for every file, a
 * hard link to the store or a copy-on-write clone of it. Never symlinks: PHP resolves symlinks in
 * __DIR__, which would point packages at the store instead of the project.
 *
 * The tree is built next to its target and renamed into place, so a target is never half-built.
 */
final class Linker
{
    public function __construct(private readonly Cloner $cloner = new Cloner())
    {
    }

    public function cloner(): Cloner
    {
        return $this->cloner;
    }

    /**
     * Fills $target, which must not exist, with the tree at $source. Every file is hard-linked except
     * the paths in $copyPaths, which get their own copy. Composer chmods package binaries in place, and
     * through a hard link that would change the store's file for every project.
     *
     * @param array<string> $copyPaths paths relative to $source, e.g. the package's `bin` entries
     *
     * @throws LinkException when a hard link cannot be created; nothing is left at $target
     */
    public function link(string $source, string $target, array $copyPaths = []): void
    {
        $copy = array_fill_keys(array_map(self::normalizePath(...), $copyPaths), true);

        $this->place($target, static function (string $temp) use ($source, $copy): void {
            self::build($source, $temp, static function (string $from, string $to, string $relative) use ($copy): void {
                if (isset($copy[$relative])) {
                    self::copyFile($from, $to);
                } elseif (!@link($from, $to)) {
                    throw new LinkException(sprintf('cannot hard-link %s to %s: %s', $from, $to, self::lastError()));
                }
            });
        });
    }

    /**
     * Same as link(), but every file is a copy-on-write clone: it shares the store's data blocks, yet
     * is a file of its own, so nothing done to it in vendor/ reaches the store. That includes the
     * chmod of package binaries, so they are cloned too.
     *
     * @throws LinkException when the tree cannot be cloned; nothing is left at $target
     */
    public function reflink(string $source, string $target): void
    {
        $this->place($target, function (string $temp) use ($source): void {
            $this->cloner->cloneTree($source, $temp);
        });
    }

    /**
     * Same as reflink(), but leaves running cp to $run, so that several packages can be cloned at
     * once: $run gets the command and returns a promise that rejects when the command fails.
     *
     * @param callable(list<string>): PromiseInterface<mixed> $run
     *
     * @return PromiseInterface<null> rejected with a LinkException when the tree cannot be cloned;
     *                                nothing is left at $target then
     */
    public function reflinkAsync(string $source, string $target, callable $run): PromiseInterface
    {
        $temp = $this->startPlacing($target);
        try {
            $cloning = $run($this->cloner->treeCommand($source, $temp));
        } catch (\Throwable $e) {
            Store::removeTree($temp);

            return reject($e);
        }

        return $cloning->then(
            function () use ($temp, $target) {
                $this->finishPlacing($temp, $target);

                return null;
            },
            static function (\Throwable $e) use ($temp): void {
                Store::removeTree($temp);

                throw $e;
            }
        );
    }

    /**
     * Same as link(), but every file is copied.
     */
    public function copy(string $source, string $target): void
    {
        $this->place($target, static function (string $temp) use ($source): void {
            self::build($source, $temp, static function (string $from, string $to): void {
                self::copyFile($from, $to);
            });
        });
    }

    /**
     * Has $fill create the tree in a temp dir next to $target, then renames it into place.
     *
     * @param callable(string): void $fill creates the given path, which does not exist yet
     */
    private function place(string $target, callable $fill): void
    {
        $temp = $this->startPlacing($target);
        try {
            $fill($temp);
        } catch (\Throwable $e) {
            Store::removeTree($temp);
            throw $e;
        }
        $this->finishPlacing($temp, $target);
    }

    /**
     * Checks that $target does not exist, creates its parent, and returns a free path next to it.
     */
    private function startPlacing(string $target): string
    {
        if (file_exists($target) || is_link($target)) {
            throw new \RuntimeException($target . ' already exists');
        }
        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0777, true) && !is_dir($parent)) {
            throw new \RuntimeException('Cannot create ' . $parent . ': ' . self::lastError());
        }

        return sprintf('%s/.%s.composer-store-%s', $parent, basename($target), bin2hex(random_bytes(4)));
    }

    /**
     * Renames the finished tree into place, or removes it.
     */
    private function finishPlacing(string $temp, string $target): void
    {
        if (!@rename($temp, $target)) {
            $error = self::lastError();
            Store::removeTree($temp);
            throw new \RuntimeException(sprintf('Cannot move %s to %s: %s', $temp, $target, $error));
        }
    }

    /**
     * Recreates the tree at $source in $target, a new directory, handing each file to $placeFile.
     *
     * @param callable(string, string, string): void $placeFile
     */
    private static function build(string $source, string $target, callable $placeFile): void
    {
        self::makeDir($target);
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
            $to = $target . '/' . $relative;
            if ($info->isLink()) {
                // Part of the package itself: reproduce it as Composer's extraction would.
                self::copySymlink($from, $to);
            } elseif ($info->isDir()) {
                self::makeDir($to);
            } else {
                $placeFile($from, $to, $relative);
            }
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
