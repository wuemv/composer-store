<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Store;

use ComposerStore\Store\PublishResult;
use ComposerStore\Store\Store;
use ComposerStore\Store\StoreEntry;
use ComposerStore\Tests\Support\Files;

/**
 * Builds store entries the way the installer does: in a temp dir, then published.
 */
trait PublishesEntries
{
    /**
     * @param array<string, string> $files relative path => content
     */
    private function publishEntry(
        Store $store,
        string $name,
        string $version,
        string $reference,
        array $files = ['src/Foo.php' => '<?php'],
        string $createdAt = '-1 hour',
    ): StoreEntry {
        $created = (int) strtotime($createdAt);
        $temp = $store->createTempDir();
        foreach ($files as $path => $content) {
            Files::makeDir(dirname($temp . '/files/' . $path));
            file_put_contents($temp . '/files/' . $path, $content);
            // Extracted before the entry was created, as in an install.
            touch($temp . '/files/' . $path, $created);
        }
        $entry = new StoreEntry($store->entryPath($name, $version, $reference), $name, $version, $reference);
        $meta = [
            'format' => StoreEntry::META_FORMAT,
            'name' => $name,
            'version' => $version,
            'reference' => $reference,
            'created_at' => gmdate(DATE_ATOM, $created),
        ];
        self::assertSame(PublishResult::Published, $store->publish($temp, $entry, $meta));

        return $entry;
    }
}
