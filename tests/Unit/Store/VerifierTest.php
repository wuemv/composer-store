<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Store;

use ComposerStore\Store\EntryCheck;
use ComposerStore\Store\EntryStatus;
use ComposerStore\Store\Store;
use ComposerStore\Store\StoreEntry;
use ComposerStore\Store\Verifier;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;

final class VerifierTest extends TestCase
{
    use PublishesEntries;

    private const REFERENCE = '0123456789abcdef0123456789abcdef01234567';

    private string $root;

    private Store $store;

    protected function setUp(): void
    {
        $this->root = Files::tempDir('verifier-test');
        $this->store = new Store($this->root);
    }

    protected function tearDown(): void
    {
        Files::remove($this->root);
    }

    public function testAnUntouchedEntryIsOk(): void
    {
        $this->publishEntry($this->store, 'acme/foo', '1.0.0', self::REFERENCE);

        $check = $this->checkOnlyEntry();

        $this->assertSame(EntryStatus::Ok, $check->status);
        $this->assertFalse($check->isProblem());
    }

    public function testAnEditedFileChangesTheEntryAndIsReported(): void
    {
        $entry = $this->publishEntry($this->store, 'acme/foo', '1.0.0', self::REFERENCE, [
            'src/Edited.php' => '<?php',
            'src/Untouched.php' => '<?php',
        ]);
        file_put_contents($entry->filesDir() . '/src/Edited.php', "<?php\nvar_dump('debugging');\n");

        $check = $this->checkOnlyEntry();

        $this->assertSame(EntryStatus::Changed, $check->status);
        $this->assertTrue($check->isProblem());
        $this->assertSame(['src/Edited.php'], $check->details);
    }

    public function testAddedAndDeletedFilesChangeTheEntry(): void
    {
        $entry = $this->publishEntry($this->store, 'acme/foo', '1.0.0', self::REFERENCE);
        file_put_contents($entry->filesDir() . '/new.php', '<?php');
        $added = $this->checkOnlyEntry();
        unlink($entry->filesDir() . '/new.php');
        unlink($entry->filesDir() . '/src/Foo.php');
        $deleted = $this->checkOnlyEntry();

        $this->assertSame(EntryStatus::Changed, $added->status);
        $this->assertSame(EntryStatus::Changed, $deleted->status);
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testOnlyTheExecBitOfThePermissionsCounts(): void
    {
        $entry = $this->publishEntry($this->store, 'acme/foo', '1.0.0', self::REFERENCE);
        Store::makeReadOnly($entry->filesDir());
        $this->assertSame(EntryStatus::Ok, $this->checkOnlyEntry()->status, 'read-only mode is not a change');

        chmod($entry->filesDir() . '/src/Foo.php', 0555);
        $this->assertSame(EntryStatus::Changed, $this->checkOnlyEntry()->status);
    }

    public function testAnEntryWithoutATreeHashCannotBeChecked(): void
    {
        $entry = $this->publishEntry($this->store, 'acme/foo', '1.0.0', self::REFERENCE);
        // As written before tree hashes were recorded.
        $meta = ['name' => 'acme/foo', 'version' => '1.0.0', 'reference' => self::REFERENCE];
        StoreEntry::writeMeta($entry->path, $meta);

        $check = $this->checkOnlyEntry();

        $this->assertSame(EntryStatus::Unhashed, $check->status);
        $this->assertFalse($check->isProblem());
    }

    public function testAnEntryWithoutMetadataIsInvalid(): void
    {
        $entry = $this->publishEntry($this->store, 'acme/foo', '1.0.0', self::REFERENCE);
        unlink($entry->path . '/' . StoreEntry::META_FILE);

        $check = $this->checkOnlyEntry();

        $this->assertSame(EntryStatus::Invalid, $check->status);
        $this->assertSame(['.store-meta.json is missing or unreadable'], $check->details);
    }

    public function testAnEntryWithoutFilesIsInvalid(): void
    {
        $entry = $this->publishEntry($this->store, 'acme/foo', '1.0.0', self::REFERENCE);
        Files::remove($entry->filesDir());

        $check = $this->checkOnlyEntry();

        $this->assertSame(EntryStatus::Invalid, $check->status);
        $this->assertSame(['files/ is missing'], $check->details);
    }

    public function testAnEntryWhoseMetadataPointsElsewhereIsInvalid(): void
    {
        $entry = $this->publishEntry($this->store, 'acme/foo', '1.0.0', self::REFERENCE);
        $meta = ['name' => 'acme/foo', 'version' => '2.0.0', 'reference' => self::REFERENCE];
        StoreEntry::writeMeta($entry->path, $meta);

        $check = $this->checkOnlyEntry();

        $this->assertSame(EntryStatus::Invalid, $check->status);
        $this->assertSame(['the metadata does not match the entry'], $check->details);
    }

    /**
     * @phpstan-impure the entry changes between calls
     */
    private function checkOnlyEntry(): EntryCheck
    {
        clearstatcache();
        $entries = $this->store->entries();
        $this->assertCount(1, $entries);

        return (new Verifier($this->store))->check($entries[0]);
    }
}
