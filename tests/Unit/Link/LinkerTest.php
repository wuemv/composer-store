<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Link;

use ComposerStore\Link\Cloner;
use ComposerStore\Link\Command;
use ComposerStore\Link\LinkException;
use ComposerStore\Link\Linker;
use ComposerStore\Tests\Support\FakeCp;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

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
        (new Linker())->link($this->source, $this->target, ['./bin//tool']);

        $copy = $this->target . '/bin/tool';
        $this->assertNotSame(fileinode($this->source . '/bin/tool'), fileinode($copy));
        $this->assertFileEquals($this->source . '/bin/tool', $copy);
        $this->assertSame(fileinode($this->source . '/src/Foo.php'), fileinode($this->target . '/src/Foo.php'));
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testCopiedFilesKeepTheirModeAndChmodStaysOnTheCopy(): void
    {
        chmod($this->source . '/bin/tool', 0755);

        (new Linker())->link($this->source, $this->target, ['bin/tool']);

        $copy = $this->target . '/bin/tool';
        $this->assertSame(0755, fileperms($copy) & 0777);
        chmod($copy, 0700);
        clearstatcache();
        $this->assertSame(0755, fileperms($this->source . '/bin/tool') & 0777, 'chmod on the copy reached the source');
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
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

    #[RequiresOperatingSystem('Linux')]
    public function testLinkAsyncHardLinksTheTreeWithOneCpThenCopiesTheCopyPaths(): void
    {
        chmod($this->source . '/bin/tool', 0755);
        symlink('src/Foo.php', $this->source . '/foo-link');
        // A path that leaves the package is never touched.
        Files::makeDir(dirname($this->target));
        file_put_contents(dirname($this->target) . '/outside', 'outside');
        $outside = fileinode(dirname($this->target) . '/outside');
        $commands = [];

        $error = $this->settle((new Linker())->linkAsync(
            $this->source,
            $this->target,
            ['./bin//tool', '../outside', 'missing'],
            static function (array $command) use (&$commands): PromiseInterface {
                $commands[] = $command;

                return self::runAtOnce($command);
            }
        ));

        $this->assertNull($error);
        $this->assertCount(1, $commands, 'one cp for the whole tree');
        $this->assertSame(['cp', '-R', '-l', '-P', '--', $this->source], array_slice($commands[0], 0, 6));
        $this->assertSame(array_keys(Files::entries($this->source)), array_keys(Files::entries($this->target)));
        foreach (['README.md', 'src/Foo.php', 'src/Deep/Bar.php'] as $file) {
            $this->assertSame(fileinode($this->source . '/' . $file), fileinode($this->target . '/' . $file), $file);
        }
        $copy = $this->target . '/bin/tool';
        $this->assertNotSame(fileinode($this->source . '/bin/tool'), fileinode($copy));
        $this->assertFileEquals($this->source . '/bin/tool', $copy);
        $this->assertSame(0755, fileperms($copy) & 0777);
        $this->assertTrue(is_link($this->target . '/foo-link'));
        $this->assertSame('src/Foo.php', readlink($this->target . '/foo-link'));
        $this->assertDirectoryExists($this->target . '/empty');
        $this->assertSame($outside, fileinode(dirname($this->target) . '/outside'));
        $this->assertSame([], glob(dirname($this->target) . '/.*composer-store*') ?: []);
    }

    #[RequiresOperatingSystem('Linux')]
    public function testLinkAsyncLeavesNothingBehindWhenCpFails(): void
    {
        // The cp gets part of the way, or all of it, before failing.
        $error = $this->settle((new Linker())->linkAsync(
            $this->source,
            $this->target,
            [],
            static function (array $command): PromiseInterface {
                self::runAtOnce($command);

                return reject(new LinkException('cp failed'));
            }
        ));

        $this->assertInstanceOf(LinkException::class, $error);
        $this->assertFileDoesNotExist($this->target);
        $this->assertSame([], glob(dirname($this->target) . '/.*composer-store*') ?: []);
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

    #[RequiresOperatingSystem('Linux')]
    public function testReflinkClonesEveryFileIncludingBinaries(): void
    {
        $path = getenv('PATH');
        FakeCp::write($this->dir . '/fake-bin');
        putenv('PATH=' . $this->dir . '/fake-bin' . PATH_SEPARATOR . $path);
        try {
            (new Linker(new Cloner('Linux')))->reflink($this->source, $this->target);
        } finally {
            putenv($path === false ? 'PATH' : 'PATH=' . $path);
        }

        $this->assertSame(Files::snapshot($this->source), Files::snapshot($this->target));
        $this->assertDirectoryExists($this->target . '/empty');
        foreach (['README.md', 'src/Foo.php', 'bin/tool'] as $file) {
            $this->assertNotSame(fileinode($this->source . '/' . $file), fileinode($this->target . '/' . $file));
        }
    }

    public function testAFailedCloneLeavesNothingBehind(): void
    {
        try {
            (new Linker(new Cloner('Windows')))->reflink($this->source, $this->target);
            $this->fail('reflink() did not fail');
        } catch (LinkException) {
        }

        $this->assertFileDoesNotExist($this->target);
        $this->assertSame([], glob(dirname($this->target) . '/.*composer-store*') ?: []);
    }

    /**
     * Runs a command at once, where Composer would run it in the background.
     *
     * @param list<string> $command
     *
     * @return PromiseInterface<mixed>
     */
    private static function runAtOnce(array $command): PromiseInterface
    {
        [$status, , $error] = Command::run($command);

        return $status === 0 ? resolve(null) : reject(new LinkException($error));
    }

    /**
     * @param PromiseInterface<mixed> $promise settled already, as the commands ran at once
     *
     * @return \Throwable|null why it was rejected
     */
    private function settle(PromiseInterface $promise): ?\Throwable
    {
        $error = null;
        $promise->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        return $error;
    }
}
