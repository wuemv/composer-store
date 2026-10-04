<?php

declare(strict_types=1);

namespace ComposerStore\Store;

use Composer\Package\PackageInterface;

/**
 * The global store: one directory per package version, shared by every project on the machine.
 * Project operations only ever add entries. Only store:prune deletes them, under the exclusive lock.
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
     * The projects that have linked from this store.
     */
    public function projects(): ProjectRegistry
    {
        return new ProjectRegistry($this->root . '/projects.json');
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
        $path = $this->entryPath($package->getName(), $package->getPrettyVersion(), $reference);

        return new StoreEntry($path, $package->getName(), $package->getPrettyVersion(), $reference);
    }

    /**
     * Where the entry for a package version lives: packages/<vendor>/<name>/<version>-<reference-short>.
     */
    public function entryPath(string $name, string $prettyVersion, string $reference): string
    {
        return sprintf(
            '%s/packages/%s/%s-%s',
            $this->root,
            strtolower($name),
            self::slug($prettyVersion),
            self::shortReference($reference)
        );
    }

    /**
     * Every entry directory in the store, described by its metadata. An entry whose metadata is
     * missing or unreadable is listed too, with an empty version and reference: it is not valid.
     *
     * @return list<StoreEntry>
     */
    public function entries(): array
    {
        $entries = [];
        foreach (self::subdirectories($this->root . '/packages') as $vendor) {
            foreach (self::subdirectories($vendor) as $package) {
                $name = basename($vendor) . '/' . basename($package);
                foreach (self::subdirectories($package) as $dir) {
                    $meta = StoreEntry::readMeta($dir) ?? [];
                    $version = is_string($meta['version'] ?? null) ? $meta['version'] : '';
                    $reference = is_string($meta['reference'] ?? null) ? $meta['reference'] : '';
                    $entries[] = new StoreEntry($dir, $name, $version, $reference);
                }
            }
        }

        return $entries;
    }

    /**
     * Temp dirs left by installs that were interrupted before publishing their entry.
     *
     * @return list<string>
     */
    public function tempDirs(): array
    {
        return self::subdirectories($this->root . '/tmp');
    }

    /**
     * Deletes an entry, and the vendor and name directories it leaves empty. The entry first moves to
     * tmp/ in one rename, so a delete that is interrupted never leaves a partial entry for installs to
     * link: what remains in tmp/ goes on the next prune. Hard links to the entry's files keep working,
     * since a file's data stays until its last link is gone.
     */
    public function removeEntry(StoreEntry $entry): void
    {
        $trash = $this->newTempPath();
        if (!@rename($entry->path, $trash)) {
            throw new StoreException(sprintf('Cannot move %s to %s: %s', $entry->path, $trash, self::lastError()));
        }
        $parent = dirname($entry->path);
        foreach ([$parent, dirname($parent)] as $dir) {
            if (is_dir($dir) && (scandir($dir) ?: []) === ['.', '..']) {
                @rmdir($dir);
            }
        }
        self::removeTree($trash);
        if (file_exists($trash)) {
            throw new StoreException(sprintf('Cannot delete all of %s (it was %s)', $trash, $entry->path));
        }
    }

    /**
     * A new empty directory inside the store, to build an entry in before publishing it.
     */
    public function createTempDir(): string
    {
        $dir = $this->newTempPath();
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

    /**
     * A path in tmp/ that does not exist yet.
     */
    private function newTempPath(): string
    {
        $parent = $this->root . '/tmp';
        self::ensureDir($parent);
        do {
            $path = $parent . '/' . bin2hex(random_bytes(8));
        } while (file_exists($path));

        return $path;
    }

    /**
     * @return list<string> full paths, sorted
     */
    private static function subdirectories(string $dir): array
    {
        $dirs = [];
        foreach (@scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && is_dir($dir . '/' . $name) && !is_link($dir . '/' . $name)) {
                $dirs[] = $dir . '/' . $name;
            }
        }

        return $dirs;
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
