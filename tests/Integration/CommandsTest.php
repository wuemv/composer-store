<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration;

use ComposerStore\Link\CloneInfo;
use ComposerStore\Store\StoreEntry;
use ComposerStore\Store\StoreLock;
use ComposerStore\Tests\Support\Files;
use ComposerStore\Tests\Support\Process;
use ComposerStore\Tests\Support\ProcessResult;

final class CommandsTest extends IntegrationTestCase
{
    public function testStatusShowsTheStoreAndTheSpaceTheLinksSave(): void
    {
        $require = ['acme/alpha' => '1.0.0', 'acme/gamma' => '1.0.0'];
        $first = $this->createProject('first', $require);
        $second = $this->createProject('second', $require);
        $this->composer($first, 'install');
        $this->composer($second, 'install');

        $status = $this->json($this->storeCommand($first, 'store:status', '--format=json'));

        $files = 0;
        foreach (['acme/alpha', 'acme/gamma'] as $package) {
            $entries = Files::entries($this->storeEntry($package, '1.0.0') . '/files');
            $files += count(array_filter($entries, static fn (\SplFileInfo $info): bool => $info->isFile()));
        }
        $this->assertSame(realpath($this->store), realpath($this->string($status['store'])));
        $this->assertSame(2, $status['entries']);
        $this->assertSame(2, $status['packages']);
        $this->assertSame($files, $status['files']);
        $this->assertSame($files, $status['linked-files']);
        $this->assertSame(2 * $files, $status['links'], 'each file is linked from both projects');
        $this->assertSame(2 * $this->int($status['bytes']), $status['saved-bytes']);
        $this->assertSame(2, $status['projects']);
        $this->assertSame(0, $status['missing-projects']);
        $this->assertSame(0, $status['temp-dirs']);
        $project = $status['project'];
        $this->assertIsArray($project);
        $this->assertSame(realpath($first), $project['dir']);
        $this->assertSame('hardlink', $project['mode']);
        $this->assertSame('hardlink', $project['method']);
        $this->assertTrue($project['links']);

        $text = self::text($this->storeCommand($first, 'store:status'));
        $this->assertMatchesRegularExpression('{^Packages: +2 packages, 2 versions$}m', $text);
        $this->assertMatchesRegularExpression('{^Projects: +2 projects registered$}m', $text);
        $this->assertMatchesRegularExpression('{^This project: +links from the store with hard links$}m', $text);

        // Outside of a project, it only describes the store.
        Files::makeDir($this->work . '/elsewhere');
        $text = self::text($this->storeCommand($this->work . '/elsewhere', 'store:status'));
        $this->assertMatchesRegularExpression('{^Store: +\S+$}m', $text);
        $this->assertStringNotContainsString('This project', $text);
    }

    public function testStatusTellsWhenAProjectDoesNotLinkFromTheStore(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0'], ['mode' => 'copy']);

        $status = $this->json($this->storeCommand($project, 'store:status', '--format=json'));

        $this->assertFalse($status['exists']);
        $this->assertSame(0, $status['entries']);
        $this->assertIsArray($status['project']);
        $this->assertFalse($status['project']['links']);
        $this->assertSame('mode is copy', $status['project']['reason']);
    }

    public function testInstallsRegisterTheirProject(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0']);
        $this->composer($project, 'install');

        $registry = Files::readJson($this->store . '/projects.json');

        $this->assertIsArray($registry['projects'] ?? null);
        $this->assertSame([realpath($project)], array_keys($registry['projects']));
        $details = $registry['projects'][realpath($project)];
        $this->assertIsArray($details);
        $this->assertSame(realpath($project . '/vendor'), realpath($this->string($details['vendor-dir'])));
    }

    public function testVerifyFindsStoreFilesEditedThroughVendor(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0', 'acme/gamma' => '1.0.0']);
        $this->composer($project, 'install');

        $result = $this->storeCommand($project, 'store:verify');
        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString('Verified 2 entries', $result->stdout);
        $this->assertStringContainsString('2 ok, 0 changed, 0 invalid', $result->stdout);

        // An edit in place goes through the hard link into the store, and from there into every project.
        $entry = $this->storeEntry('acme/alpha', '1.0.0');
        $file = $project . '/vendor/acme/alpha/src/Alpha.php';
        file_put_contents($file, "\n// local debugging\n", FILE_APPEND);
        $createdAt = strtotime($this->string((StoreEntry::readMeta($entry) ?? [])['created_at'] ?? ''));
        touch($file, (int) $createdAt + 60);

        $result = $this->storeCommand($project, 'store:verify');
        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertStringContainsString('acme/alpha 1.0.0: changed', $result->stdout);
        $this->assertStringContainsString('modified: src/Alpha.php', $result->stdout);
        $this->assertStringContainsString($entry, $result->stdout);
        $this->assertStringContainsString('1 ok, 1 changed, 0 invalid', $result->stdout);
        $this->assertStringContainsString('composer reinstall', $result->stdout);

        $report = $this->json($this->storeCommand($project, 'store:verify', '--format=json'));
        $this->assertSame(1, $report['changed']);
        $this->assertIsArray($report['entries']);
        $statuses = array_column($report['entries'], 'status', 'name');
        $this->assertSame(['acme/alpha' => 'changed', 'acme/gamma' => 'ok'], $statuses);

        // Package arguments limit what is verified.
        $result = $this->storeCommand($project, 'store:verify', 'acme/g*');
        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString('Verified 1 entry', $result->stdout);

        // Missing metadata makes an entry invalid: installs do not use it.
        unlink($this->storeEntry('acme/gamma', '1.0.0') . '/' . StoreEntry::META_FILE);
        $result = $this->storeCommand($project, 'store:verify', 'acme/gamma');
        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertStringContainsString('invalid', $result->stdout);
        $this->assertStringContainsString('.store-meta.json is missing or unreadable', $result->stdout);
    }

    public function testPruneDeletesWhatNoProjectUses(): void
    {
        $app = $this->createProject('app', ['acme/alpha' => '1.0.0']);
        $this->composer($app, 'install');
        $this->setRequirement($app, 'acme/alpha', '1.1.0');
        $this->composer($app, 'update');
        $gone = $this->createProject('gone', ['acme/gamma' => '1.0.0']);
        $this->composer($gone, 'install');
        $goneDir = (string) realpath($gone);
        Files::remove($gone);
        $leftover = $this->store . '/tmp/0123456789abcdef';
        Files::makeDir($leftover . '/files');
        file_put_contents($leftover . '/files/partial.php', '<?php');

        $alphaOld = $this->storeEntry('acme/alpha', '1.0.0');
        $alphaNew = $this->storeEntry('acme/alpha', '1.1.0');
        $gamma = $this->storeEntry('acme/gamma', '1.0.0');

        // A dry run lists what is unused, and deletes nothing.
        $result = $this->storeCommand($app, 'store:prune');
        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString('acme/alpha 1.0.0', $result->stdout);
        $this->assertStringContainsString('acme/gamma 1.0.0', $result->stdout);
        $this->assertStringNotContainsString('acme/alpha 1.1.0', $result->stdout);
        $this->assertStringContainsString($leftover, $result->stdout);
        $this->assertStringContainsString($goneDir, $result->stdout);
        $this->assertStringContainsString('store:prune --force', $result->stdout);
        $this->assertDirectoryExists($alphaOld);
        $this->assertDirectoryExists($gamma);
        $this->assertDirectoryExists($leftover);

        $plan = $this->json($this->storeCommand($app, 'store:prune', '--format=json'));
        $this->assertFalse($plan['deleted']);
        $this->assertIsArray($plan['entries']);
        $this->assertSame(['acme/alpha', 'acme/gamma'], array_column($plan['entries'], 'name'));
        $this->assertSame([$goneDir], $plan['missing-projects']);

        $result = $this->storeCommand($app, 'store:prune', '--force');
        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString('Deleted 2 unused entries and 1 temp dir', $result->stdout);
        $this->assertStringContainsString('forgot 1 project', $result->stdout);
        $this->assertDirectoryDoesNotExist($alphaOld);
        $this->assertDirectoryDoesNotExist(dirname($gamma), 'the empty package directory is removed too');
        $this->assertDirectoryDoesNotExist($leftover);
        $this->assertDirectoryExists($alphaNew);
        $this->assertSame([], glob($this->store . '/tmp/*'));
        $registry = Files::readJson($this->store . '/projects.json');
        $this->assertIsArray($registry['projects'] ?? null);
        $this->assertSame([realpath($app)], array_keys($registry['projects']));

        // The project is untouched, and the next prune has nothing to do.
        $this->assertLinkedFromStore($app, 'acme/alpha', '1.1.0');
        $this->assertSame('1.1.0', $this->php($app, 'echo Acme\Alpha\Alpha::VERSION;'));
        $result = $this->storeCommand($app, 'store:prune', '--force');
        $this->assertStringContainsString('Nothing to prune', $result->stdout);
    }

    public function testPruneKeepsEntriesThatRegisteredProjectsCopied(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0']);
        $this->composer($project, 'install');
        // Replace the links with copies, as a copy fallback or a reflink would leave them.
        $vendor = $project . '/vendor/acme/alpha';
        Files::copyTree($vendor, $vendor . '.copy');
        Files::remove($vendor);
        rename($vendor . '.copy', $vendor);
        $entry = $this->storeEntry('acme/alpha', '1.0.0');
        $this->assertSame(1, $this->linkCount($entry . '/files/src/Alpha.php'));

        $result = $this->storeCommand($project, 'store:prune', '--force');

        $this->assertStringContainsString('Nothing to prune', $result->stdout);
        $this->assertDirectoryExists($entry);
    }

    public function testPruneWaitsForRunningInstallsAndDeletesNothingWithoutTheLock(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0']);
        $this->composer($project, 'install');
        $this->composer($project, 'remove', 'acme/alpha');
        $entry = $this->storeEntry('acme/alpha', '1.0.0');

        $install = new StoreLock($this->store . '/.lock');
        $this->assertTrue($install->acquireShared(0));
        try {
            $env = ['COMPOSER_STORE_LOCK_TIMEOUT' => '1'];
            $result = $this->runComposer($project, ['store:prune', '--force'], env: $env);
        } finally {
            $install->release();
        }

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertStringContainsString('Waiting up to 1 seconds for running Composer installs', $result->stderr);
        $this->assertStringContainsString('Could not lock the store', $result->stderr);
        $this->assertStringContainsString('nothing was deleted', $result->stderr);
        $this->assertDirectoryExists($entry);

        $result = $this->storeCommand($project, 'store:prune', '--force');
        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertDirectoryDoesNotExist($entry);
    }

    public function testCommandsWorkOnAStoreThatDoesNotExistYet(): void
    {
        Files::makeDir($this->work . '/elsewhere');
        $dir = $this->work . '/elsewhere';

        $commands = [['store:status'], ['store:verify'], ['store:prune'], ['store:monitor', $dir, '--once']];
        foreach ($commands as $command) {
            $result = $this->storeCommand($dir, ...$command);
            $this->assertSame(0, $result->exitCode, $result->describe());
        }
        $this->assertDirectoryDoesNotExist($this->store, 'the commands do not create the store');
    }

    public function testMonitorMeasuresWhatEachProjectTakesOnDisk(): void
    {
        if (PHP_OS_FAMILY === 'Darwin' && CloneInfo::load() === null) {
            $this->markTestSkipped('On macOS, telling copies from clones needs the FFI extension');
        }
        $require = ['acme/alpha' => '1.0.0', 'acme/gamma' => '1.0.0'];
        $this->composer($this->createProject('sites/first', $require), 'install');
        $this->composer($this->createProject('sites/second', $require), 'install');
        $this->composerWithoutPlugin($this->createProject('sites/copied', $require), 'install');

        $sites = $this->work . '/sites';
        $monitor = $this->json($this->storeCommand($this->work, 'store:monitor', $sites, '--format=json'));

        $this->assertIsArray($monitor['projects']);
        $projects = [];
        foreach ($monitor['projects'] as $project) {
            $this->assertIsArray($project);
            $projects[basename($this->string($project['dir']))] = $project;
        }
        $this->assertSame(['copied', 'first', 'second'], array_keys($projects));
        foreach (['first', 'second'] as $name) {
            $this->assertSame(2, $projects[$name]['from-store']);
            $this->assertSame('hardlink', $projects[$name]['linked-by']);
            $this->assertLessThan($projects[$name]['vendor-bytes'], $projects[$name]['own-bytes']);
        }
        $this->assertSame(0, $projects['copied']['from-store'], 'installed without the plugin');
        $this->assertSame($projects['copied']['vendor-bytes'], $projects['copied']['own-bytes']);
        $this->assertIsArray($monitor['store']);
        $this->assertIsArray($monitor['totals']);
        $this->assertSame(2, $monitor['store']['entries']);
        $this->assertGreaterThan(0, $monitor['store']['used-bytes']);
        $this->assertSame(
            $monitor['store']['used-bytes'],
            $monitor['totals']['saved-bytes'],
            'the two linked projects share one copy of each version, the third has its own'
        );

        $text = self::text($this->storeCommand($this->work, 'store:monitor', $sites, '--once'));
        $this->assertMatchesRegularExpression('{^first +2 +2 \(hard links\) }m', $text);
        $this->assertMatchesRegularExpression('{^copied +2 +0 }m', $text);
        $this->assertStringContainsString('Saved: ', $text);
    }

    public function testDashboardServesLiveMeasurementsOnlyWhileItRuns(): void
    {
        $this->composer($this->createProject('sites/first', ['acme/alpha' => '1.0.0']), 'install');
        $args = ['store:dashboard', $this->work . '/sites', '--no-open', '--interval=0.5'];
        $server = $this->startComposer($this->work, $args);
        try {
            $url = '';
            for ($i = 0; $i < 300 && $url === ''; $i++) {
                if (preg_match('{http://127\.0\.0\.1:\d+/}', $server->output(), $match) === 1) {
                    $url = $match[0];
                } else {
                    usleep(100_000);
                }
            }
            $this->assertNotSame('', $url, 'the dashboard prints its address');

            $data = $this->dashboardData($url);
            $this->assertIsArray($data['snapshot']);
            $this->assertIsArray($data['snapshot']['projects']);
            $this->assertCount(1, $data['snapshot']['projects']);
            $this->assertIsArray($data['snapshot']['projects'][0]);
            $this->assertSame(1, $data['snapshot']['projects'][0]['from-store']);
            $page = (string) file_get_contents($url);
            $this->assertStringContainsString('<title>composer-store dashboard</title>', $page);

            $samples = count($this->list($data['history']));
            $later = $samples;
            for ($i = 0; $i < 100 && $later === $samples; $i++) {
                usleep(100_000);
                $later = count($this->list($this->dashboardData($url)['history']));
            }
            $this->assertGreaterThan($samples, $later, 'it keeps measuring while it runs');
        } finally {
            $server->stop();
        }
        $this->assertFalse(@file_get_contents($url . 'data.json'), 'and stops with the command');
    }

    public function testWithoutADirectoryTheMonitorTakesWhatItWouldSuggest(): void
    {
        $sites = $this->work . '/sites';
        Files::makeDir($sites);
        Files::makeDir($this->work . '/home');
        $json = ['store:monitor', '--format=json'];

        // No terminal to ask in: the suggestion. Without ~/Developer, the current directory.
        $result = $this->runComposer($sites, $json, env: ['HOME' => $this->work . '/home']);
        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(realpath($sites), $this->json($result)['dir']);

        Files::makeDir($this->work . '/home/Developer');
        $result = $this->runComposer($sites, $json, env: ['HOME' => $this->work . '/home']);
        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(realpath($this->work . '/home/Developer'), $this->json($result)['dir']);
    }

    public function testAGlobalInstallAddsAComposerStoreCommand(): void
    {
        $command = $this->env->pluginHome . '/vendor/bin/composer-store';
        $this->assertFileExists($command, "Composer links the plugin's bin into its global vendor/bin");

        $result = Process::run([PHP_BINARY, $command, 'status', '--format=json'], $this->work, [
            'COMPOSER_HOME' => $this->env->pluginHome,
            'COMPOSER_CACHE_DIR' => $this->env->cache,
            'COMPOSER_STORE_DIR' => $this->store,
            'COMPOSER_STORE_COMPOSER' => $this->env->composer->binary(),
        ] + getenv());

        $this->assertSame(0, $result->exitCode, $result->describe());
        // The store does not exist yet: compare the directory it would be in.
        $store = $this->string($this->json($result)['store']);
        $this->assertSame('store', basename($store));
        $this->assertSame(realpath($this->work), realpath(dirname($store)));
    }

    public function testMonitorRejectsADirectoryThatDoesNotExist(): void
    {
        $result = $this->runComposer($this->work, ['store:monitor', $this->work . '/missing', '--once']);

        $this->assertSame(1, $result->exitCode);
        $this->assertStringContainsString('is not a directory', $result->stderr);
    }

    public function testUnknownFormatsAreRejected(): void
    {
        $result = $this->runComposer($this->work, ['store:status', '--format=yaml']);

        $this->assertNotSame(0, $result->exitCode);
        $this->assertStringContainsString('Unknown format "yaml"', $result->stderr);
    }

    private function storeCommand(string $dir, string $command, string ...$args): ProcessResult
    {
        return $this->runComposer($dir, [$command, ...array_values($args)]);
    }

    /**
     * Stdout with \n line endings, for patterns anchored at line ends: Windows ends lines with \r\n.
     */
    private static function text(ProcessResult $result): string
    {
        return str_replace("\r\n", "\n", $result->stdout);
    }

    /**
     * @return array<mixed>
     */
    private function dashboardData(string $url): array
    {
        $data = json_decode((string) file_get_contents($url . 'data.json'), true);
        $this->assertIsArray($data);

        return $data;
    }

    /**
     * @return array<mixed>
     */
    private function list(mixed $value): array
    {
        $this->assertIsArray($value);

        return $value;
    }

    private function string(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }

    private function int(mixed $value): int
    {
        $this->assertIsInt($value);

        return $value;
    }
}
