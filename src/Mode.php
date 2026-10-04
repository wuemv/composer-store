<?php

declare(strict_types=1);

namespace ComposerStore;

/**
 * How package files get from the store into vendor/ (`extra.composer-store.mode`).
 */
enum Mode: string
{
    /** Hard links when the store and vendor/ share a filesystem, otherwise Composer's normal copy. */
    case Auto = 'auto';

    /** Copy-on-write clones. Not implemented yet: packages are installed without the store. */
    case Reflink = 'reflink';

    /** Hard links, with Composer's normal copy when the store is on another filesystem. */
    case Hardlink = 'hardlink';

    /** Leave installs to Composer: the store is not used. */
    case Copy = 'copy';
}
