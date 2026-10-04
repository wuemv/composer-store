<?php

declare(strict_types=1);

namespace ComposerStore\Store;

use Composer\Package\PackageInterface;

/**
 * The global store: one directory per package version, shared by every project on the machine.
 * Project operations only ever add entries; nothing here deletes one.
 */
final class Store
{
    public function __construct(private readonly string $root)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * The store-wide lock: shared while installing, exclusive while deleting entries.
     */
    public function lock(): StoreLock
    {
        return new StoreLock($this->root . '/.lock');
    }

    /**
     * Creates the store directories if needed and returns the store's real path.
     */
    public function initialize(): string
    {
        foreach ([$this->root, $this->root . '/packages', $this->root . '/tmp'] as $dir) {
            self::ensureDir($dir);
        }
        $real = realpath($this->root);
        if ($real === false) {
            throw new StoreException('Cannot resolve the store directory ' . $this->root);
        }

        return $real;
    }

    /**
     * The entry for a package version, keyed by name + version + dist reference.
     * Null when the package has no dist reference to key on.
     */
    public function entryFor(PackageInterface $package): ?StoreEntry
    {
        $reference = $package->getDistReference();
        if ($reference === null || $reference === '') {
            return null;
        }
        $path = sprintf(
            '%s/packages/%s/%s-%s',
            $this->root,
            $package->getName(),
            self::slug($package->getPrettyVersion()),
            self::shortReference($reference)
        );

        return new StoreEntry($path, $package->getName(), $package->getPrettyVersion(), $reference);
    }

    /**
     * A new empty directory inside the store, to build an entry in before publishing it.
     */
    public function createTempDir(): string
    {
        $parent = $this->root . '/tmp';
        self::ensureDir($parent);
        do {
            $dir = $parent . '/' . bin2hex(random_bytes(8));
        } while (file_exists($dir));
        if (!@mkdir($dir)) {
            throw new StoreException('Cannot create ' . $dir . ': ' . self::lastError());
        }

        return $dir;
    }

    /**
     * Turns a temp dir holding a complete `files/` tree into $entry: adds the tree hash to the
     * metadata, makes the files read-only if asked, writes the metadata, and renames the temp dir into
     * place. The rename is atomic, so other processes see either no entry or a complete one.
     *
     * When another process got there first, its entry is used only if it holds the same files.
     *
     * @param array<string, mixed> $meta
     */
    public function publish(string $tempDir, StoreEntry $entry, array $meta, bool $readOnly = false): PublishResult
    {
        $files = $tempDir . '/' . StoreEntry::FILES_DIR;
        $meta['tree_hash'] = TreeHasher::hash($files);
        if ($readOnly) {
            self::makeReadOnly($files);
        }
        StoreEntry::writeMeta($tempDir, $meta);
        self::ensureDir(dirname($entry->path));

        if (@rename($tempDir, $entry->path)) {
            return PublishResult::Published;
        }
        $error = self::lastError();
        clearstatcache();
        if (!is_dir($entry->filesDir())) {
            throw new StoreException(sprintf('Cannot move %s to %s: %s', $tempDir, $entry->path, $error));
        }

        $existingHash = StoreEntry::readMeta($entry->path)['tree_hash'] ?? $meta['tree_hash'];
        if ($entry->isValid() && $existingHash === $meta['tree_hash']) {
            self::removeTree($tempDir);

            return PublishResult::AlreadyPublished;
        }

        return PublishResult::Conflict;
    }

    /**
     * Removes the write bits of every file in a tree. Hard links share permissions, so the files
     * become read-only in every vendor/ that links them. Directories stay writable.
     */
    public static function makeReadOnly(string $dir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $info) {
            if ($info instanceof \SplFileInfo && $info->isFile() && !$info->isLink()) {
                $perms = $info->getPerms() & 07777;
                if (($perms & 0222) !== 0) {
                    @chmod($info->getPathname(), $perms & ~0222);
                }
            }
        }
    }

    /**
     * Deletes a directory tree without following symlinks.
     */
    public static function removeTree(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $info) {
            if ($info instanceof \SplFileInfo) {
                $info->isDir() && !$info->isLink() ? @rmdir($info->getPathname()) : @unlink($info->getPathname());
            }
        }
        @rmdir($dir);
    }

    private static function slug(string $version): string
    {
        $slug = (string) preg_replace('{[^A-Za-z0-9._+-]+}', '_', $version);

        return trim($slug, '.') === '' ? '_' : $slug;
    }

    /**
     * The first 12 characters of a commit hash; anything else is hashed so it is filesystem-safe.
     * The full reference is in the entry metadata and is checked before an entry is used.
     */
    private static function shortReference(string $reference): string
    {
        if (preg_match('{^[0-9a-f]{12,}$}i', $reference) === 1) {
            return strtolower(substr($reference, 0, 12));
        }

        return substr(hash('sha256', $reference), 0, 12);
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new StoreException('Cannot create ' . $dir . ': ' . self::lastError());
        }
    }

    private static function lastError(): string
    {
        return error_get_last()['message'] ?? 'unknown error';
    }
}
