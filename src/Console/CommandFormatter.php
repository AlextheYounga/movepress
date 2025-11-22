<?php

declare(strict_types=1);

namespace Movepress\Console;

final class CommandFormatter
{
    /**
     * Produce a human-friendly rendering of a shell command without altering the
     * command actually executed. Useful for logs where nested quoting/escaping
     * makes output hard to read.
     */
    public static function forDisplay(string $command): string
    {
        $clean = $command;

        // If we wrapped with bash -c "..." to preserve pipefail, unwrap for display
        if (preg_match('/^bash -o pipefail -c ([\"\'])(.*)\\1$/', $clean, $matches)) {
            $quote = $matches[1];
            $inner = $matches[2];

            if ($quote === '"') {
                $inner = str_replace(['\\"', '\\`', '\\$', '\\\\'], ['"', '`', '$', '\\'], $inner);
            }

            $clean = 'bash -o pipefail -c ' . $inner;
        }

        // Collapse repeated escaped quotes: turns --user='\''user'\'' into --user='user'
        while (str_contains($clean, "'\\''")) {
            $clean = str_replace("'\\''", "'", $clean);
        }

        return $clean;
    }
}
