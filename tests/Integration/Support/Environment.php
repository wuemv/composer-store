<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration\Support;

use ComposerStore\Tests\Support\Files;
use ComposerStore\Tests\Support\ProcessResult;

/**
 * Shared by all integration tests of one PHPUnit run, built on first use:
 * the fixture repository, a copy of the plugin, a Composer home with the plugin installed globally,
 * a Composer home without it, and a Composer cache.
 *
 * Everything lives in one temp dir, removed at exit unless COMPOSER_STORE_TEST_KEEP is set.
 */
final class Environment
{
    public const PLUGIN_NAME = 'wuemv/composer-store';

    private static ?self $instance = null;

    /**
     * @param array<string, array<string, string>> $references package name => version => commit hash
     */
    private function __construct(
        public readonly string $root,
        public readonly string $repository,
        public readonly string $plugin,
        public readonly string $pluginHome,
        public readonly string $plainHome,
        public readonly string $cache,
        public readonly ComposerRunner $composer,
        public readonly array $references,
    ) {
    }

    public static function get(): self
    {
        return self::$instance ??= self::create();
    }

    public function reference(string $package, string $version): string
    {
        return $this->references[$package][$version] ?? throw new \LogicException("No fixture {$package} {$version}");
    }

    private static function create(): self
    {
        $root = Files::tempDir('composer-store-tests');
        if (getenv('COMPOSER_STORE_TEST_KEEP') === false) {
            register_shutdown_function(static fn () => Files::remove($root));
        }

        $references = FixtureRepository::build(dirname(__DIR__, 2) . '/Fixtures/packages', $root);
        $environment = new self(
            $root,
            $root . '/repository',
            self::copyPlugin($root . '/plugin'),
            $root . '/home-with-plugin',
            $root . '/home-without-plugin',
            $root . '/cache',
            ComposerRunner::fromEnvironment(),
            $references,
        );
        Files::makeDir($environment->plainHome);
        $environment->installPluginGlobally();

        return $environment;
    }

    /**
     * The plugin as a package a path repository can install: composer.json with a version, src/ and
     * bin/, as in the archives Composer downloads.
     */
    private static function copyPlugin(string $dir): string
    {
        $project = dirname(__DIR__, 3);
        $manifest = Files::readJson($project . '/composer.json');
        $keep = array_flip(['name', 'description', 'type', 'require', 'bin', 'autoload', 'extra']);
        Files::writeJson($dir . '/composer.json', array_intersect_key($manifest, $keep) + ['version' => '1.0.0']);
        Files::copyTree($project . '/src', $dir . '/src');
        Files::copyTree($project . '/bin', $dir . '/bin');

        return $dir;
    }

    /**
     * What `composer global require` would do, without touching the real Composer home.
     */
    private function installPluginGlobally(): void
    {
        Files::writeJson($this->pluginHome . '/composer.json', [
            'repositories' => [
                ['type' => 'path', 'url' => $this->plugin, 'options' => ['symlink' => false]],
                ['packagist.org' => false],
            ],
            'require' => [self::PLUGIN_NAME => '1.0.0'],
            'config' => ['allow-plugins' => [self::PLUGIN_NAME => true]],
            // The same method on every machine: auto would clone on APFS. ReflinkTest sets mode itself.
            'extra' => ['composer-store' => ['mode' => 'hardlink']],
        ]);
        $result = $this->composer->run($this->pluginHome, ['install'], [
            'COMPOSER_HOME' => $this->pluginHome,
            'COMPOSER_CACHE_DIR' => $this->cache,
        ]);
        self::assertSucceeded($result);
    }

    public static function assertSucceeded(ProcessResult $result): void
    {
        if ($result->exitCode !== 0) {
            throw new \RuntimeException("Command failed:\n" . $result->describe());
        }
    }
}
