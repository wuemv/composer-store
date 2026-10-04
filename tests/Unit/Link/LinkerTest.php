<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Link;

use ComposerStore\Link\LinkException;
use ComposerStore\Link\Linker;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;

final class LinkerTest extends TestCase
{
    private string $dir;

    private string $source;

    private string $target;

    protected function setUp(): void
    {
        $this->dir = Files::tempDir('linker-test');
        $this->source = $this->dir . '/source';
        $this->target = $this->dir . '/vendor/acme/foo';
        foreach (['README.md', 'src/Foo.php', 'src/Deep/Bar.php', 'bin/tool'] as $file) {
            Files::makeDir(dirname($this->source . '/' . $file));
            file_put_contents($this->source . '/' . $file, 'content of ' . $file);
        }
        Files::makeDir($this->source . '/empty');
    }

    protected function tearDown(): void
    {
        Files::remove($this->dir);
    }

    public function testEveryFileBecomesAHardLinkInsideRealDirectories(): void
    {
        (new Linker())->link($this->source, $this->target);

        $sourceEntries = Files::entries($this->source);
        $this->assertSame(array_keys($sourceEntries), array_keys(Files::entries($this->target)));
        foreach ($sourceEntries as $relative => $info) {
            $linked = $this->target . '/' . $relative;
            $this->assertFalse(is_link($linked), $relative . ' is a symlink');
            if ($info->isDir()) {
                $this->assertDirectoryExists($linked);
            } else {
                $this->assertSame($info->getInode(), fileinode($linked), $relative . ' is not a hard link');
            }
        }
    }

    public function testCopyPathsGetTheirOwnFile(): void
    {
        chmod($this->source . '/bin/tool', 0755);

        (new Linker())->link($this->source, $this->target, ['./bin//tool']);

        $copy = $this->target . '/bin/tool';
        $this->assertNotSame(fileinode($this->source . '/bin/tool'), fileinode($copy));
        $this->assertFileEquals($this->source . '/bin/tool', $copy);
        $this->assertSame(0755, fileperms($copy) & 0777);
        $this->assertSame(fileinode($this->source . '/src/Foo.php'), fileinode($this->target . '/src/Foo.php'));

        chmod($copy, 0700);
        clearstatcache();
        $this->assertSame(0755, fileperms($this->source . '/bin/tool') & 0777, 'chmod on the copy reached the source');
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testSymlinksInsideThePackageAreKeptAsTheyAre(): void
    {
        symlink('src/Foo.php', $this->source . '/foo-link');

        (new Linker())->link($this->source, $this->target);

        $this->assertTrue(is_link($this->target . '/foo-link'));
        $this->assertSame('src/Foo.php', readlink($this->target . '/foo-link'));
    }

    public function testTheTargetMustNotExist(): void
    {
        Files::makeDir($this->target);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        (new Linker())->link($this->source, $this->target);
    }

    public function testAFailedLinkLeavesNothingBehind(): void
    {
        // Hard links cannot cross filesystems, so a target on another one always fails.
        $other = getenv('COMPOSER_STORE_TEST_OTHER_FS') ?: '/dev/shm';
        $otherStat = is_dir($other) && is_writable($other) ? stat($other) : false;
        $sourceStat = stat($this->source);
        if ($otherStat === false || $sourceStat === false || $otherStat['dev'] === $sourceStat['dev']) {
            $this->markTestSkipped("{$other} is not a writable directory on another filesystem");
        }
        $parent = $other . '/linker-test-' . bin2hex(random_bytes(4));
        Files::makeDir($parent);

        try {
            (new Linker())->link($this->source, $parent . '/foo');
            $this->fail('Linking across filesystems should fail');
        } catch (LinkException $e) {
            $this->assertStringContainsString('cannot hard-link', $e->getMessage());
            $this->assertSame(['.', '..'], scandir($parent), 'the half-built tree was left behind');
        } finally {
            Files::remove($parent);
        }
    }

    public function testCopyGivesEveryFileItsOwnCopy(): void
    {
        chmod($this->source . '/bin/tool', 0755);

        (new Linker())->copy($this->source, $this->target);

        foreach (Files::entries($this->source) as $relative => $info) {
            if ($info->isFile()) {
                $copy = $this->target . '/' . $relative;
                $this->assertNotSame($info->getInode(), fileinode($copy), $relative . ' is a hard link');
                $this->assertFileEquals($info->getPathname(), $copy);
                $this->assertSame($info->getPerms() & 0777, fileperms($copy) & 0777);
            }
        }
    }
}
