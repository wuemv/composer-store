<?php

declare(strict_types=1);

namespace ComposerStore\Store;

/**
 * What verifying a store entry found.
 */
enum EntryStatus: string
{
    case Ok = 'ok';

    /** The files no longer match the tree hash taken when the entry was created. */
    case Changed = 'changed';

    /** Missing files, unreadable metadata, or stored under the wrong key: installs never link it. */
    case Invalid = 'invalid';

    /** Created before tree hashes were recorded, so it cannot be checked. */
    case Unhashed = 'unhashed';
}
