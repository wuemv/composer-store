<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Link;

use ComposerStore\Link\Cloner;
use ComposerStore\Link\Method;
use ComposerStore\Link\MethodChoice;
use ComposerStore\Mode;
use ComposerStore\Tests\Support\FakeCp;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;

/**
 * Reflink support comes from a stand-in cp that either copies or fails, so every case runs anywhere.
 */
#[RequiresOperatingSystem('Linux')]
final class MethodChoiceTest extends TestCase
{
    private string $store;

    private string|false $path;

    protected function setUp(): void
    {
        $this->store = Files::tempDir('method-test');
        Files::makeDir($this->store . '/tmp');
        $this->path = getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv($this->path === false ? 'PATH' : 'PATH=' . $this->path);
        Files::remove($this->store);
    }

    public function testAutoPrefersReflinks(): void
    {
        $this->assertEquals(new MethodChoice(Method::Reflink), $this->choose(Mode::Auto, reflinks: true));
        $this->assertEquals(new MethodChoice(Method::Hardlink), $this->choose(Mode::Auto, reflinks: false));
    }

    public function testHardlinkModeAlwaysHardLinks(): void
    {
        $this->assertEquals(new MethodChoice(Method::Hardlink), $this->choose(Mode::Hardlink, reflinks: true));
    }

    public function testReflinkModeOnlyClones(): void
    {
        $this->assertEquals(new MethodChoice(Method::Reflink), $this->choose(Mode::Reflink, reflinks: true));

        $choice = $this->choose(Mode::Reflink, reflinks: false);

        $this->assertNull($choice->method);
        $this->assertSame('the filesystem of the store does not support reflinks', $choice->reason);
    }

    public function testReflinkModeOnASystemWithoutClones(): void
    {
        $choice = MethodChoice::make(Mode::Reflink, $this->store, new Cloner('Windows'));

        $this->assertNull($choice->method);
        $this->assertSame('reflinks are not supported on this operating system', $choice->reason);
        $auto = MethodChoice::make(Mode::Auto, $this->store, new Cloner('Windows'));
        $this->assertSame(Method::Hardlink, $auto->method);
    }

    public function testCopyModeDoesNotUseTheStore(): void
    {
        $choice = $this->choose(Mode::Copy, reflinks: true);

        $this->assertNull($choice->method);
        $this->assertSame('mode is copy', $choice->reason);
    }

    private function choose(Mode $mode, bool $reflinks): MethodChoice
    {
        $bin = $this->store . '/fake-bin-' . ($reflinks ? 'clones' : 'fails');
        FakeCp::write($bin, fail: !$reflinks);
        putenv('PATH=' . $bin . PATH_SEPARATOR . $this->path);

        return MethodChoice::make($mode, $this->store, new Cloner('Linux'));
    }
}
