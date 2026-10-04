<?php

declare(strict_types=1);

namespace ComposerStore\Store;

/**
 * How an entry's files are used, from their link counts. Each link beyond the store's own is a
 * vendor/ copy that did not need its own disk space.
 */
final class EntryStats
{
    /**
     * @param int $bytes       space the files take on disk (their apparent size where blocks are unknown)
     * @param int $linkedFiles files linked from at least one vendor/ directory
     * @param int $links       links from vendor/ directories, over all files
     * @param int $savedBytes  disk space those links would take as separate copies
     */
    public function __construct(
        public readonly int $files = 0,
        public readonly int $bytes = 0,
        public readonly int $linkedFiles = 0,
        public readonly int $links = 0,
        public readonly int $savedBytes = 0,
    ) {
    }

    public static function of(string $dir): self
    {
        $files = $bytes = $linkedFiles = $links = $savedBytes = 0;
        if (!is_dir($dir)) {
            return new self();
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $info) {
            if (!$info instanceof \SplFileInfo || $info->isLink() || !$info->isFile()) {
                continue;
            }
            $stat = @lstat($info->getPathname());
            if ($stat === false) {
                continue; // removed while counting
            }
            $size = $stat['blocks'] >= 0 ? $stat['blocks'] * 512 : $stat['size'];
            $files++;
            $bytes += $size;
            if ($stat['nlink'] > 1) {
                $linkedFiles++;
                $links += $stat['nlink'] - 1;
                $savedBytes += $size * ($stat['nlink'] - 1);
            }
        }

        return new self($files, $bytes, $linkedFiles, $links, $savedBytes);
    }

    public function add(self $other): self
    {
        return new self(
            $this->files + $other->files,
            $this->bytes + $other->bytes,
            $this->linkedFiles + $other->linkedFiles,
            $this->links + $other->links,
            $this->savedBytes + $other->savedBytes,
        );
    }
}
