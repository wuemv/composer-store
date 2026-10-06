<?php

declare(strict_types=1);

namespace ComposerStore\Monitor;

use ComposerStore\Link\Method;

/**
 * What one project's vendor/ holds and what it takes on disk, as measured from its files.
 */
final class ProjectUsage
{
    /**
     * @param int          $packages    packages its installed.json lists
     * @param ?int         $fromStore   packages whose files are links or clones of the store's, null where
     *                                  clones cannot be seen
     * @param list<Method> $methods     how those packages came from the store
     * @param list<string> $entries     the store entries those packages come from
     * @param int          $vendorBytes what vendor/'s files take as separate copies: what du and Finder count
     * @param ?int         $ownBytes    what only this vendor/ holds: data no other file shares, null where
     *                                  clones cannot be seen
     */
    public function __construct(
        public readonly string $dir,
        public readonly string $vendorDir,
        public readonly int $packages,
        public readonly ?int $fromStore,
        public readonly array $methods,
        public readonly array $entries,
        public readonly int $files,
        public readonly int $vendorBytes,
        public readonly ?int $ownBytes,
    ) {
    }

    /**
     * Reflinks, hard links, or both: what the packages that come from the store were linked with.
     */
    public function linkedBy(): ?string
    {
        return match (count($this->methods)) {
            0 => null,
            1 => $this->methods[0]->value,
            default => 'mixed',
        };
    }
}
