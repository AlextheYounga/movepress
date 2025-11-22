<?php

declare(strict_types=1);

namespace Movepress\Services\Sync;

final class RsyncStatsFormatter
{
    public function formatNoteLines(RsyncStats $stats, ?array $dryRunSummary, bool $isDryRun): array
    {
        $filesTransferred = $stats->getFilesTransferred() ?? 0;
        $bytesTransferred = $stats->getBytesTransferred();

        if ($isDryRun && $dryRunSummary !== null) {
            $filesTransferred = $dryRunSummary['files'];
            $bytesTransferred = $dryRunSummary['bytes'];
        }

        $filesTotal = $stats->getFilesTotal();
        $bytesTotal = $stats->getBytesTotal();

        $lines = [];
        $verb = $isDryRun ? 'Would transfer' : 'Transferred';
        $lines[] = sprintf(
            '%s %s %s (%s).',
            $verb,
            number_format($filesTransferred),
            $filesTransferred === 1 ? 'file' : 'files',
            $this->formatBytes($bytesTransferred),
        );

        if ($filesTotal !== null) {
            $totalLine = sprintf('Examined %s %s', number_format($filesTotal), $filesTotal === 1 ? 'file' : 'files');
            if ($bytesTotal !== null) {
                $totalLine .= sprintf(' (%s total)', $this->formatBytes($bytesTotal));
            }
            $totalLine .= '.';
            $lines[] = $totalLine;
        } elseif ($bytesTotal !== null) {
            $lines[] = sprintf('Total dataset size: %s.', $this->formatBytes($bytesTotal));
        }

        return $lines;
    }

    private function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'unknown size';
        }

        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        $value = $bytes / 1024 ** $power;

        return sprintf('%0.1f %s', $value, $units[$power]);
    }
}
