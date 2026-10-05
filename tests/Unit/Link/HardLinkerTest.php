<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Link;

use ComposerStore\Link\HardLinker;
use ComposerStore\Tests\Support\FakeCp;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;

final class HardLinkerTest extends TestCase
{
    private string $dir;

    private string|false $path;

    protected function setUp(): void
    {
        $this->dir = Files::tempDir('hard-linker-test');
        Files::makeDir($this->dir . '/probes');
        $this->path = getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv($this->path === false ? 'PATH' : 'PATH=' . $this->path);
        Files::remove($this->dir);
    }

    #[RequiresOperatingSystem('Linux')]
    public function testLinuxHardLinksTreesWithCpRecursiveLinkNoDereference(): void
    {
        $linker = new HardLinker();

        $this->assertTrue($linker->isSupported($this->dir . '/probes'));
        $this->assertSame([], Files::entries($this->dir . '/probes'), 'the probe is removed');
        $this->assertSame(['cp', '-R', '-l', '-P', '--', 'from', 'to'], $linker->treeCommand('from', 'to'));
    }

    #[RequiresOperatingSystem('Linux')]
    public function testACpThatFollowsSymlinksIsNotUsed(): void
    {
        // Such a cp would turn a package's symlinks into hard links to their targets.
        $bin = $this->dir . '/fake-bin';
        Files::makeDir($bin);
        file_put_contents($bin . '/cp', <<<'SH'
            #!/bin/sh
            for arg do
                shift
                if [ "$arg" = -P ]; then arg=-L; fi
                set -- "$@" "$arg"
            done
            exec /bin/cp "$@"
            SH);
        chmod($bin . '/cp', 0755);
        putenv('PATH=' . $bin . PATH_SEPARATOR . $this->path);

        $this->assertFalse((new HardLinker())->isSupported($this->dir . '/probes'));
        $this->assertSame([], Files::entries($this->dir . '/probes'), 'the probe is removed');
    }

    #[RequiresOperatingSystem('Linux')]
    public function testACpThatFailsIsNotUsed(): void
    {
        FakeCp::write($this->dir . '/fake-bin', fail: true);
        putenv('PATH=' . $this->dir . '/fake-bin' . PATH_SEPARATOR . $this->path);

        $this->assertFalse((new HardLinker())->isSupported($this->dir . '/probes'));
        $this->assertSame([], Files::entries($this->dir . '/probes'), 'the probe is removed');
    }

    public function testOtherSystemsLinkFileByFile(): void
    {
        $this->assertFalse((new HardLinker('Darwin'))->isSupported($this->dir . '/probes'));
        $this->assertFalse((new HardLinker('Windows'))->isSupported($this->dir . '/probes'));
        $this->assertSame([], Files::entries($this->dir . '/probes'), 'nothing was probed');
    }
}
