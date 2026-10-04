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
     * @return list<array{name: string, version: string, reference: string, source: string}> `version`
     *         is the pretty version; `source` is how Composer installed it: dist, source, or empty
     */
    public static function read(string $vendorDir): array
    {
        $data = json_decode((string) @file_get_contents($vendorDir . '/composer/installed.json'), true);
        if (!is_array($data)) {
            return [];
        }
        // Composer 2 wraps the list in {"packages": [...]}; Composer 1 wrote the list itself.
        $packages = is_array($data['packages'] ?? null) ? $data['packages'] : $data;

        $installed = [];
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $name = $package['name'] ?? null;
            $version = $package['version'] ?? null;
            $reference = is_array($package['dist'] ?? null) ? $package['dist']['reference'] ?? null : null;
            $source = $package['installation-source'] ?? null;
            if (is_string($name) && is_string($version) && is_string($reference) && $reference !== '') {
                $installed[] = [
                    'name' => $name,
                    'version' => $version,
                    'reference' => $reference,
                    'source' => is_string($source) ? $source : '',
                ];
            }
        }

        return $installed;
    }
}
