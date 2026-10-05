<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration;

use ComposerStore\Tests\Integration\Support\Environment;
use ComposerStore\Tests\Support\Files;
use ComposerStore\Tests\Support\Process;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A real Laravel app with every library linked from the store. Needs Packagist and GitHub, so it only
 * runs with `composer test:network`.
 *
 * Composer runs with your own configuration (home, cache, auth, proxies), and the plugin is installed
 * in the app rather than globally, so your Composer home is not changed.
 */
#[Group('network')]
final class LaravelTest extends TestCase
{
    private Environment $env;

    private string $work;

    protected function setUp(): void
    {
        $this->env = Environment::get();
        $this->work = $this->env->root . '/laravel-' . bin2hex(random_bytes(3));
        Files::makeDir($this->work);
    }

    public function testAFreshLaravelAppRunsFromTheStore(): void
    {
        $app = $this->work . '/app';
        $this->composer($this->work, 'create-project', 'laravel/laravel', $app, '--no-install', '--no-scripts');
        $this->requirePlugin($app);

        $output = $this->composer($app, 'update');
        $this->composer($app, 'run-script', 'post-root-package-install');
        $this->composer($app, 'run-script', 'post-create-project-cmd');

        $this->assertStringContainsString('Installing laravel/framework', $output);
        $this->assertGreaterThan(50, substr_count($output, 'Extracting archive into the store'));
        $framework = $app . '/vendor/laravel/framework/src/Illuminate/Foundation/Application.php';
        $this->assertSame(2, (int) (stat($framework)['nlink'] ?? 0), 'laravel/framework is not linked from the store');
        $this->assertChecksPass($app);

        // A second app installs from the first one's lock file: everything comes from the store.
        $second = $this->work . '/second';
        Files::makeDir($second);
        foreach (scandir($app) ?: [] as $entry) {
            if (!in_array($entry, ['.', '..', 'vendor'], true)) {
                is_dir($app . '/' . $entry)
                    ? Files::copyTree($app . '/' . $entry, $second . '/' . $entry)
                    : copy($app . '/' . $entry, $second . '/' . $entry);
            }
        }
        $output = $this->composer($second, 'install');

        $this->assertStringNotContainsString('Extracting archive into the store', $output);
        $this->assertGreaterThan(50, substr_count($output, 'Linking from store'));
        $this->assertSame(fileinode($framework), fileinode(str_replace($app, $second, $framework)));
        $this->assertChecksPass($second);

        // The store's own commands agree: every entry is intact, shared by both apps, and in use.
        $verify = $this->composer($second, 'store:verify');
        $this->assertMatchesRegularExpression('{Verified \d+ entries: \d+ ok, 0 changed, 0 invalid\n}', $verify);
        $status = $this->composer($second, 'store:status');
        $this->assertMatchesRegularExpression('{^Projects: +2 projects registered$}m', $status);
        $linking = '{^This project: +links from the store with (hard links|reflinks)$}m';
        $this->assertMatchesRegularExpression($linking, $status);
        $this->assertStringContainsString('Nothing to prune', $this->composer($second, 'store:prune'));
    }

    /**
     * Adds the plugin to the app's composer.json, from the copy the integration environment made.
     */
    private function requirePlugin(string $app): void
    {
        $manifest = Files::readJson($app . '/composer.json');
        $require = is_array($manifest['require'] ?? null) ? $manifest['require'] : [];
        $config = is_array($manifest['config'] ?? null) ? $manifest['config'] : [];
        $allowed = is_array($config['allow-plugins'] ?? null) ? $config['allow-plugins'] : [];

        $manifest['repositories'] = [
            ['type' => 'path', 'url' => $this->env->plugin, 'options' => ['symlink' => false]],
        ];
        $manifest['require'] = [Environment::PLUGIN_NAME => '1.0.0'] + $require;
        $manifest['config'] = ['allow-plugins' => [Environment::PLUGIN_NAME => true] + $allowed] + $config;
        Files::writeJson($app . '/composer.json', $manifest);
    }

    private function assertChecksPass(string $app): void
    {
        $checks = [
            ['artisan', 'about'],
            ['artisan', 'package:discover'],
            ['artisan', 'test'],
            ['artisan', 'vendor:publish', '--tag=laravel-errors', '--force'],
            ['vendor/bin/phpunit'],
            ['vendor/bin/pint', '--test'],
        ];
        foreach ($checks as $check) {
            $result = Process::run([PHP_BINARY, ...$check], $app, getenv());
            $this->assertSame(0, $result->exitCode, $result->describe());
        }
    }

    private function composer(string $cwd, string ...$args): string
    {
        $env = ['COMPOSER_STORE_DIR' => $this->work . '/store'];
        foreach (['COMPOSER_HOME', 'COMPOSER_CACHE_DIR', 'COMPOSER_AUTH'] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $env[$name] = $value;
            }
        }
        $result = $this->env->composer->run($cwd, array_values($args), $env, network: true);
        $this->assertSame(0, $result->exitCode, $result->describe());

        // Windows ends lines with \r\n.
        return str_replace("\r\n", "\n", $result->output());
    }
}
