<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Link;

use ComposerStore\Link\CloneFile;
use ComposerStore\Link\Cloner;
use ComposerStore\Link\LinkException;
use ComposerStore\Tests\Support\FakeCp;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;

/**
 * Most tests run the Linux and macOS commands through a stand-in cp that copies, so they pass on any
 * filesystem. testTheRealCpAgreesWithTheProbe uses the system's own cp, and clones for real on
 * filesystems with reflinks. On macOS, testMacClonesWholeTreesWithClonefile calls clonefile(2) for real.
 */
final class ClonerTest extends TestCase
{
    private string $dir;

    private string $source;

    private string|false $path;

    protected function setUp(): void
    {
        $this->dir = Files::tempDir('cloner-test');
        $this->source = $this->dir . '/source';
        foreach (['src/Foo.php' => '<?php', 'bin/tool' => '#!/bin/sh', 'README.md' => 'readme'] as $file => $content) {
            Files::makeDir(dirname($this->source . '/' . $file));
            file_put_contents($this->source . '/' . $file, $content);
        }
        chmod($this->source . '/bin/tool', 0755);
        $this->path = getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv($this->path === false ? 'PATH' : 'PATH=' . $this->path);
        Files::remove($this->dir);
    }

    #[RequiresOperatingSystem('Linux')]
    public function testLinuxClonesWithGnuCpReflinkAlways(): void
    {
        $log = $this->useFakeCp();
        $cloner = new Cloner('Linux');

        $this->assertTrue($cloner->isAvailable());
        $this->assertFalse($cloner->clonesInProcess());
        $this->assertTrue($cloner->isSupported($this->dir));
        $cloner->cloneTree($this->source, $this->dir . '/target');

        $this->assertClonedTree($this->dir . '/target');
        $calls = file($log, FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertCount(2, $calls);
        $this->assertStringStartsWith('--reflink=always -- ' . $this->dir . '/.reflink-probe-', $calls[0]);
        $this->assertSame(
            sprintf('-R -T --reflink=always --preserve=mode,timestamps -- %s %s/target', $this->source, $this->dir),
            $calls[1]
        );
        $this->assertSame([], glob($this->dir . '/.reflink-probe-*'), 'the probe files are removed');
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testMacClonesWithCpCOnApfs(): void
    {
        $log = $this->useFakeCp(mounts: "/dev/disk3s1s1 on / (apfs, sealed, local, read-only, journaled)\n");
        $cloner = new Cloner('Darwin', inProcess: false);

        $this->assertFalse($cloner->clonesInProcess());
        $this->assertTrue($cloner->isSupported($this->dir));
        $cloner->cloneTree($this->source, $this->dir . '/target');

        $this->assertClonedTree($this->dir . '/target');
        $calls = file($log, FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertStringStartsWith('-c -- ' . $this->dir . '/.reflink-probe-', $calls[0]);
        $this->assertSame(sprintf('-c -R -p -- %s %s/target', $this->source, $this->dir), $calls[1]);
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testMacOnlyClonesOnApfsVolumes(): void
    {
        // cp -c quietly copies where clonefile is not supported, so the volume type decides.
        Files::makeDir($this->dir . '/external');
        $log = $this->useFakeCp(mounts: implode("\n", [
            '/dev/disk3s1s1 on / (apfs, sealed, local, read-only, journaled)',
            'map auto_home on /System/Volumes/Data/home (autofs, automounted, nobrowse)',
            '/dev/disk5s1 on ' . $this->dir . '/external (hfs, local, nodev, nosuid, journaled)',
            '',
        ]));
        $cloner = new Cloner('Darwin', inProcess: false);

        $this->assertFalse($cloner->isSupported($this->dir . '/external'));
        $this->assertFalse(is_file($log), 'cp is not even tried');
        $this->assertTrue($cloner->isSupported($this->dir));
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testACpThatCannotCloneMeansNoSupport(): void
    {
        $this->useFakeCp(fail: true);
        $cloner = new Cloner('Linux');

        $this->assertFalse($cloner->isSupported($this->dir));
        try {
            $cloner->cloneTree($this->source, $this->dir . '/target');
            $this->fail('cloneTree() did not fail');
        } catch (LinkException $e) {
            $this->assertStringContainsString('cp: failed to clone: Operation not supported', $e->getMessage());
        }
    }

    #[RequiresOperatingSystem('Darwin')]
    public function testMacClonesWholeTreesWithClonefile(): void
    {
        if (CloneFile::load() === null) {
            $this->markTestSkipped('clonefile(2) needs the FFI extension');
        }
        $log = $this->useFakeCp();
        touch($this->source . '/src/Foo.php', 1_000_000_000);
        $cloner = new Cloner();

        $this->assertTrue($cloner->clonesInProcess());
        $this->assertTrue($cloner->isSupported($this->dir));
        $cloner->cloneTree($this->source, $this->dir . '/target');

        $this->assertClonedTree($this->dir . '/target');
        $this->assertSame(1_000_000_000, filemtime($this->dir . '/target/src/Foo.php'));
        $this->assertFileDoesNotExist($log, 'cp did not run');
        try {
            $cloner->cloneTree($this->source, $this->dir . '/target');
            $this->fail('cloneTree() did not fail');
        } catch (LinkException $e) {
            $this->assertStringEndsWith('/target: File exists', $e->getMessage());
        }
    }

    public function testOtherSystemsHaveNoClones(): void
    {
        $cloner = new Cloner('Windows');

        $this->assertFalse($cloner->isAvailable());
        $this->assertFalse($cloner->clonesInProcess());
        $this->assertFalse($cloner->isSupported($this->dir));
        $this->expectException(LinkException::class);
        $cloner->cloneTree($this->source, $this->dir . '/target');
    }

    public function testTheRealCpAgreesWithTheProbe(): void
    {
        $cloner = new Cloner(PHP_OS_FAMILY, inProcess: false);

        if (!$cloner->isSupported($this->dir)) {
            $this->expectException(LinkException::class);
        }
        $cloner->cloneTree($this->source, $this->dir . '/target');

        $this->assertClonedTree($this->dir . '/target');
    }

    /**
     * Puts a stand-in cp (and mount, when given a mount table) first on the PATH.
     *
     * @return string the file where the cp logs its arguments
     */
    private function useFakeCp(bool $fail = false, ?string $mounts = null): string
    {
        $bin = $this->dir . '/fake-bin';
        $log = $this->dir . '/cp.log';
        FakeCp::write($bin, $log, $fail);
        if ($mounts !== null) {
            FakeCp::writeMount($bin, $mounts);
        }
        putenv('PATH=' . $bin . PATH_SEPARATOR . $this->path);

        return $log;
    }

    private function assertClonedTree(string $target): void
    {
        $this->assertSame(Files::snapshot($this->source), Files::snapshot($target));
        foreach (['src/Foo.php', 'bin/tool', 'README.md'] as $file) {
            $this->assertNotSame(fileinode($this->source . '/' . $file), fileinode($target . '/' . $file));
        }
    }
}
