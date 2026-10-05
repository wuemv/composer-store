<?php

declare(strict_types=1);

namespace ComposerStore\Store;

/**
 * Re-hashes store entries and compares them with the tree hash taken when they were created.
 *
 * A changed entry usually means a linked file was edited in place through some project's vendor/,
 * which changes it for every project linking it. The files modified after the entry was created are
 * reported as the likely culprits.
 */
final class Verifier
{
    public function __construct(private readonly Store $store)
    {
    }

    public function check(StoreEntry $entry): EntryCheck
    {
        if (!is_dir($entry->filesDir())) {
            return new EntryCheck($entry, EntryStatus::Invalid, ['files/ is missing']);
        }
        $meta = StoreEntry::readMeta($entry->path);
        if ($meta === null) {
            $reason = StoreEntry::META_FILE . ' is missing or unreadable';

            return new EntryCheck($entry, EntryStatus::Invalid, [$reason]);
        }
        // The metadata must name this package, and its version and reference must lead to this path.
        $expectedPath = $this->store->entryPath($entry->name, $entry->version, $entry->reference);
        if (!$entry->isValid() || $entry->reference === '' || $expectedPath !== $entry->path) {
            return new EntryCheck($entry, EntryStatus::Invalid, ['the metadata does not match the entry']);
        }

        $expected = $meta['tree_hash'] ?? null;
        if (!is_string($expected)) {
            return new EntryCheck($entry, EntryStatus::Unhashed);
        }
        if (TreeHasher::hash($entry->filesDir()) === $expected) {
            return new EntryCheck($entry, EntryStatus::Ok);
        }

        $createdAt = is_string($meta['created_at'] ?? null) ? strtotime($meta['created_at']) : false;

        return new EntryCheck(
            $entry,
            EntryStatus::Changed,
            $createdAt === false ? [] : self::modifiedSince($entry->filesDir(), $createdAt)
        );
    }

    /**
     * @return list<string> relative paths, sorted
     */
    private static function modifiedSince(string $dir, int $time): array
    {
        $modified = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $info) {
            if ($info instanceof \SplFileInfo && $info->isFile() && $info->getMTime() > $time) {
                $modified[] = str_replace('\\', '/', substr($info->getPathname(), strlen($dir) + 1));
            }
        }
        sort($modified, SORT_STRING);

        return $modified;
    }
}
