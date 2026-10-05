<?php

declare(strict_types=1);

namespace ComposerStore\Link;

/**
 * A hard link could not be created. Nothing was left at the target, so the caller can copy instead.
 */
final class LinkException extends \RuntimeException
{
}
