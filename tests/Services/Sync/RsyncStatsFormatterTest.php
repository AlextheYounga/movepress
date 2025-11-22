<?php

declare(strict_types=1);

namespace Movepress\Tests\Services\Sync;

use Movepress\Services\Sync\RsyncStats;
use Movepress\Services\Sync\RsyncStatsFormatter;
use PHPUnit\Framework\TestCase;

class RsyncStatsFormatterTest extends TestCase
{
    public function test_formats_live_stats(): void
    {
        $stats = new RsyncStats(200, 5, 512000, 20480);
        $formatter = new RsyncStatsFormatter();

        $lines = $formatter->formatNoteLines($stats, null, false);

        $this->assertSame('Transferred 5 files (20.0 KB).', $lines[0]);
        $this->assertSame('Examined 200 files (500.0 KB total).', $lines[1]);
    }

    public function test_formats_dry_run_stats_with_summary(): void
    {
        $stats = new RsyncStats(50, 0, 102400, 0);
        $summary = ['files' => 3, 'bytes' => 2048];
        $formatter = new RsyncStatsFormatter();

        $lines = $formatter->formatNoteLines($stats, $summary, true);

        $this->assertSame('Would transfer 3 files (2.0 KB).', $lines[0]);
        $this->assertSame('Examined 50 files (100.0 KB total).', $lines[1]);
    }
}
