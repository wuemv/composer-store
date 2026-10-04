<?php

declare(strict_types=1);

namespace ComposerStore\Installer;

use Composer\Package\PackageInterface;
use ComposerStore\NamePatterns;

/**
 * Packages that must be installed by Composer as usual even though they could be linked: the ones
 * the user excluded, and the ones a patches plugin will modify.
 */
final class SkipRules
{
    private readonly NamePatterns $exclude;

    /**
     * @param list<string>        $exclude package names; `*` matches any characters
     * @param array<string, true> $patched lower-cased names of packages that will be patched
     */
    public function __construct(array $exclude, private readonly array $patched)
    {
        $this->exclude = new NamePatterns($exclude);
    }

    /**
     * Why the package must not be linked, or null when it can be.
     */
    public function reason(PackageInterface $package): ?string
    {
        if ($this->exclude->matches($package->getName())) {
            return 'excluded in extra.composer-store.exclude';
        }
        if (isset($this->patched[strtolower($package->getName())])) {
            return 'patched by a patches plugin';
        }

        return null;
    }
}
