<?php

declare(strict_types=1);

namespace Movepress\Services\Sync;

final class RsyncStatsParser
{
    public function parse(string $output): ?RsyncStats
    {
        $filesTotal = $this->matchInt('/Number of files:\s*([\d,]+)/i', $output);
        $filesTransferred = $this->matchInt('/Number of (?:regular )?files transferred:\s*([\d,]+)/i', $output);
        $bytesTotal = $this->matchInt('/Total file size:\s*([\d,]+)\s*(?:bytes|B)?/i', $output);
        $bytesTransferred = $this->matchInt('/Total transferred file size:\s*([\d,]+)\s*(?:bytes|B)?/i', $output);

        $stats = new RsyncStats($filesTotal, $filesTransferred, $bytesTotal, $bytesTransferred);

        return $stats->hasAnyValues() ? $stats : null;
    }

    public function parseDryRunSummary(string $output): ?array
    {
        $matches = [];
        preg_match_all('/^INFO:([^:]+):(\d+):(.+)$/m', $output, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return null;
        }

        $files = 0;
        $bytes = 0;

        foreach ($matches as $match) {
            $flags = $match[1];
            $size = (int) $match[2];

            if (strlen($flags) < 2) {
                continue;
            }

            if ($flags[1] === 'f') {
                $files++;
                $bytes += $size;
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    private function matchInt(string $pattern, string $subject): ?int
    {
        if (!preg_match($pattern, $subject, $matches)) {
            return null;
        }

        $value = preg_replace('/[,\s]/', '', $matches[1]);
        if ($value === '') {
            return null;
        }

        return (int) $value;
    }
}
