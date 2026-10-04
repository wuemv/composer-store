<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Store;

use ComposerStore\Store\StoreLock;
use ComposerStore\Tests\Support\Files;
use ComposerStore\Tests\Support\Process;
use PHPUnit\Framework\TestCase;

/**
 * Each StoreLock opens the lock file on its own, so two instances behave like two processes.
 */
final class StoreLockTest extends TestCase
{
    private string $dir;

    private string $file;

    protected function setUp(): void
    {
        $this->dir = Files::tempDir('lock-test');
        $this->file = $this->dir . '/.lock';
    }

    protected function tearDown(): void
    {
        Files::remove($this->dir);
    }

    public function testInstallsCanShareTheLock(): void
    {
        $first = new StoreLock($this->file);
        $second = new StoreLock($this->file);

        $this->assertTrue($first->acquireShared(0));
        $this->assertTrue($second->acquireShared(0));
        $this->assertTrue($first->isHeld() && $second->isHeld());
    }

    public function testExclusiveWaitsForEveryInstallToFinish(): void
    {
        $install = new StoreLock($this->file);
        $maintenance = new StoreLock($this->file);
        $this->assertTrue($install->acquireShared(0));

        $this->assertFalse($maintenance->acquireExclusive(0));
        $this->assertFalse($maintenance->isHeld());

        $install->release();
        $this->assertTrue($maintenance->acquireExclusive(0));
    }

    public function testInstallsWaitForMaintenanceUpToTheTimeout(): void
    {
        $maintenance = new StoreLock($this->file);
        $install = new StoreLock($this->file);
        $this->assertTrue($maintenance->acquireExclusive(0));

        $start = microtime(true);
        $this->assertFalse($install->acquireShared(0.3));
        $this->assertGreaterThanOrEqual(0.25, microtime(true) - $start, 'it did not wait');

        $maintenance->release();
        $this->assertTrue($install->acquireShared(0));
    }

    public function testALockHeldByAnotherProcessIsRespected(): void
    {
        $holder = Files::tempFile('lock-holder');
        file_put_contents($holder, sprintf(
            '<?php $h = fopen(%s, "c"); flock($h, LOCK_EX); echo "locked\n"; fflush(STDOUT); usleep(800000);',
            var_export($this->file, true)
        ));
        $process = Process::start([PHP_BINARY, $holder], $this->dir, getenv());
        try {
            $deadline = microtime(true) + 10;
            while (!str_contains($process->output(), 'locked') && microtime(true) < $deadline) {
                usleep(20000);
            }

            $this->assertFalse((new StoreLock($this->file))->acquireShared(0));
            $this->assertTrue((new StoreLock($this->file))->acquireShared(5), 'the lock was not released at exit');
        } finally {
            $process->wait();
            unlink($holder);
        }
    }

    public function testALockFileThatCannotBeCreatedIsNotAcquired(): void
    {
        $lock = new StoreLock($this->dir . '/missing/.lock');

        $this->assertFalse($lock->acquireShared(0));
        $this->assertStringStartsWith('cannot open ' . $this->dir . '/missing/.lock', (string) $lock->failure());
    }

    public function testFailureSaysWhyTheLockWasNotAcquired(): void
    {
        $maintenance = new StoreLock($this->file);
        $install = new StoreLock($this->file);
        $this->assertTrue($maintenance->acquireExclusive(0));
        $this->assertNull($maintenance->failure());

        $this->assertFalse($install->acquireShared(0));
        $this->assertSame('another process holds it', $install->failure());
        $this->assertFalse($install->acquireShared(0.2));
        $this->assertSame('another process held it for 0.2 seconds', $install->failure());

        $maintenance->release();
        $this->assertTrue($install->acquireShared(0));
        $this->assertNull($install->failure());
    }

    public function testTheWaitCallbackRunsOnceAndOnlyWhenTheLockIsBusy(): void
    {
        $calls = 0;
        $onWait = static function () use (&$calls): void {
            $calls++;
        };
        $maintenance = new StoreLock($this->file);
        $this->assertTrue($maintenance->acquireExclusive(0, $onWait));
        $this->assertSame(0, $calls);

        $this->assertFalse((new StoreLock($this->file))->acquireShared(0.3, $onWait));
        $this->assertSame(1, $calls);
        $this->assertFalse((new StoreLock($this->file))->acquireShared(0, $onWait));
        $this->assertSame(1, $calls, 'without a timeout, nothing waits');
    }

    public function testTheLockCannotBeTakenTwiceByTheSameObject(): void
    {
        $lock = new StoreLock($this->file);
        $lock->acquireShared(0);

        $this->expectException(\LogicException::class);
        $lock->acquireShared(0);
    }
}
