<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Monitor;

use ComposerStore\Monitor\History;
use ComposerStore\Monitor\Snapshot;
use PHPUnit\Framework\TestCase;

final class HistoryTest extends TestCase
{
    public function testRecordsTheTotalsOfEachSnapshotInSecondsFromTheStart(): void
    {
        $history = new History(1000.0);

        $history->add(1000.04, self::snapshot(free: 500));
        $history->add(1002.0, self::snapshot(free: 400, storeUsed: null));

        $this->assertSame([
            ['t' => 0.0, 'vendor-bytes' => 0, 'with-store-bytes' => 0, 'free-bytes' => 500],
            ['t' => 2.0, 'vendor-bytes' => 0, 'with-store-bytes' => null, 'free-bytes' => 400],
        ], $history->samples());
    }

    public function testKeepsOnlyTheLatestSamples(): void
    {
        $history = new History(0.0);

        for ($i = 0; $i <= History::MAX_SAMPLES; $i++) {
            $history->add((float) $i, self::snapshot(free: $i));
        }

        $samples = $history->samples();
        $this->assertCount(History::MAX_SAMPLES, $samples);
        $this->assertSame(1, $samples[0]['free-bytes'], 'the oldest one went');
        $this->assertSame(History::MAX_SAMPLES, $samples[History::MAX_SAMPLES - 1]['free-bytes']);
    }

    private static function snapshot(?int $free, ?int $storeUsed = 0): Snapshot
    {
        return new Snapshot('/projects', $free, 0, 0, $storeUsed, [], true, true);
    }
}
