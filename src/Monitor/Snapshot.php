<?php

declare(strict_types=1);

namespace ComposerStore\Monitor;

/**
 * The projects in one directory, the store and the disk, measured at one moment.
 */
final class Snapshot
{
    /**
     * @param ?int               $freeBytes      free space on the directory's filesystem, null if unknown
     * @param int                $storeBytes     what all the store's entries take
     * @param ?int               $storeUsedBytes what the entries the projects link from take: their one copy,
     *                                           null where clones cannot be seen
     * @param list<ProjectUsage> $projects
     * @param bool               $clonesVisible  whether this machine can tell clones from copies
     * @param bool               $sameFilesystem whether the store is on the directory's filesystem
     */
    public function __construct(
        public readonly string $dir,
        public readonly ?int $freeBytes,
        public readonly int $storeEntries,
        public readonly int $storeBytes,
        public readonly ?int $storeUsedBytes,
        public readonly array $projects,
        public readonly bool $clonesVisible,
        public readonly bool $sameFilesystem,
    ) {
    }

    public function packages(): int
    {
        return array_sum(array_map(static fn (ProjectUsage $project): int => $project->packages, $this->projects));
    }

    public function fromStore(): ?int
    {
        return self::sum(array_map(static fn (ProjectUsage $project): ?int => $project->fromStore, $this->projects));
    }

    /**
     * What the projects' vendor/ directories would take as copies, as without the store.
     */
    public function vendorBytes(): int
    {
        return array_sum(array_map(static fn (ProjectUsage $project): int => $project->vendorBytes, $this->projects));
    }

    public function ownBytes(): ?int
    {
        return self::sum(array_map(static fn (ProjectUsage $project): ?int => $project->ownBytes, $this->projects));
    }

    /**
     * What the projects take with the store: one copy of each store entry they link, plus what each
     * vendor/ holds on its own.
     */
    public function withStoreBytes(): ?int
    {
        $own = $this->ownBytes();

        return $own === null || $this->storeUsedBytes === null ? null : $this->storeUsedBytes + $own;
    }

    public function savedBytes(): ?int
    {
        $withStore = $this->withStoreBytes();

        return $withStore === null ? null : $this->vendorBytes() - $withStore;
    }

    /**
     * The JSON form, with kebab-case keys like the other commands' output.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dir' => $this->dir,
            'free-bytes' => $this->freeBytes,
            'clones-visible' => $this->clonesVisible,
            'same-filesystem' => $this->sameFilesystem,
            'store' => [
                'entries' => $this->storeEntries,
                'bytes' => $this->storeBytes,
                'used-bytes' => $this->storeUsedBytes,
            ],
            'projects' => array_map(static fn (ProjectUsage $project): array => [
                'dir' => $project->dir,
                'vendor-dir' => $project->vendorDir,
                'packages' => $project->packages,
                'from-store' => $project->fromStore,
                'linked-by' => $project->linkedBy(),
                'files' => $project->files,
                'vendor-bytes' => $project->vendorBytes,
                'own-bytes' => $project->ownBytes,
            ], $this->projects),
            'totals' => [
                'packages' => $this->packages(),
                'from-store' => $this->fromStore(),
                'vendor-bytes' => $this->vendorBytes(),
                'own-bytes' => $this->ownBytes(),
                'with-store-bytes' => $this->withStoreBytes(),
                'saved-bytes' => $this->savedBytes(),
            ],
        ];
    }

    /**
     * @param list<?int> $values
     */
    private static function sum(array $values): ?int
    {
        return in_array(null, $values, true) ? null : array_sum($values);
    }
}
