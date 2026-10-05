<?php

declare(strict_types=1);

namespace ComposerStore\Store;

/**
 * What store:prune would delete.
 */
final class PrunePlan
{
    /**
     * @param list<array{entry: StoreEntry, stats: EntryStats}> $entries         entries no project uses
     * @param list<string>                                       $tempDirs        left by interrupted installs
     * @param list<string>                                       $missingProjects registered projects that are gone
     */
    public function __construct(
        public readonly array $entries,
        public readonly array $tempDirs,
        public readonly array $missingProjects,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->entries === [] && $this->tempDirs === [] && $this->missingProjects === [];
    }

    /**
     * Disk space the deletion frees.
     */
    public function bytes(): int
    {
        $bytes = 0;
        foreach ($this->entries as $unused) {
            $bytes += $unused['stats']->bytes;
        }
        foreach ($this->tempDirs as $dir) {
            $bytes += EntryStats::of($dir)->bytes;
        }

        return $bytes;
    }
}
