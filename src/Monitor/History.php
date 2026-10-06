<?php

declare(strict_types=1);

namespace ComposerStore\Monitor;

/**
 * The totals of a dashboard's measurements, oldest first, for its charts. Keeps the last MAX_SAMPLES:
 * two hours at the default interval.
 */
final class History
{
    public const MAX_SAMPLES = 3600;

    /** @var list<array{t: float, vendor-bytes: int, with-store-bytes: ?int, free-bytes: ?int}> */
    private array $samples = [];

    /**
     * @param float $start when measuring started, as microtime(true): samples count seconds from it
     */
    public function __construct(private readonly float $start)
    {
    }

    public function add(float $time, Snapshot $snapshot): void
    {
        $this->samples[] = [
            't' => round($time - $this->start, 1),
            'vendor-bytes' => $snapshot->vendorBytes(),
            'with-store-bytes' => $snapshot->withStoreBytes(),
            'free-bytes' => $snapshot->freeBytes,
        ];
        if (count($this->samples) > self::MAX_SAMPLES) {
            $this->samples = array_slice($this->samples, -self::MAX_SAMPLES);
        }
    }

    /**
     * @return list<array{t: float, vendor-bytes: int, with-store-bytes: ?int, free-bytes: ?int}>
     */
    public function samples(): array
    {
        return $this->samples;
    }
}
