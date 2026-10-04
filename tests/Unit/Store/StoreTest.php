<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Store;

use Composer\Package\Package;
use ComposerStore\Store\PublishResult;
use ComposerStore\Store\Store;
use ComposerStore\Store\StoreEntry;
use ComposerStore\Store\TreeHasher;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
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

    public function testPublishMovesACompleteEntryIntoPlaceWithItsTreeHash(): void
    {
        $store = new Store($this->root);
        $entry = $this->entry('acme/foo', '1.0.0', self::REFERENCE);
        $temp = $this->prepare($store, 'first');
        $this->assertStringStartsWith($this->root . '/tmp/', $temp);
        $treeHash = TreeHasher::hash($temp . '/files');
        $this->assertFalse($entry->exists());

        $result = $store->publish($temp, $entry, self::meta());

        $this->assertSame(PublishResult::Published, $result);
        $this->assertTrue($entry->isValid());
        $this->assertSame('first', file_get_contents($entry->filesDir() . '/content.txt'));
        $this->assertSame($treeHash, (StoreEntry::readMeta($entry->path) ?? [])['tree_hash'] ?? null);
        $this->assertDirectoryDoesNotExist($temp);
    }

    public function testPublishingTheSameFilesAgainUsesTheFirstEntry(): void
    {
        $store = new Store($this->root);
        $entry = $this->entry('acme/foo', '1.0.0', self::REFERENCE);
        $store->publish($this->prepare($store, 'same'), $entry, self::meta());
        $second = $this->prepare($store, 'same');

        $result = $store->publish($second, $entry, self::meta());

        $this->assertSame(PublishResult::AlreadyPublished, $result);
        $this->assertDirectoryDoesNotExist($second);
    }

    public function testPublishingOtherFilesUnderAnExistingKeyIsAConflict(): void
    {
        $store = new Store($this->root);
        $entry = $this->entry('acme/foo', '1.0.0', self::REFERENCE);
        $store->publish($this->prepare($store, 'first'), $entry, self::meta());
        $second = $this->prepare($store, 'second');

        $result = $store->publish($second, $entry, self::meta());

        $this->assertSame(PublishResult::Conflict, $result);
        $this->assertSame('first', file_get_contents($entry->filesDir() . '/content.txt'));
        $this->assertSame('second', file_get_contents($second . '/files/content.txt'), 'the loser keeps its copy');
    }

    public function testPublishingWhereAnEntryForAnotherReferenceExistsIsAConflict(): void
    {
        $store = new Store($this->root);
        $entry = $this->entry('acme/foo', '1.0.0', self::REFERENCE);
        $store->publish($this->prepare($store, 'same'), $entry, ['reference' => 'other'] + self::meta());

        $result = $store->publish($this->prepare($store, 'same'), $entry, self::meta());

        $this->assertSame(PublishResult::Conflict, $result);
    }

    public function testAnExistingEntryWithoutATreeHashIsTrusted(): void
    {
        $store = new Store($this->root);
        $entry = $this->entry('acme/foo', '1.0.0', self::REFERENCE);
        $store->publish($this->prepare($store, 'first'), $entry, self::meta());
        StoreEntry::writeMeta($entry->path, self::meta()); // as written before tree hashes existed

        $result = $store->publish($this->prepare($store, 'from an older plugin version'), $entry, self::meta());

        $this->assertSame(PublishResult::AlreadyPublished, $result);
    }

    public function testAnEntryIsOnlyValidForTheSameNameAndReference(): void
    {
        $store = new Store($this->root);
        $entry = $this->entry('acme/foo', '1.0.0', self::REFERENCE);
        $store->publish($this->prepare($store, 'other'), $entry, ['reference' => 'other'] + self::meta());

        $this->assertTrue($entry->exists());
        $this->assertFalse($entry->isValid());

        unlink($entry->path . '/' . StoreEntry::META_FILE);
        $this->assertFalse($entry->isValid());
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testReadOnlyPublishRemovesTheWriteBitsOfFilesOnly(): void
    {
        $store = new Store($this->root);
        $entry = $this->entry('acme/foo', '1.0.0', self::REFERENCE);
        $temp = $this->prepare($store, 'content');
        Files::makeDir($temp . '/files/bin');
        file_put_contents($temp . '/files/bin/tool', '#!/bin/sh');
        chmod($temp . '/files/bin/tool', 0755);
        $treeHash = TreeHasher::hash($temp . '/files');

        $store->publish($temp, $entry, self::meta(), readOnly: true);

        clearstatcache();
        $this->assertSame(0444, fileperms($entry->filesDir() . '/content.txt') & 0777);
        $this->assertSame(0555, fileperms($entry->filesDir() . '/bin/tool') & 0777);
        $this->assertTrue(is_writable($entry->filesDir() . '/bin'), 'directories must stay writable');
        $this->assertSame($treeHash, (StoreEntry::readMeta($entry->path) ?? [])['tree_hash'] ?? null);
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
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

    /**
     * @return array<string, mixed>
     */
    private static function meta(): array
    {
        return ['name' => 'acme/foo', 'reference' => self::REFERENCE];
    }

    private static function package(string $name, string $version, ?string $reference): Package
    {
        $package = new Package($name, $version, $version);
        $package->setDistType('zip');
        $package->setDistReference($reference);

        return $package;
    }
}
