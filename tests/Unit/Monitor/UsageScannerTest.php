<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Monitor;

use ComposerStore\Link\CloneFile;
use ComposerStore\Link\CloneInfo;
use ComposerStore\Link\Method;
use ComposerStore\Monitor\ProjectUsage;
use ComposerStore\Monitor\UsageScanner;
use ComposerStore\Store\Store;
use ComposerStore\Store\StoreEntry;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;

/**
 * Projects under sites/ with acme/alpha placed from the store (hard links, or clones on macOS with
 * FFI), acme/beta copied although the store holds it, and acme/gamma installed from source.
 */
final class UsageScannerTest extends TestCase
{
    private const ALPHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const BETA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $dir;

    private Store $store;

    protected function setUp(): void
    {
        $this->dir = Files::tempDir('usage-scanner-test');
        $this->store = new Store($this->dir . '/store');
        $this->storeEntry('acme/alpha', self::ALPHA, ['composer.json' => '{}', 'src/A.php' => str_repeat('a', 9000)]);
        $this->storeEntry('acme/beta', self::BETA, ['composer.json' => '{}', 'src/B.php' => str_repeat('b', 20000)]);
    }

    protected function tearDown(): void
    {
        Files::remove($this->dir);
    }

    public function testHardLinkedPackagesComeFromTheStoreAndTakeNothingOfTheirOwn(): void
    {
        $project = $this->project('shop', [self::class, 'hardLinkTree']);

        $snapshot = (new UsageScanner($this->store, null, 'Linux'))->snapshot($this->dir . '/sites');

        $this->assertCount(1, $snapshot->projects);
        $usage = $snapshot->projects[0];
        $this->assertSame($project, $usage->dir);
        $this->assertSame($project . '/vendor', $usage->vendorDir);
        $this->assertSame(3, $usage->packages);
        $this->assertSame(1, $usage->fromStore, 'alpha only: beta is a copy, gamma came from source');
        $this->assertSame('hardlink', $usage->linkedBy());
        $this->assertSame([$this->alphaEntry()], $usage->entries);
        $this->assertSame(self::bytes($project . '/vendor'), $usage->vendorBytes);
        $this->assertSame($usage->vendorBytes - self::bytes($project . '/vendor/acme/alpha'), $usage->ownBytes);

        $this->assertSame(2, $snapshot->storeEntries);
        $this->assertSame(
            self::bytes($this->alphaEntry() . '/files') + self::bytes($this->betaEntry() . '/files'),
            $snapshot->storeBytes
        );
        $this->assertSame(self::bytes($this->alphaEntry() . '/files'), $snapshot->storeUsedBytes);
        $this->assertSame(0, $snapshot->savedBytes(), 'one project shares its store data with nobody yet');
        $this->assertTrue($snapshot->sameFilesystem);
        $this->assertNotNull($snapshot->freeBytes);
    }

    public function testEachProjectThatLinksTheSameVersionSavesACopyOfIt(): void
    {
        $this->project('shop', [self::class, 'hardLinkTree']);
        $this->project('blog', [self::class, 'hardLinkTree']);

        $snapshot = (new UsageScanner($this->store, null, 'Linux'))->snapshot($this->dir . '/sites');

        $names = array_map(static fn (ProjectUsage $usage): string => basename($usage->dir), $snapshot->projects);
        $this->assertSame(['blog', 'shop'], $names);
        $this->assertSame(2, $snapshot->fromStore());
        $this->assertSame(6, $snapshot->packages());
        $this->assertSame(self::bytes($this->alphaEntry() . '/files'), $snapshot->savedBytes());
        $this->assertSame($snapshot->vendorBytes() - $snapshot->savedBytes(), $snapshot->withStoreBytes());
    }

    public function testOnMacOsWithoutFfiClonesCannotBeToldFromCopies(): void
    {
        $this->project('shop', [self::class, 'hardLinkTree']);

        $snapshot = (new UsageScanner($this->store, null, 'Darwin'))->snapshot($this->dir . '/sites');

        $usage = $snapshot->projects[0];
        $this->assertNull($usage->fromStore, 'beta could be a clone');
        $this->assertNull($usage->ownBytes);
        $this->assertGreaterThan(0, $usage->vendorBytes);
        $this->assertNull($snapshot->storeUsedBytes);
        $this->assertNull($snapshot->withStoreBytes());
        $this->assertNull($snapshot->savedBytes());
    }

    public function testOnLinuxAProjectThatClonedWithReflinksHidesItsClones(): void
    {
        $project = $this->project('shop', [self::class, 'copyTree']);
        $this->store->projects()->register((string) realpath($project), $project . '/vendor', Method::Reflink);

        $snapshot = (new UsageScanner($this->store, null, 'Linux'))->snapshot($this->dir . '/sites');

        $this->assertNull($snapshot->projects[0]->fromStore);
        $this->assertNull($snapshot->projects[0]->ownBytes);
    }

    public function testAProjectInstalledWithoutTheStoreHoldsCopies(): void
    {
        $project = $this->project('shop', [self::class, 'copyTree']);

        $snapshot = (new UsageScanner($this->store, null, 'Linux'))->snapshot($this->dir . '/sites');

        $this->assertSame(0, $snapshot->projects[0]->fromStore);
        $this->assertNull($snapshot->projects[0]->linkedBy());
        $this->assertSame(self::bytes($project . '/vendor'), $snapshot->projects[0]->ownBytes);
        $this->assertSame(0, $snapshot->storeUsedBytes);
        $this->assertSame(0, $snapshot->savedBytes());
    }

    #[RequiresOperatingSystem('Darwin')]
    public function testClonedPackagesComeFromTheStore(): void
    {
        $clones = CloneInfo::load() ?? $this->markTestSkipped('needs the FFI extension');
        $cloner = CloneFile::load() ?? $this->markTestSkipped('needs the FFI extension');
        $project = $this->project('shop', static function (string $from, string $to) use ($cloner): void {
            Files::makeDir(dirname($to));
            $cloner->clone($from, $to);
        });

        $snapshot = (new UsageScanner($this->store, $clones))->snapshot($this->dir . '/sites');

        $usage = $snapshot->projects[0];
        $this->assertSame(1, $usage->fromStore);
        $this->assertSame([Method::Reflink], $usage->methods);
        $this->assertSame($usage->vendorBytes - self::bytes($project . '/vendor/acme/alpha'), $usage->ownBytes);
        $this->assertTrue($snapshot->clonesVisible);
    }

    public function testForgetsStoreEntriesThatAreGone(): void
    {
        $scanner = new UsageScanner($this->store, null, 'Linux');
        $this->assertSame(2, $scanner->snapshot($this->dir)->storeEntries);

        Files::remove($this->betaEntry());
        $snapshot = $scanner->snapshot($this->dir);

        $this->assertSame(1, $snapshot->storeEntries);
        $this->assertSame(self::bytes($this->alphaEntry() . '/files'), $snapshot->storeBytes);
    }

    public function testReusesTheFiguresOfAnUnchangedProjectUntilItIsDueForARefresh(): void
    {
        $project = $this->project('shop', [self::class, 'hardLinkTree']);
        $scanner = new UsageScanner($this->store, null, 'Linux', refreshAfter: 3600);
        $before = $scanner->snapshot($this->dir . '/sites')->projects[0]->ownBytes;

        // An edit inside a package leaves vendor/'s fingerprint as it was.
        file_put_contents($project . '/vendor/acme/gamma/gamma.php', str_repeat('g', 100_000), FILE_APPEND);

        $this->assertSame($before, $scanner->snapshot($this->dir . '/sites')->projects[0]->ownBytes);
        $refreshing = new UsageScanner($this->store, null, 'Linux', refreshAfter: 0);
        $refreshing->snapshot($this->dir . '/sites');
        $this->assertGreaterThan($before, $refreshing->snapshot($this->dir . '/sites')->projects[0]->ownBytes);
    }

    public function testMeasuresAgainAProjectThatGainedAPackage(): void
    {
        $project = $this->project('shop', [self::class, 'hardLinkTree']);
        $scanner = new UsageScanner($this->store, null, 'Linux', refreshAfter: 3600);
        $before = $scanner->snapshot($this->dir . '/sites')->projects[0]->vendorBytes;

        Files::makeDir($project . '/vendor/acme/delta');
        file_put_contents($project . '/vendor/acme/delta/delta.php', str_repeat('d', 50_000));
        // A later install: stat() has whole seconds, and this test runs within one.
        touch($project . '/vendor/acme', time() + 5);

        $this->assertGreaterThan($before, $scanner->snapshot($this->dir . '/sites')->projects[0]->vendorBytes);
    }

    public function testMeasuresAgainAProjectThatLostAStoreEntryItLinked(): void
    {
        $this->project('shop', [self::class, 'hardLinkTree']);
        $scanner = new UsageScanner($this->store, null, 'Linux', refreshAfter: 3600);
        $before = $scanner->snapshot($this->dir . '/sites')->projects[0];
        $this->assertSame(1, $before->fromStore);

        Files::remove($this->alphaEntry());
        $after = $scanner->snapshot($this->dir . '/sites')->projects[0];

        $this->assertSame(0, $after->fromStore);
        $this->assertSame($after->vendorBytes, $after->ownBytes, 'its last links to alpha hold the data now');
    }

    public function testReportsItsProgressProjectByProject(): void
    {
        $this->project('shop', [self::class, 'hardLinkTree']);
        $this->project('blog', [self::class, 'hardLinkTree']);
        $calls = [];

        (new UsageScanner($this->store, null, 'Linux'))->snapshot(
            $this->dir . '/sites',
            static function (int $done, int $total) use (&$calls): void {
                $calls[] = [$done, $total];
            }
        );

        $this->assertSame([[1, 2], [2, 2]], $calls);
    }

    public function testReadsTheVendorDirectoryAProjectConfigures(): void
    {
        $project = $this->dir . '/sites/shop';
        Files::writeJson($project . '/composer.json', ['config' => ['vendor-dir' => 'lib/vendor']]);
        Files::writeJson($project . '/lib/vendor/composer/installed.json', ['packages' => [
            self::package('acme/alpha', self::ALPHA, 'dist'),
        ]]);
        self::hardLinkTree($this->alphaEntry() . '/files', $project . '/lib/vendor/acme/alpha');

        $usage = (new UsageScanner($this->store, null, 'Linux'))->snapshot($project)->projects[0];

        $this->assertSame($project . '/lib/vendor', $usage->vendorDir);
        $this->assertSame(1, $usage->fromStore);
    }

    public function testAProjectWithoutVendorTakesNothing(): void
    {
        Files::writeJson($this->dir . '/sites/new/composer.json', []);

        $snapshot = (new UsageScanner($this->store, null, 'Linux'))->snapshot($this->dir . '/sites');

        $usage = $snapshot->projects[0];
        $this->assertSame([0, 0, 0, 0], [$usage->packages, $usage->fromStore, $usage->vendorBytes, $usage->ownBytes]);
    }

    /**
     * @param array<string, string> $files
     */
    private function storeEntry(string $name, string $reference, array $files): void
    {
        $entry = $this->store->entryPath($name, '1.0.0', $reference);
        foreach ($files as $file => $content) {
            Files::makeDir(dirname($entry . '/' . StoreEntry::FILES_DIR . '/' . $file));
            file_put_contents($entry . '/' . StoreEntry::FILES_DIR . '/' . $file, $content);
        }
        StoreEntry::writeMeta($entry, ['name' => $name, 'version' => '1.0.0', 'reference' => $reference]);
    }

    private function alphaEntry(): string
    {
        return $this->store->entryPath('acme/alpha', '1.0.0', self::ALPHA);
    }

    private function betaEntry(): string
    {
        return $this->store->entryPath('acme/beta', '1.0.0', self::BETA);
    }

    /**
     * @param callable(string, string): void $placeAlpha puts alpha's store files into vendor/
     */
    private function project(string $name, callable $placeAlpha): string
    {
        $project = $this->dir . '/sites/' . $name;
        $vendor = $project . '/vendor';
        Files::writeJson($project . '/composer.json', ['name' => 'test/' . $name]);
        $placeAlpha($this->alphaEntry() . '/files', $vendor . '/acme/alpha');
        self::copyTree($this->betaEntry() . '/files', $vendor . '/acme/beta');
        Files::makeDir($vendor . '/acme/gamma');
        file_put_contents($vendor . '/acme/gamma/gamma.php', '<?php');
        Files::writeJson($vendor . '/composer/installed.json', ['packages' => [
            self::package('acme/alpha', self::ALPHA, 'dist'),
            self::package('acme/beta', self::BETA, 'dist'),
            self::package('acme/gamma', 'cccccccc', 'source'),
        ]]);

        return $project;
    }

    /**
     * @return array<string, mixed>
     */
    private static function package(string $name, string $reference, string $source): array
    {
        return [
            'name' => $name,
            'version' => '1.0.0',
            'dist' => ['type' => 'zip', 'url' => 'https://example.invalid/' . $name, 'reference' => $reference],
            'installation-source' => $source,
            'install-path' => '../' . $name,
        ];
    }

    public static function hardLinkTree(string $from, string $to): void
    {
        foreach (Files::entries($from) as $relative => $info) {
            Files::makeDir(dirname($to . '/' . $relative));
            if ($info->isFile() && !link($info->getPathname(), $to . '/' . $relative)) {
                throw new \RuntimeException('Cannot link ' . $info->getPathname());
            }
        }
    }

    /**
     * Copies the bytes, so that no filesystem can make a clone of them.
     */
    public static function copyTree(string $from, string $to): void
    {
        foreach (Files::entries($from) as $relative => $info) {
            Files::makeDir(dirname($to . '/' . $relative));
            if ($info->isFile()) {
                file_put_contents($to . '/' . $relative, (string) file_get_contents($info->getPathname()));
            }
        }
    }

    /**
     * What the files under $dir take on disk, measured as the scanner does.
     */
    private static function bytes(string $dir): int
    {
        $bytes = 0;
        foreach (Files::entries($dir) as $info) {
            $stat = $info->isFile() && !$info->isLink() ? lstat($info->getPathname()) : false;
            if ($stat !== false) {
                $bytes += $stat['blocks'] >= 0 ? $stat['blocks'] * 512 : $stat['size'];
            }
        }

        return $bytes;
    }
}
