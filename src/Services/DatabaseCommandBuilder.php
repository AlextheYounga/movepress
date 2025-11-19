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
        [$host, $port] = $this->resolveHostAndPort($dbConfig);

        $parts = ['mysqldump', '--user=' . escapeshellarg($dbConfig['user']), '--host=' . escapeshellarg($host)];

        if ($port !== null) {
            $parts[] = '--port=' . escapeshellarg($port);
        }

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
        [$host, $port] = $this->resolveHostAndPort($dbConfig);

        $parts = ['mysql', '--user=' . escapeshellarg($dbConfig['user']), '--host=' . escapeshellarg($host)];

        if ($port !== null) {
            $parts[] = '--port=' . escapeshellarg($port);
        }

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

    /**
     * Resolve host/port using explicit port when present, with fallback to host parsing
     *
     * @param array{host: string, port?: string|int|null} $dbConfig
     *
     * @return array{0: string, 1: string|null}
     */
    private function resolveHostAndPort(array $dbConfig): array
    {
        [$host, $parsedPort] = $this->parseHostPort($dbConfig['host']);

        if (isset($dbConfig['port']) && $dbConfig['port'] !== '' && $dbConfig['port'] !== null) {
            return [$host, (string) $dbConfig['port']];
        }

        return [$host, $parsedPort];
    }

    /**
     * Parse host and port from a host string
     * Supports formats: "localhost", "localhost:3306", "mysql-remote", "mysql-remote:3306"
     *
     * @return array{0: string, 1: string|null} [host, port]
     */
    private function parseHostPort(string $hostString): array
    {
        // Check if port is specified after colon
        if (str_contains($hostString, ':')) {
            $parts = explode(':', $hostString, 2);
            return [$parts[0], $parts[1]];
        }

        return [$hostString, null];
    }
}
