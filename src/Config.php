<?php

declare(strict_types=1);

namespace ComposerStore;

use Composer\Composer;
use Composer\Json\JsonFile;
use Composer\Util\Filesystem;

/**
 * Plugin settings from `extra.composer-store` in the global composer.json ($COMPOSER_HOME),
 * overridden key by key by the project's composer.json.
 */
final class Config
{
    public const EXTRA_KEY = 'composer-store';

    /**
     * @param list<string> $warnings problems found in the settings, already resolved to a default
     */
    public function __construct(
        public readonly Mode $mode,
        public readonly string $storeDir,
        public readonly array $warnings = [],
    ) {
    }

    public static function fromComposer(Composer $composer): self
    {
        $home = $composer->getConfig()->get('home');
        $home = is_string($home) ? $home : '';
        $warnings = [];
        $global = self::readExtra($home . '/composer.json', $warnings);
        $project = $composer->getPackage()->getExtra()[self::EXTRA_KEY] ?? null;

        return self::fromSettings([$global, $project], self::storeDir($home), $warnings);
    }

    /**
     * @param list<mixed>  $layers   values of extra.composer-store, lowest precedence first
     * @param list<string> $warnings
     */
    public static function fromSettings(array $layers, string $storeDir, array $warnings = []): self
    {
        $settings = [];
        foreach ($layers as $layer) {
            if ($layer === null) {
                continue;
            }
            if (!is_array($layer)) {
                $warnings[] = 'extra.' . self::EXTRA_KEY . ' must be an object, ignoring it';
                continue;
            }
            $settings = array_replace($settings, $layer);
        }

        $mode = Mode::Auto;
        if (array_key_exists('mode', $settings)) {
            $parsed = is_string($settings['mode']) ? Mode::tryFrom($settings['mode']) : null;
            if ($parsed === null) {
                $warnings[] = sprintf(
                    'unknown mode %s (expected auto, reflink, hardlink or copy), using auto',
                    json_encode($settings['mode'])
                );
            }
            $mode = $parsed ?? Mode::Auto;
        }

        return new self($mode, $storeDir, $warnings);
    }

    /**
     * $COMPOSER_STORE_DIR, or `store` inside the Composer home directory.
     */
    public static function storeDir(string $composerHome): string
    {
        $dir = $_SERVER['COMPOSER_STORE_DIR'] ?? getenv('COMPOSER_STORE_DIR');
        if (!is_string($dir) || $dir === '') {
            return rtrim($composerHome, '/\\') . '/store';
        }
        if ((new Filesystem())->isAbsolutePath($dir)) {
            return $dir;
        }
        $cwd = getcwd();

        return ($cwd === false ? '.' : $cwd) . '/' . $dir;
    }

    /**
     * @param list<string> $warnings
     */
    private static function readExtra(string $composerJson, array &$warnings): mixed
    {
        if (!is_file($composerJson)) {
            return null;
        }
        try {
            $data = (new JsonFile($composerJson))->read();
        } catch (\Exception $e) {
            $warnings[] = sprintf('cannot read %s: %s', $composerJson, $e->getMessage());

            return null;
        }

        return is_array($data) && is_array($data['extra'] ?? null) ? $data['extra'][self::EXTRA_KEY] ?? null : null;
    }
}
