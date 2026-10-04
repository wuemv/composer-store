<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration;

use ComposerStore\Tests\Integration\Support\Environment;
use ComposerStore\Tests\Integration\Support\FixtureRepository;
use ComposerStore\Tests\Support\Files;
use ComposerStore\Tests\Support\Process;
use ComposerStore\Tests\Support\ProcessResult;
use ComposerStore\Tests\Support\RunningProcess;
use PHPUnit\Framework\TestCase;

/**
 * Runs real Composer commands against projects in a temp dir. The plugin is installed globally in an
 * isolated Composer home, so test projects look exactly like projects that know nothing about it.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Environment $env;

    /** This test's own directory, holding its projects and its store. */
    protected string $work;

    protected string $store;

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->env = Environment::get();
        $this->work = sprintf(
            '%s/work/%s-%s',
            $this->env->root,
            preg_replace('{\W+}', '-', $this->name()),
            bin2hex(random_bytes(3))
        );
        Files::makeDir($this->work);
        $this->store = $this->work . '/store';
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            Files::remove($path);
        }
    }

    /**
     * A project using the fixture repository only.
     *
     * @param array<string, string> $require
     * @param array<string, mixed>  $settings extra.composer-store
     * @param array<string, mixed>  $extra    other root `extra` keys, such as `patches`
     */
    protected function createProject(string $name, array $require, array $settings = [], array $extra = []): string
    {
        $dir = $this->work . '/' . $name;
        $manifest = [
            'name' => 'test/app',
            'type' => 'project',
            'repositories' => [
                // A file:// URL rather than a path: Composer before 2.3 only accepts URLs here.
                ['type' => 'composer', 'url' => FixtureRepository::fileUrl($this->env->repository)],
                ['packagist.org' => false],
            ],
            'require' => $require,
        ];
        if ($settings !== []) {
            $extra['composer-store'] = $settings;
        }
        if ($extra !== []) {
            $manifest['extra'] = $extra;
        }
        Files::writeJson($dir . '/composer.json', $manifest);

        return $dir;
    }

    /**
     * Runs Composer with the plugin installed globally, and returns its output.
     */
    protected function composer(string $project, string ...$args): string
    {
        return $this->succeeded($this->runComposer($project, array_values($args)));
    }

    /**
     * Runs Composer without the plugin, and returns its output.
     */
    protected function composerWithoutPlugin(string $project, string ...$args): string
    {
        return $this->succeeded($this->runComposer($project, array_values($args), plugin: false));
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env  extra environment variables
     */
    protected function runComposer(
        string $project,
        array $args,
        bool $plugin = true,
        array $env = [],
        bool $network = false,
    ): ProcessResult {
        return $this->startComposer($project, $args, $plugin, $env, $network)->wait();
    }

    /**
     * Starts Composer in the background, with the plugin installed globally.
     *
     * @param list<string>          $args
     * @param array<string, string> $env  extra environment variables
     */
    protected function startComposer(
        string $project,
        array $args,
        bool $plugin = true,
        array $env = [],
        bool $network = false,
    ): RunningProcess {
        return $this->env->composer->start($project, $args, $env + [
            'COMPOSER_HOME' => $plugin ? $this->env->pluginHome : $this->env->plainHome,
            'COMPOSER_CACHE_DIR' => $this->env->cache,
            'COMPOSER_STORE_DIR' => $this->store,
        ], $network);
    }

    /**
     * Runs PHP code in the project, with its autoloader loaded, and returns what it printed.
     */
    protected function php(string $project, string $code): string
    {
        $command = [PHP_BINARY, '-r', 'require "vendor/autoload.php"; ' . $code];
        $result = Process::run($command, $project, Process::environmentWithoutComposer());

        return $this->succeeded($result, stdoutOnly: true);
    }

    protected function storeEntry(string $package, string $version): string
    {
        $reference = $this->env->reference($package, $version);
        $entry = sprintf('%s/packages/%s/%s-%s', $this->store, $package, $version, substr($reference, 0, 12));
        $this->assertDirectoryExists($entry . '/files', "There is no store entry for {$package} {$version}");

        return $entry;
    }

    /**
     * vendor/<package> holds the same tree as the store entry, and every file is a hard link to the
     * store's copy except the ones in $copied.
     *
     * @param list<string> $copied
     */
    protected function assertLinkedFromStore(
        string $project,
        string $package,
        string $version,
        array $copied = [],
    ): void {
        $storeFiles = Files::entries($this->storeEntry($package, $version) . '/files');
        $vendor = $project . '/vendor/' . $package;
        $this->assertSame(
            array_keys($storeFiles),
            array_keys(Files::entries($vendor)),
            "vendor/{$package} differs from the store"
        );

        foreach ($storeFiles as $relative => $info) {
            if (!$info->isFile()) {
                $this->assertFalse(is_link($vendor . '/' . $relative), "vendor/{$package}/{$relative} is a symlink");
                continue;
            }
            $shared = fileinode($vendor . '/' . $relative) === $info->getInode();
            $expected = !in_array($relative, $copied, true);
            $this->assertSame($expected, $shared, sprintf(
                'vendor/%s/%s should %sbe a hard link to the store',
                $package,
                $relative,
                $expected ? '' : 'not '
            ));
        }
    }

    /**
     * Every file of the package in vendor/ is an ordinary file, as after a normal Composer install.
     */
    protected function assertNotLinked(string $project, string $package): void
    {
        $files = array_filter(Files::entries($project . '/vendor/' . $package), static fn ($info) => $info->isFile());
        $this->assertNotEmpty($files);
        foreach ($files as $relative => $info) {
            $message = "vendor/{$package}/{$relative} is a hard link";
            $this->assertSame(1, $this->linkCount($info->getPathname()), $message);
        }
    }

    protected function linkCount(string $file): int
    {
        $stat = stat($file);
        $this->assertIsArray($stat, "Cannot stat {$file}");

        return $stat['nlink'];
    }

    /**
     * A writable directory on another filesystem than the test's work dir, or the test is skipped.
     */
    protected function otherFilesystem(): string
    {
        $candidate = getenv('COMPOSER_STORE_TEST_OTHER_FS') ?: '/dev/shm';
        $candidateStat = is_dir($candidate) && is_writable($candidate) ? stat($candidate) : false;
        $workStat = stat($this->work);
        if ($candidateStat === false || $workStat === false || $candidateStat['dev'] === $workStat['dev']) {
            $this->markTestSkipped(
                "{$candidate} is not a writable directory on another filesystem; set COMPOSER_STORE_TEST_OTHER_FS"
            );
        }
        $dir = $candidate . '/composer-store-test-' . bin2hex(random_bytes(4));
        Files::makeDir($dir);
        $this->cleanup[] = $dir;

        return $dir;
    }

    protected function setRequirement(string $project, string $package, string $constraint): void
    {
        $manifest = Files::readJson($project . '/composer.json');
        $require = is_array($manifest['require'] ?? null) ? $manifest['require'] : [];
        $manifest['require'] = [$package => $constraint] + $require;
        Files::writeJson($project . '/composer.json', $manifest);
    }

    private function succeeded(ProcessResult $result, bool $stdoutOnly = false): string
    {
        $this->assertSame(0, $result->exitCode, $result->describe());

        return $stdoutOnly ? $result->stdout : $result->output();
    }
}
