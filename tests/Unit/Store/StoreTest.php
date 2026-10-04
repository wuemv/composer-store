<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Store;

use Composer\Package\Package;
use ComposerStore\Store\Store;
use ComposerStore\Store\StoreEntry;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private const REFERENCE = 'ABCDEF0123456789abcdef0123456789abcdef01';

    private string $root;

    protected function setUp(): void
    {
        $this->root = Files::tempDir('store-test');
    }

    protected function tearDown(): void
    {
        Files::remove($this->root);
    }

    public function testEntriesAreKeyedByNameVersionAndShortReference(): void
    {
        $entry = $this->entry('acme/foo', 'v1.2.3', self::REFERENCE);

        $this->assertSame($this->root . '/packages/acme/foo/v1.2.3-abcdef012345', $entry->path);
        $this->assertSame($entry->path . '/files', $entry->filesDir());
        $this->assertSame(self::REFERENCE, $entry->reference);
    }

    public function testVersionsAreMadeSafeForTheFilesystem(): void
    {
        $entry = $this->entry('acme/foo', 'dev-feature/new thing', self::REFERENCE);

        $this->assertSame($this->root . '/packages/acme/foo/dev-feature_new_thing-abcdef012345', $entry->path);
    }

    public function testReferencesThatAreNotCommitHashesAreHashed(): void
    {
        $entry = $this->entry('acme/foo', '1.0.0', 'release/1.0');

        $this->assertSame(
            $this->root . '/packages/acme/foo/1.0.0-' . substr(hash('sha256', 'release/1.0'), 0, 12),
            $entry->path
        );
    }

    public function testPackagesWithoutADistReferenceHaveNoEntry(): void
    {
        $store = new Store($this->root);

        $this->assertNull($store->entryFor(self::package('acme/foo', '1.0.0', null)));
        $this->assertNull($store->entryFor(self::package('acme/foo', '1.0.0', '')));
    }

    public function testInitializeCreatesTheLayout(): void
    {
        $root = $this->root . '/nested/store';

        $real = (new Store($root))->initialize();

        $this->assertSame(realpath($root), $real);
        $this->assertDirectoryExists($root . '/packages');
        $this->assertDirectoryExists($root . '/tmp');
    }

    public function testPublishMovesACompleteEntryIntoPlace(): void
    {
        $store = new Store($this->root);
        $entry = $this->entry('acme/foo', '1.0.0', self::REFERENCE);
        $temp = $this->prepare($store, 'first');
        $this->assertStringStartsWith($this->root . '/tmp/', $temp);
        $this->assertFalse($entry->exists());

        $published = $store->publish($temp, $entry, ['name' => 'acme/foo', 'reference' => self::REFERENCE]);

        $this->assertTrue($published);
        $this->assertTrue($entry->isValid());
        $this->assertSame('first', file_get_contents($entry->filesDir() . '/content.txt'));
        $this->assertDirectoryDoesNotExist($temp);
    }

    public function testPublishingAnEntryThatAlreadyExistsKeepsTheFirstOne(): void
    {
        $store = new Store($this->root);
        $entry = $this->entry('acme/foo', '1.0.0', self::REFERENCE);
        $meta = ['name' => 'acme/foo', 'reference' => self::REFERENCE];
        $store->publish($this->prepare($store, 'first'), $entry, $meta);
        $second = $this->prepare($store, 'second');

        $published = $store->publish($second, $entry, $meta);

        $this->assertFalse($published);
        $this->assertSame('first', file_get_contents($entry->filesDir() . '/content.txt'));
        $this->assertSame('second', file_get_contents($second . '/files/content.txt'), 'the loser keeps its copy');
    }

    public function testAnEntryIsOnlyValidForTheSameNameAndReference(): void
    {
        $store = new Store($this->root);
        $entry = $this->entry('acme/foo', '1.0.0', self::REFERENCE);
        $store->publish($this->prepare($store, 'other'), $entry, ['name' => 'acme/foo', 'reference' => 'other']);

        $this->assertTrue($entry->exists());
        $this->assertFalse($entry->isValid());

        unlink($entry->path . '/' . StoreEntry::META_FILE);
        $this->assertFalse($entry->isValid());
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testRemoveTreeDoesNotFollowSymlinks(): void
    {
        $outside = $this->root . '/outside';
        Files::makeDir($outside);
        file_put_contents($outside . '/keep.txt', 'keep');
        $tree = $this->root . '/tree';
        Files::makeDir($tree . '/nested');
        symlink($outside, $tree . '/nested/link');

        Store::removeTree($tree);

        $this->assertFileDoesNotExist($tree);
        $this->assertFileExists($outside . '/keep.txt');
    }

    private function entry(string $name, string $version, string $reference): StoreEntry
    {
        $entry = (new Store($this->root))->entryFor(self::package($name, $version, $reference));
        $this->assertNotNull($entry);

        return $entry;
    }

    private function prepare(Store $store, string $content): string
    {
        $temp = $store->createTempDir();
        Files::makeDir($temp . '/files');
        file_put_contents($temp . '/files/content.txt', $content);

        return $temp;
    }

    private static function package(string $name, string $version, ?string $reference): Package
    {
        $package = new Package($name, $version, $version);
        $package->setDistType('zip');
        $package->setDistReference($reference);

        return $package;
    }
}
