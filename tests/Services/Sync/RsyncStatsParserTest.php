<?php

declare(strict_types=1);

namespace Movepress\Tests\Services\Sync;

use Movepress\Services\Sync\RsyncDryRunSummary;
use Movepress\Services\Sync\RsyncStatsParser;
use PHPUnit\Framework\TestCase;

class RsyncStatsParserTest extends TestCase
{
    public function test_parses_stats_output(): void
    {
        $parser = new RsyncStatsParser();

        $statsOutput = <<<OUT
        Number of files: 120 (reg: 100, dir: 20)
        Number of regular files transferred: 5
        Total file size: 204800 bytes
        Total transferred file size: 10240 bytes
        OUT;

        $stats = $parser->parse($statsOutput);

        $this->assertNotNull($stats);
        $this->assertSame(120, $stats->getFilesTotal());
        $this->assertSame(5, $stats->getFilesTransferred());
        $this->assertSame(204800, $stats->getBytesTotal());
        $this->assertSame(10240, $stats->getBytesTransferred());
    }

    public function test_parses_dry_run_summary(): void
    {
        $parser = new RsyncStatsParser();
        $output = <<<OUT
        INFO:>f+++++++++:1234:wp-content/uploads/file.jpg
        INFO:cd+++++++++:0:wp-content/uploads/newdir/
        INFO:>f.st......:4321:wp-content/uploads/file2.jpg
        OUT;

        $summary = $parser->parseDryRunSummary($output);

        $this->assertInstanceOf(RsyncDryRunSummary::class, $summary);
        $this->assertSame(2, $summary->getFiles());
        $this->assertSame(5555, $summary->getBytes());
    }
}
