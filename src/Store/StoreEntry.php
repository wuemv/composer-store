<?php

declare(strict_types=1);

namespace ComposerStore\Store;

/**
 * One package version in the store:
 *
 *     packages/<vendor>/<name>/<version>-<reference-short>/
 *         files/              the package, exactly as Composer extracts it
 *         .store-meta.json    what the entry holds and where it came from
 *
 * Entries are only ever created by renaming a complete temp dir into place, so an entry that
 * exists is complete.
 */
final class StoreEntry
{
    public const FILES_DIR = 'files';
    public const META_FILE = '.store-meta.json';
    public const META_FORMAT = 1;

    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly string $version,
        public readonly string $reference,
    ) {
    }

    public function filesDir(): string
    {
        return $this->path . '/' . self::FILES_DIR;
    }

    /**
     * @phpstan-impure another process can create the entry at any time
     */
    public function exists(): bool
    {
        return file_exists($this->path);
    }

    /**
     * The entry exists and its metadata says it holds exactly this package version.
     *
     * @phpstan-impure another process can create the entry at any time
     */
    public function isValid(): bool
    {
        if (!is_dir($this->filesDir())) {
            return false;
        }
        $meta = self::readMeta($this->path);

        return $meta !== null
            && ($meta['name'] ?? null) === $this->name
            && ($meta['reference'] ?? null) === $this->reference;
    }

    /**
     * @return array<mixed>|null
     */
    public static function readMeta(string $entryDir): ?array
    {
        $json = @file_get_contents($entryDir . '/' . self::META_FILE);
        if ($json === false) {
            return null;
        }
        $meta = json_decode($json, true);

        return is_array($meta) ? $meta : null;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function writeMeta(string $entryDir, array $meta): void
    {
        $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($entryDir . '/' . self::META_FILE, $json) === false) {
            throw new StoreException('Cannot write ' . $entryDir . '/' . self::META_FILE);
        }
    }
}
