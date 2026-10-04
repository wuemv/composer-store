<?php

declare(strict_types=1);

namespace ComposerStore\Store;

/**
 * The result of verifying one store entry.
 */
final class EntryCheck
{
    /**
     * @param list<string> $details why the entry is invalid, or the files modified after it was created
     */
    public function __construct(
        public readonly StoreEntry $entry,
        public readonly EntryStatus $status,
        public readonly array $details = [],
    ) {
    }

    public function isProblem(): bool
    {
        return $this->status === EntryStatus::Changed || $this->status === EntryStatus::Invalid;
    }
}
