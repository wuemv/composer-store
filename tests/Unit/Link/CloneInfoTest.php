<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Link;

use ComposerStore\Link\CloneFile;
use ComposerStore\Link\CloneInfo;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;

/**
 * On macOS with FFI, clones a file for real and asks getattrlist(2) about it, a copy and a hard link.
 * Elsewhere, there is no binding.
 */
final class CloneInfoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Files::tempDir('clone-info-test');
    }

    protected function tearDown(): void
    {
        Files::remove($this->dir);
    }

    public function testThereIsNoBindingOutsideMacOs(): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->markTestSkipped('macOS has getattrlist(2)');
        }

        $this->assertNull(CloneInfo::load());
    }

    #[RequiresOperatingSystem('Darwin')]
    public function testClonesShareTheirOriginalsCloneIdAndCopiesDoNot(): void
    {
        $info = CloneInfo::load() ?? $this->markTestSkipped('needs the FFI extension');
        $cloner = CloneFile::load() ?? $this->markTestSkipped('needs the FFI extension');
        $original = $this->dir . '/original';
        file_put_contents($original, random_bytes(100_000));
        $cloner->clone($original, $this->dir . '/clone');
        file_put_contents($this->dir . '/copy', (string) file_get_contents($original));
        link($original, $this->dir . '/hard-link');

        $originalInfo = $info->of($original);
        $clone = $info->of($this->dir . '/clone');
        $copy = $info->of($this->dir . '/copy');
        $hardLink = $info->of($this->dir . '/hard-link');

        $this->assertNotNull($originalInfo);
        $this->assertNotNull($clone);
        $this->assertNotNull($copy);
        $this->assertNotNull($hardLink);
        $this->assertSame($originalInfo['clone-id'], $clone['clone-id']);
        $this->assertSame(0, $clone['private-bytes'], 'a fresh clone shares all its data');
        $this->assertSame(0, $originalInfo['private-bytes'], 'and so does the file it was cloned from');
        $this->assertNotSame($originalInfo['clone-id'], $copy['clone-id']);
        $this->assertGreaterThanOrEqual(100_000, $copy['private-bytes'], 'a copy shares nothing');
        $this->assertSame($originalInfo['clone-id'], $hardLink['clone-id'], 'a hard link is the same file');
        $this->assertNull($info->of($this->dir . '/missing'));
    }

    #[RequiresOperatingSystem('Darwin')]
    public function testAnEditedCloneStopsSharingWhatItRewrote(): void
    {
        $info = CloneInfo::load() ?? $this->markTestSkipped('needs the FFI extension');
        $cloner = CloneFile::load() ?? $this->markTestSkipped('needs the FFI extension');
        $original = $this->dir . '/original';
        file_put_contents($original, random_bytes(1_000_000));
        $cloner->clone($original, $this->dir . '/clone');

        $handle = fopen($this->dir . '/clone', 'r+');
        $this->assertNotFalse($handle);
        fwrite($handle, 'edited');
        fclose($handle);
        $clone = $info->of($this->dir . '/clone');

        $this->assertNotNull($clone);
        $this->assertGreaterThan(0, $clone['private-bytes']);
        $this->assertLessThan(1_000_000, $clone['private-bytes'], 'the rest is still shared');
    }
}
