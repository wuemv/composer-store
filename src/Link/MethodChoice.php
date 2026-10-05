<?php

declare(strict_types=1);

namespace ComposerStore\Link;

use ComposerStore\Mode;

/**
 * The method an install uses for the configured mode, or why it does not use the store at all.
 * Reflinks are preferred where the filesystem has them: clones share disk space like hard links, but
 * nothing done to a file in vendor/ can reach the store.
 */
final class MethodChoice
{
    public function __construct(public readonly ?Method $method, public readonly string $reason = '')
    {
    }

    /**
     * Only for a store on the same filesystem as vendor/ (see Device::same()). Unless the mode settles
     * it, a file is cloned in the store's tmp/ to see whether that filesystem has reflinks.
     */
    public static function make(Mode $mode, string $storeRoot, Cloner $cloner): self
    {
        if ($mode === Mode::Copy) {
            return new self(null, 'mode is copy');
        }
        if ($mode === Mode::Hardlink) {
            return new self(Method::Hardlink);
        }
        if ($cloner->isSupported($storeRoot . '/tmp')) {
            return new self(Method::Reflink);
        }
        if ($mode === Mode::Auto) {
            return new self(Method::Hardlink);
        }

        return new self(null, $cloner->isAvailable()
            ? 'the filesystem of the store does not support reflinks'
            : 'reflinks are not supported on this operating system');
    }
}
