<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Store;

use ComposerStore\Store\EntryStats;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;

final class EntryStatsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Files::tempDir('stats-test');
    }

    protected function tearDown(): void
    {
        Files::remove($this->dir);
    }

    public function testCountsTheFilesAndTheLinksFromVendorDirectories(): void
    {
        $files = $this->dir . '/entry/files';
        Files::makeDir($files . '/src');
        file_put_contents($files . '/big.txt', str_repeat('x', 10000));
        file_put_contents($files . '/src/small.php', '<?php');
        file_put_contents($files . '/unused.txt', 'nobody links me');
        Files::makeDir($this->dir . '/first');
        Files::makeDir($this->dir . '/second');
        $this->assertTrue(link($files . '/big.txt', $this->dir . '/first/big.txt'));
        $this->assertTrue(link($files . '/big.txt', $this->dir . '/second/big.txt'));
        $this->assertTrue(link($files . '/src/small.php', $this->dir . '/first/small.php'));

        $stats = EntryStats::of($files);

        $big = self::diskSize($files . '/big.txt');
        $small = self::diskSize($files . '/src/small.php');
        $this->assertSame(3, $stats->files);
        $this->assertSame($big + $small + self::diskSize($files . '/unused.txt'), $stats->bytes);
        $this->assertSame(2, $stats->linkedFiles);
        $this->assertSame(3, $stats->links);
        $this->assertSame(2 * $big + $small, $stats->savedBytes);
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testSymlinksAreNotCounted(): void
    {
        Files::makeDir($this->dir . '/files');
        file_put_contents($this->dir . '/target.txt', 'outside');
        symlink($this->dir . '/target.txt', $this->dir . '/files/link.txt');

        $this->assertSame(0, EntryStats::of($this->dir . '/files')->files);
    }

    public function testAMissingDirectoryIsEmpty(): void
    {
        $this->assertEquals(new EntryStats(), EntryStats::of($this->dir . '/missing'));
    }

    public function testAddSumsEveryCount(): void
    {
        $sum = (new EntryStats(1, 2, 3, 4, 5))->add(new EntryStats(10, 20, 30, 40, 50));

        $this->assertEquals(new EntryStats(11, 22, 33, 44, 55), $sum);
    }

    private static function diskSize(string $file): int
    {
        $stat = stat($file);
        self::assertIsArray($stat);

        return $stat['blocks'] >= 0 ? $stat['blocks'] * 512 : $stat['size'];
    }
}
