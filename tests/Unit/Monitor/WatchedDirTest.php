<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Monitor;

use ComposerStore\Monitor\WatchedDir;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\TestCase;

final class WatchedDirTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Files::tempDir('watched-dir-test');
    }

    protected function tearDown(): void
    {
        Files::remove($this->dir);
    }

    public function testSuggestsTheDeveloperDirectoryWhenThereIsOne(): void
    {
        Files::makeDir($this->dir . '/Developer');

        $this->assertSame('~/Developer', WatchedDir::suggestion($this->dir, '/somewhere'));
    }

    public function testSuggestsTheCurrentDirectoryOtherwise(): void
    {
        $this->assertSame('/somewhere', WatchedDir::suggestion($this->dir, '/somewhere'));
    }

    public function testResolvesTheHomeDirectoryAndRelativePaths(): void
    {
        Files::makeDir($this->dir . '/Developer/apps');

        // realpath() on both sides: Windows answers with backslashes.
        $developer = realpath($this->dir . '/Developer');
        $apps = realpath($this->dir . '/Developer/apps');
        $this->assertSame($developer, WatchedDir::resolve('~/Developer', $this->dir));
        $this->assertSame($apps, WatchedDir::resolve(' ~/Developer/apps/ ', $this->dir));
        $this->assertSame(realpath($this->dir), WatchedDir::resolve('~', $this->dir));
        $this->assertSame($developer, WatchedDir::resolve($this->dir . '/Developer/apps/..', $this->dir));
    }

    public function testRejectsWhatIsNotADirectory(): void
    {
        file_put_contents($this->dir . '/file', '');

        $this->assertNull(WatchedDir::resolve($this->dir . '/file', $this->dir));
        $this->assertNull(WatchedDir::resolve($this->dir . '/missing', $this->dir));
        $this->assertNull(WatchedDir::resolve('~/missing', $this->dir));
        $this->assertNull(WatchedDir::resolve('', $this->dir));
    }
}
