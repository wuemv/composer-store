<?php

declare(strict_types=1);

namespace ComposerStore\Installer;

use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;

/**
 * Finds the packages a patches plugin will modify after install: cweagans/composer-patches 1.x and
 * 2.x, and plugins that read the same `extra.patches` format. Patching rewrites files in vendor/, so
 * these packages are never linked from the store.
 *
 * Every source is read whether or not a patches plugin is installed: a package wrongly treated as
 * patched is only copied instead of linked.
 */
final class PatchedPackages
{
    /**
     * @param iterable<PackageInterface> $packages packages that may declare patches for others
     *
     * @return array<string, true> lower-cased package names
     */
    public static function find(RootPackageInterface $root, string $projectDir, iterable $packages): array
    {
        $names = [];
        $extra = $root->getExtra();
        self::addTargets($names, $extra['patches'] ?? null);
        foreach (self::patchFiles($extra, $projectDir) as $file) {
            self::addTargets($names, self::readJson($file)['patches'] ?? null);
        }
        foreach ($packages as $package) {
            self::addTargets($names, $package->getExtra()['patches'] ?? null);
        }

        return $names;
    }

    /**
     * @param array<mixed> $extra the root package's extra
     *
     * @return list<string>
     */
    private static function patchFiles(array $extra, string $projectDir): array
    {
        $files = [];
        // 1.x: extra.patches-file
        if (is_string($extra['patches-file'] ?? null)) {
            $files[] = $extra['patches-file'];
        }
        // 2.x: extra.composer-patches.patches-file, patches.json by default, and the resolved patches.lock.json
        $settings = $extra['composer-patches'] ?? null;
        $files[] = is_array($settings) && is_string($settings['patches-file'] ?? null)
            ? $settings['patches-file']
            : 'patches.json';
        $files[] = 'patches.lock.json';

        $filesystem = new Filesystem();

        return array_map(
            static fn (string $file): string => $filesystem->isAbsolutePath($file) ? $file : $projectDir . '/' . $file,
            $files
        );
    }

    /**
     * @param array<string, true> $names
     */
    private static function addTargets(array &$names, mixed $patches): void
    {
        if (!is_array($patches)) {
            return;
        }
        foreach (array_keys($patches) as $name) {
            if (is_string($name) && $name !== '') {
                $names[strtolower($name)] = true;
            }
        }
    }

    /**
     * @return array<mixed>
     */
    private static function readJson(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }
}
