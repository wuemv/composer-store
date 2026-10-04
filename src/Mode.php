<?php

declare(strict_types=1);

namespace ComposerStore;

/**
 * How package files get from the store into vendor/ (`extra.composer-store.mode`). Every mode except
 * copy needs the store on the same filesystem as vendor/, and otherwise leaves installs to Composer.
 */
enum Mode: string
{
    /** Reflinks where the filesystem supports them (APFS, Btrfs, XFS...), hard links elsewhere. */
    case Auto = 'auto';

    /** Copy-on-write clones only. Where reflinks are not supported, the store is not used. */
    case Reflink = 'reflink';

    /** Hard links, even where reflinks are supported. */
    case Hardlink = 'hardlink';

    /** Leave installs to Composer: the store is not used. */
    case Copy = 'copy';
}
