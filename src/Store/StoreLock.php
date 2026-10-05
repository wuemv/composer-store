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
    public const DEFAULT_TIMEOUT = 60.0;

    /** @var resource|null */
    private $handle = null;

    private ?string $failure = null;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * Seconds to wait for the lock: $COMPOSER_STORE_LOCK_TIMEOUT, or DEFAULT_TIMEOUT.
     */
    public static function timeout(): float
    {
        $value = $_SERVER['COMPOSER_STORE_LOCK_TIMEOUT'] ?? getenv('COMPOSER_STORE_LOCK_TIMEOUT');

        return is_numeric($value) && $value >= 0 ? (float) $value : self::DEFAULT_TIMEOUT;
    }

    /**
     * @param float                   $timeout seconds to wait for a process holding the lock exclusively
     * @param (callable(): void)|null $onWait  called once when the lock is busy and acquiring it waits
     */
    public function acquireShared(float $timeout, ?callable $onWait = null): bool
    {
        return $this->acquire(LOCK_SH, $timeout, $onWait);
    }

    /**
     * @param float                   $timeout seconds to wait for every other holder to let go
     * @param (callable(): void)|null $onWait  called once when the lock is busy and acquiring it waits
     */
    public function acquireExclusive(float $timeout, ?callable $onWait = null): bool
    {
        return $this->acquire(LOCK_EX, $timeout, $onWait);
    }

    /**
     * Why the last attempt to acquire the lock failed.
     */
    public function failure(): ?string
    {
        return $this->failure;
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
     * @param LOCK_SH|LOCK_EX         $operation
     * @param (callable(): void)|null $onWait
     */
    private function acquire(int $operation, float $timeout, ?callable $onWait): bool
    {
        if ($this->handle !== null) {
            throw new \LogicException('The store lock is already held');
        }
        $this->failure = null;
        $handle = @fopen($this->file, 'c');
        if ($handle === false) {
            $this->failure = sprintf('cannot open %s: %s', $this->file, error_get_last()['message'] ?? 'unknown error');

            return false;
        }

        $deadline = microtime(true) + $timeout;
        $waiting = false;
        while (true) {
            $wouldBlock = 0;
            if (flock($handle, $operation | LOCK_NB, $wouldBlock)) {
                $this->handle = $handle;

                return true;
            }
            // Failing without contention means locks are unsupported here (some network filesystems):
            // waiting would not help. Windows does not report contention, so it always waits.
            if ($wouldBlock !== 1 && PHP_OS_FAMILY !== 'Windows') {
                $this->failure = 'the filesystem does not support file locks';
                break;
            }
            if (microtime(true) >= $deadline) {
                $this->failure = $timeout > 0
                    ? sprintf('another process held it for %g seconds', $timeout)
                    : 'another process holds it';
                break;
            }
            if (!$waiting && $onWait !== null) {
                $onWait();
            }
            $waiting = true;
            usleep(100000);
        }
        fclose($handle);

        return false;
    }
}
