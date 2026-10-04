<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Store;

use ComposerStore\Store\Pruner;
use ComposerStore\Store\Store;
use ComposerStore\Store\StoreEntry;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\TestCase;

final class PrunerTest extends TestCase
{
    use PublishesEntries;

    private const LINKED = '1111111111111111111111111111111111111111';
    private const LISTED = '2222222222222222222222222222222222222222';
    private const UNUSED = '3333333333333333333333333333333333333333';

    private string $root;

    private Store $store;

    protected function setUp(): void
    {
        $this->root = Files::tempDir('pruner-test');
        $this->store = new Store($this->root . '/store');
    }

    protected function tearDown(): void
    {
        Files::remove($this->root);
    }

    public function testEntriesLinkedFromAVendorDirectoryAreKept(): void
    {
        $entry = $this->publishEntry($this->store, 'acme/linked', '1.0.0', self::LINKED);
        Files::makeDir($this->root . '/vendor');
        $this->assertTrue(link($entry->filesDir() . '/src/Foo.php', $this->root . '/vendor/Foo.php'));
        $unused = $this->publishEntry($this->store, 'acme/unused', '1.0.0', self::UNUSED);

        $plan = (new Pruner($this->store))->plan();

        $this->assertSame([$unused->path], self::paths($plan->entries));
    }

    public function testEntriesListedByARegisteredProjectAreKeptEvenWithoutLinks(): void
    {
        $listed = $this->publishEntry($this->store, 'acme/listed', 'v2.0.0', self::LISTED);
        $unused = $this->publishEntry($this->store, 'acme/unused', '1.0.0', self::UNUSED);
        $this->registerProject('app', [
            ['name' => 'acme/listed', 'version' => 'v2.0.0', 'dist' => ['reference' => self::LISTED]],
            // Same package, other version: does not keep acme/unused 1.0.0.
            ['name' => 'acme/unused', 'version' => '1.0.1', 'dist' => ['reference' => self::UNUSED]],
            ['name' => 'acme/source-only', 'version' => '1.0.0', 'source' => ['reference' => self::UNUSED]],
        ]);

        $plan = (new Pruner($this->store))->plan();

        $this->assertSame([$unused->path], self::paths($plan->entries));
        $this->assertDirectoryExists($listed->path);
    }

    public function testComposer1InstalledJsonIsUnderstood(): void
    {
        $this->publishEntry($this->store, 'acme/listed', '1.0.0', self::LISTED);
        $vendor = $this->root . '/old-app/vendor';
        Files::writeJson($vendor . '/composer/installed.json', [
            ['name' => 'acme/listed', 'version' => '1.0.0', 'dist' => ['reference' => self::LISTED]],
        ]);
        $this->store->projects()->register($this->root . '/old-app', $vendor);

        $this->assertSame([], (new Pruner($this->store))->plan()->entries);
    }

    public function testTempDirsAndProjectsThatAreGoneArePlannedToo(): void
    {
        $this->store->initialize();
        $temp = $this->store->createTempDir();
        file_put_contents($temp . '/partial.php', str_repeat('x', 5000));
        $this->registerProject('app', []);
        $this->store->projects()->register($this->root . '/deleted', $this->root . '/deleted/vendor');

        $plan = (new Pruner($this->store))->plan();

        $this->assertSame([], $plan->entries);
        $this->assertSame([$temp], $plan->tempDirs);
        $this->assertSame([$this->root . '/deleted'], $plan->missingProjects);
        $this->assertGreaterThanOrEqual(5000, $plan->bytes());
        $this->assertFalse($plan->isEmpty());
    }

    public function testPruneDeletesThePlanAndTheDirectoriesItEmpties(): void
    {
        $linked = $this->publishEntry($this->store, 'acme/linked', '1.0.0', self::LINKED);
        Files::makeDir($this->root . '/vendor');
        $this->assertTrue(link($linked->filesDir() . '/src/Foo.php', $this->root . '/vendor/Foo.php'));
        $unused = $this->publishEntry($this->store, 'acme/unused', '1.0.0', self::UNUSED);
        $otherVersion = $this->publishEntry($this->store, 'acme/linked', '0.9.0', self::UNUSED);
        $temp = $this->store->createTempDir();
        $this->store->projects()->register($this->root . '/deleted', $this->root . '/deleted/vendor');
        $pruner = new Pruner($this->store);

        $failures = $pruner->prune($pruner->plan());

        $this->assertSame([], $failures);
        $this->assertDirectoryDoesNotExist($this->root . '/store/packages/acme/unused');
        $this->assertDirectoryDoesNotExist($otherVersion->path);
        $this->assertDirectoryExists($linked->path);
        $this->assertDirectoryDoesNotExist($temp);
        $this->assertSame([], $this->store->tempDirs(), 'deleted entries pass through tmp/');
        $this->assertSame([], $this->store->projects()->projects());
        $this->assertSame('<?php', file_get_contents($this->root . '/vendor/Foo.php'));
        $this->assertTrue($pruner->plan()->isEmpty());
        $this->assertSame([], (new Pruner($this->store))->prune($pruner->plan()));
        $this->assertDirectoryDoesNotExist($unused->path);
    }

    public function testPruneReportsWhatItCouldNotDelete(): void
    {
        $unused = $this->publishEntry($this->store, 'acme/unused', '1.0.0', self::UNUSED);
        $pruner = new Pruner($this->store);
        $plan = $pruner->plan();
        // Gone before the prune runs, so moving it out of packages/ fails.
        Files::remove($unused->path);

        $failures = $pruner->prune($plan);

        $this->assertCount(1, $failures);
        $this->assertStringContainsString($unused->path, $failures[0]);
    }

    /**
     * @param list<array<string, mixed>> $packages installed.json records
     */
    private function registerProject(string $name, array $packages): void
    {
        $vendor = $this->root . '/' . $name . '/vendor';
        Files::writeJson($vendor . '/composer/installed.json', ['packages' => $packages, 'dev' => true]);
        $this->store->projects()->register($this->root . '/' . $name, $vendor);
    }

    /**
     * @param list<array{entry: StoreEntry, stats: mixed}> $unused
     *
     * @return list<string>
     */
    private static function paths(array $unused): array
    {
        return array_map(static fn (array $item): string => $item['entry']->path, $unused);
    }
}
