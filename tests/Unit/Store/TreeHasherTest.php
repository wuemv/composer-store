<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Store;

use ComposerStore\Store\TreeHasher;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;

final class TreeHasherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Files::tempDir('tree-hash-test');
        Files::makeDir($this->dir . '/src/Deep');
        Files::makeDir($this->dir . '/empty');
        file_put_contents($this->dir . '/README.md', 'readme');
        file_put_contents($this->dir . '/src/Deep/Foo.php', '<?php // foo');
    }

    protected function tearDown(): void
    {
        Files::remove($this->dir);
    }

    public function testTheHashIsASha256OfTheTree(): void
    {
        $this->assertMatchesRegularExpression('{^sha256:[0-9a-f]{64}$}', TreeHasher::hash($this->dir));
    }

    public function testACopyOfTheTreeHasTheSameHash(): void
    {
        $copy = $this->dir . '-copy';
        Files::copyTree($this->dir, $copy);
        try {
            touch($copy . '/README.md', time() - 3600);

            $this->assertSame(TreeHasher::hash($this->dir), TreeHasher::hash($copy));
        } finally {
            Files::remove($copy);
        }
    }

    public function testContentChangesChangeTheHash(): void
    {
        $before = TreeHasher::hash($this->dir);
        file_put_contents($this->dir . '/src/Deep/Foo.php', '<?php // changed');

        $this->assertNotSame($before, TreeHasher::hash($this->dir));
    }

    public function testRenamesAndEmptyDirectoriesChangeTheHash(): void
    {
        $before = TreeHasher::hash($this->dir);
        rename($this->dir . '/README.md', $this->dir . '/README.txt');
        $renamed = TreeHasher::hash($this->dir);
        rmdir($this->dir . '/empty');

        $this->assertNotSame($before, $renamed);
        $this->assertNotSame($renamed, TreeHasher::hash($this->dir));
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testTheExecBitCountsButWriteBitsDoNot(): void
    {
        $file = $this->dir . '/README.md';
        chmod($file, 0644);
        $before = TreeHasher::hash($this->dir);

        chmod($file, 0444);
        clearstatcache();
        $this->assertSame($before, TreeHasher::hash($this->dir), 'write bits changed the hash');

        chmod($file, 0755);
        clearstatcache();
        $this->assertNotSame($before, TreeHasher::hash($this->dir), 'the exec bit did not change the hash');
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testSymlinkTargetsCount(): void
    {
        symlink('README.md', $this->dir . '/link');
        $before = TreeHasher::hash($this->dir);
        unlink($this->dir . '/link');
        symlink('src/Deep/Foo.php', $this->dir . '/link');

        $this->assertNotSame($before, TreeHasher::hash($this->dir));
    }
}
