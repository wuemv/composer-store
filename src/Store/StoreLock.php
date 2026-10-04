<?php

declare(strict_types=1);

namespace ComposerStore\Store;

/**
 * Advisory lock on the store's `.lock` file.
 *
 * Installs hold it shared for as long as they use the store, so any number can run at once. Anything
 * that deletes from the store (pruning) must hold it exclusively, so it never removes an entry that an
 * install is about to link. Adding an entry needs no exclusive lock: it is a single atomic rename.
 *
 * The lock is released by release(), when the object is destroyed, or when the process exits.
 */
final class StoreLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * @param float $timeout seconds to wait for a process holding the lock exclusively
     */
    public function acquireShared(float $timeout): bool
    {
        return $this->acquire(LOCK_SH, $timeout);
    }

    /**
     * @param float $timeout seconds to wait for every other holder to let go
     */
    public function acquireExclusive(float $timeout): bool
    {
        return $this->acquire(LOCK_EX, $timeout);
    }

    public function isHeld(): bool
    {
        return $this->handle !== null;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * @param LOCK_SH|LOCK_EX $operation
     */
    private function acquire(int $operation, float $timeout): bool
    {
        if ($this->handle !== null) {
            throw new \LogicException('The store lock is already held');
        }
        $handle = @fopen($this->file, 'c');
        if ($handle === false) {
            return false;
        }

        $deadline = microtime(true) + $timeout;
        while (true) {
            $wouldBlock = 0;
            if (flock($handle, $operation | LOCK_NB, $wouldBlock)) {
                $this->handle = $handle;

                return true;
            }
            // Failing without contention means locks are unsupported here (some network filesystems):
            // waiting would not help. Windows does not report contention, so it always waits.
            if (($wouldBlock !== 1 && PHP_OS_FAMILY !== 'Windows') || microtime(true) >= $deadline) {
                break;
            }
            usleep(100000);
        }
        fclose($handle);

        return false;
    }
}
