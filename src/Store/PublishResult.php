<?php

declare(strict_types=1);

namespace ComposerStore\Store;

/**
 * What Store::publish() did with a prepared temp dir.
 */
enum PublishResult
{
    /** The temp dir is now the entry. */
    case Published;

    /** Another process published the same files first. The temp dir was removed; use the entry. */
    case AlreadyPublished;

    /** Another process published different files under this key. The temp dir is left to the caller. */
    case Conflict;
}
