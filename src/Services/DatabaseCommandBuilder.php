<?php

declare(strict_types=1);

namespace Movepress\Services;

class DatabaseCommandBuilder
{
    /**
     * Build mysqldump command for database export
     */
    public function buildExportCommand(array $dbConfig, string $outputPath, bool $compress): string
    {
        $parts = [
            'mysqldump',
            '--user=' . escapeshellarg($dbConfig['user']),
            '--host=' . escapeshellarg($dbConfig['host']),
        ];

        if (!empty($dbConfig['password'])) {
            $parts[] = '--password=' . escapeshellarg($dbConfig['password']);
        }

        // Add common mysqldump options for safe, consistent exports
        $parts[] = '--single-transaction';
        $parts[] = '--quick';
        $parts[] = '--lock-tables=false';

        $parts[] = escapeshellarg($dbConfig['name']);

        if ($compress) {
            return implode(' ', $parts) . ' | gzip > ' . escapeshellarg($outputPath);
        }

        return implode(' ', $parts) . ' > ' . escapeshellarg($outputPath);
    }

    /**
     * Build mysql import command for database import
     */
    public function buildImportCommand(array $dbConfig, string $inputPath): string
    {
        $parts = [
            'mysql',
            '--user=' . escapeshellarg($dbConfig['user']),
            '--host=' . escapeshellarg($dbConfig['host']),
        ];

        if (!empty($dbConfig['password'])) {
            $parts[] = '--password=' . escapeshellarg($dbConfig['password']);
        }

        $parts[] = escapeshellarg($dbConfig['name']);

        if (str_ends_with($inputPath, '.gz')) {
            return 'gunzip < ' . escapeshellarg($inputPath) . ' | ' . implode(' ', $parts);
        }

        return implode(' ', $parts) . ' < ' . escapeshellarg($inputPath);
    }

    /**
     * Build wp-cli search-replace command
     */
    public function buildSearchReplaceCommand(
        string $wpCliBinary,
        string $wordpressPath,
        string $oldUrl,
        string $newUrl,
    ): string {
        return sprintf(
            '%s search-replace %s %s --path=%s --skip-columns=guid --quiet',
            escapeshellarg($wpCliBinary),
            escapeshellarg($oldUrl),
            escapeshellarg($newUrl),
            escapeshellarg($wordpressPath),
        );
    }

    /**
     * Validate database configuration has required fields
     */
    public function validateDatabaseConfig(array $dbConfig): void
    {
        $required = ['name', 'user', 'host'];
        foreach ($required as $field) {
            if (empty($dbConfig[$field])) {
                throw new \RuntimeException("Database configuration missing required field: {$field}");
            }
        }
    }
}
