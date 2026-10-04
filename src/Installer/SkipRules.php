<?php

declare(strict_types=1);

namespace ComposerStore\Installer;

use Composer\Package\PackageInterface;

/**
 * Packages that must be installed by Composer as usual even though they could be linked: the ones
 * the user excluded, and the ones a patches plugin will modify.
 */
final class SkipRules
{
    /** @var list<string> */
    private readonly array $excludePatterns;

    /**
     * @param list<string>        $exclude package names; `*` matches any characters
     * @param array<string, true> $patched lower-cased names of packages that will be patched
     */
    public function __construct(array $exclude, private readonly array $patched)
    {
        $this->excludePatterns = array_map(
            static fn (string $name): string => '{^' . str_replace('\*', '.*', preg_quote(strtolower($name))) . '$}',
            $exclude
        );
    }

    /**
     * Why the package must not be linked, or null when it can be.
     */
    public function reason(PackageInterface $package): ?string
    {
        $name = strtolower($package->getName());
        foreach ($this->excludePatterns as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return 'excluded in extra.composer-store.exclude';
            }
        }
        if (isset($this->patched[$name])) {
            return 'patched by a patches plugin';
        }

        return null;
    }
}
