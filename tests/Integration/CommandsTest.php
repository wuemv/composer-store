<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration;

use ComposerStore\Store\StoreEntry;
use ComposerStore\Store\StoreLock;
use ComposerStore\Tests\Support\Files;
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
        $this->assertSame('auto', $project['mode']);
        $this->assertTrue($project['links']);

        $text = $this->storeCommand($first, 'store:status')->stdout;
        $this->assertMatchesRegularExpression('{^Packages: +2 packages, 2 versions$}m', $text);
        $this->assertMatchesRegularExpression('{^Projects: +2 projects registered$}m', $text);
        $this->assertMatchesRegularExpression('{^This project: +links from the store$}m', $text);

        // Outside of a project, it only describes the store.
        Files::makeDir($this->work . '/elsewhere');
        $text = $this->storeCommand($this->work . '/elsewhere', 'store:status')->stdout;
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

        foreach (['store:status', 'store:verify', 'store:prune'] as $command) {
            $result = $this->storeCommand($dir, $command);
            $this->assertSame(0, $result->exitCode, $result->describe());
        }
        $this->assertDirectoryDoesNotExist($this->store, 'the commands do not create the store');
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
     * @return array<mixed>
     */
    private function json(ProcessResult $result): array
    {
        // Composer 2.0 prints PHP 8 deprecation notices on stdout before the command runs.
        $json = (string) preg_replace('{\A.*?^(?=\{$)}ms', '', $result->stdout);
        $data = json_decode($json, true);
        $this->assertIsArray($data, $result->describe());

        return $data;
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
