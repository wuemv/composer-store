<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration;

use ComposerStore\Tests\Support\Files;
use ComposerStore\Tests\Support\Process;
use ComposerStore\Tests\Support\ProcessResult;

/**
 * install.php, run against a Composer home of its own. The plugin comes from the copy in the test
 * environment (--from=DIR) and Packagist is turned off, so the tests stay offline.
 */
final class InstallScriptTest extends IntegrationTestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = $this->work . '/home';
        Files::writeJson($this->home . '/composer.json', ['repositories' => [['packagist.org' => false]]]);
    }

    public function testInstallsThePluginGloballyAndChecksThatComposerLoadsIt(): void
    {
        $result = $this->runScript(['--from=' . $this->env->plugin]);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $allow = '> composer global config allow-plugins.wuemv/composer-store true';
        $this->assertStringContainsString($allow, $result->stdout);
        $this->assertStringContainsString('> composer store:status', $result->stdout);
        $this->assertStringContainsString('composer-store is installed.', $result->stdout);
        $manifest = Files::readJson($this->home . '/composer.json');
        $this->assertSame(['wuemv/composer-store' => '@dev'], $manifest['require'] ?? null);
        $config = is_array($manifest['config'] ?? null) ? $manifest['config'] : [];
        $this->assertSame(['wuemv/composer-store' => true], $config['allow-plugins'] ?? null);
        $repositories = is_array($manifest['repositories'] ?? null) ? $manifest['repositories'] : [];
        $repository = ['type' => 'path', 'url' => realpath($this->env->plugin)];
        $this->assertSame($repository, $repositories['composer-store'] ?? null);

        // Projects now go through the store.
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0']);
        $install = $this->env->composer->run($project, ['install'], $this->environment());
        $this->assertSame(0, $install->exitCode, $install->describe());
        $this->assertStringContainsString('acme/alpha (1.0.0): Extracting archive into the store', $install->stderr);
    }

    public function testADryRunPrintsTheCommandsWithoutRunningThem(): void
    {
        $before = (string) file_get_contents($this->home . '/composer.json');

        $result = $this->runScript(['--dry-run']);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $commands = [
            'composer global config repositories.composer-store vcs https://github.com/wuemv/composer-store',
            'composer global config allow-plugins.wuemv/composer-store true',
            'composer global require --no-interaction wuemv/composer-store:@dev',
        ];
        foreach ($commands as $command) {
            $this->assertStringContainsString('> ' . $command . "\n", $result->stdout);
        }
        $this->assertStringContainsString('Dry run: nothing was changed.', $result->stdout);
        $this->assertSame($before, file_get_contents($this->home . '/composer.json'));
    }

    public function testFromPackagistDropsTheGitHubRepository(): void
    {
        $result = $this->runScript(['--from=packagist', '--dry-run']);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $output = $result->stdout;
        $this->assertStringContainsString("> composer global config --unset repositories.composer-store\n", $output);
        $this->assertStringContainsString("> composer global require --no-interaction wuemv/composer-store\n", $output);
    }

    public function testUnknownOptionsAndSourcesAreRefused(): void
    {
        $unknown = $this->runScript(['--fast']);
        $this->assertSame(2, $unknown->exitCode);
        $this->assertStringContainsString('Unknown option: --fast', $unknown->stderr);
        $this->assertStringContainsString('Usage: php install.php [options]', $unknown->stderr);

        $nowhere = $this->runScript(['--from=' . $this->work . '/nowhere']);
        $this->assertSame(1, $nowhere->exitCode);
        $refusal = '--from takes github, packagist, a repository URL or a directory';
        $this->assertStringContainsString($refusal, $nowhere->stderr);
    }

    /**
     * @param list<string> $args
     */
    private function runScript(array $args): ProcessResult
    {
        $script = dirname(__DIR__, 2) . '/install.php';
        $command = [PHP_BINARY, $script, ...$args, '--composer=' . $this->env->composer->binary()];

        return Process::run($command, $this->work, $this->environment() + Process::environmentWithoutComposer());
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        return [
            'COMPOSER_HOME' => $this->home,
            'COMPOSER_CACHE_DIR' => $this->env->cache,
            'COMPOSER_STORE_DIR' => $this->store,
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'COMPOSER_NO_INTERACTION' => '1',
            'COMPOSER_NO_AUDIT' => '1',
        ];
    }
}
