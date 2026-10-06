<?php

declare(strict_types=1);

namespace ComposerStore\Store;

/**
 * The packages a vendor directory holds, from its composer/installed.json.
 */
final class InstalledPackages
{
    /**
     * Packages with a dist reference, the part of the store key installed.json does not spell out.
     *
     * @return list<array{name: string, version: string, reference: string, source: string, install-path: string}>
     *         `version` is the pretty version; `source` is how Composer installed it: dist, source, or
     *         empty; `install-path` is relative to the vendor directory
     */
    public static function read(string $vendorDir): array
    {
        $installed = [];
        foreach (self::entries($vendorDir) as $package) {
            $name = $package['name'] ?? null;
            $version = $package['version'] ?? null;
            $reference = is_array($package['dist'] ?? null) ? $package['dist']['reference'] ?? null : null;
            $source = $package['installation-source'] ?? null;
            if (is_string($name) && is_string($version) && is_string($reference) && $reference !== '') {
                $path = $package['install-path'] ?? null;
                $installed[] = [
                    'name' => $name,
                    'version' => $version,
                    'reference' => $reference,
                    'source' => is_string($source) ? $source : '',
                    // installed.json gives it relative to vendor/composer, like ../laravel/framework.
                    'install-path' => is_string($path) && str_starts_with($path, '../') ? substr($path, 3) : $name,
                ];
            }
        }

        return $installed;
    }

    /**
     * All the packages installed.json lists, dist reference or not.
     */
    public static function count(string $vendorDir): int
    {
        return count(self::entries($vendorDir));
    }

    /**
     * @return list<array<mixed>>
     */
    private static function entries(string $vendorDir): array
    {
        $data = json_decode((string) @file_get_contents($vendorDir . '/composer/installed.json'), true);
        if (!is_array($data)) {
            return [];
        }
        // Composer 2 wraps the list in {"packages": [...]}; Composer 1 wrote the list itself.
        $packages = is_array($data['packages'] ?? null) ? $data['packages'] : $data;

        return array_values(array_filter($packages, 'is_array'));
    }
}
