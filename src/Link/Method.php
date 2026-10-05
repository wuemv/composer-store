<?php

declare(strict_types=1);

namespace ComposerStore\Link;

/**
 * How an install puts store files into vendor/.
 */
enum Method: string
{
    /** Copy-on-write clones: files of their own that share the store's data blocks. */
    case Reflink = 'reflink';

    /** Hard links: the store's files themselves. */
    case Hardlink = 'hardlink';

    public function describe(): string
    {
        return $this === self::Reflink ? 'reflinks' : 'hard links';
    }
}
