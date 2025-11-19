<?php

declare(strict_types=1);

namespace Movepress\Services\Sync;

use Movepress\Services\DatabaseService;
use Movepress\Services\SshService;
use Movepress\Services\SqlSearchReplaceService;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class DatabaseSyncController
{
    private SqlSearchReplaceService $sqlProcessor;
    private bool $preserveDumps;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly SymfonyStyle $io,
        private readonly bool $dryRun,
        private readonly bool $verbose,
    ) {
        $this->sqlProcessor = new SqlSearchReplaceService();
        $value = getenv('MOVEPRESS_DEBUG_KEEP_REMOTE_IMPORT');
        $this->preserveDumps = $value !== false && (bool) $value;
    }

    public function sync(
        array $sourceEnv,
        array $destEnv,
        bool $noBackup,
        ?SshService $sourceSsh,
        ?SshService $destSsh,
    ): bool {
        if ($this->dryRun) {
            $this->io->text('Would export source database');
            if (!$noBackup) {
                $this->io->text('Would create backup of destination database');
            }
            $this->io->text('Would rewrite SQL dump: ' . $sourceEnv['url'] . ' → ' . $destEnv['url']);
            $this->io->text('Would import to destination database');
            return true;
        }

        if (!DatabaseService::isMysqldumpAvailable()) {
            $this->io->error('mysqldump is not installed or not available in PATH');
            return false;
        }
        if (!DatabaseService::isMysqlAvailable()) {
            $this->io->error('mysql is not installed or not available in PATH');
            return false;
        }

        if ($noBackup) {
            $this->io->warning('Destination database will be overwritten without creating a backup.');
        }

        $dbService = new DatabaseService($this->output, $this->verbose);

        $sourceDb = $sourceEnv['database'];
        $destDb = $destEnv['database'];
        $sourceUrl = $sourceEnv['url'];
        $destUrl = $destEnv['url'];

        $tempDir = sys_get_temp_dir();
        $exportFile = $tempDir . '/movepress_export_' . uniqid() . '.sql.gz';

        $processedDump = null;

        try {
            $this->exportSourceDatabase($dbService, $sourceDb, $exportFile, $sourceSsh);
            $processedDump = $this->prepareSqlDumpForDestination($exportFile, $sourceUrl, $destUrl);

            if (!$noBackup) {
                $this->createDestinationBackup($dbService, $destDb, $destSsh, $destEnv);
            }

            $this->importDestinationDatabase($dbService, $destDb, $processedDump, $destSsh);
            $this->io->success("Replaced URLs in SQL dump: {$sourceUrl} → {$destUrl}");

            $this->cleanupTempFiles($exportFile, $processedDump);
            return true;
        } catch (\Exception $e) {
            $this->cleanupTempFiles($exportFile, $processedDump);
            $this->io->error($e->getMessage());
            return false;
        }
    }

    private function exportSourceDatabase(
        DatabaseService $dbService,
        array $sourceDb,
        string $exportFile,
        ?SshService $sourceSsh,
    ): void {
        $this->io->text('Exporting source database...');
        $success =
            $sourceSsh === null
                ? $dbService->exportLocal($sourceDb, $exportFile, true)
                : $dbService->exportRemote($sourceDb, $sourceSsh, $exportFile, true);

        if (!$success) {
            throw new \RuntimeException('Failed to export source database');
        }
    }

    private function createDestinationBackup(
        DatabaseService $dbService,
        array $destDb,
        ?SshService $destSsh,
        array $destEnv,
    ): void {
        $this->io->text('Creating backup of destination database...');
        $backupDir = $destEnv['backup_path'] ?? null;
        $wordpressPath = $destEnv['wordpress_path'] ?? null;
        $backupPath = $dbService->backup($destDb, $destSsh, $backupDir, $wordpressPath);
        $this->io->note("Destination backup stored at: {$backupPath}");
    }

    private function importDestinationDatabase(
        DatabaseService $dbService,
        array $destDb,
        string $exportFile,
        ?SshService $destSsh,
    ): void {
        $this->io->text('Importing to destination database...');
        $success =
            $destSsh === null
                ? $dbService->importLocal($destDb, $exportFile)
                : $dbService->importRemote($destDb, $destSsh, $exportFile);

        if (!$success) {
            throw new \RuntimeException('Failed to import to destination database');
        }
    }

    private function cleanupTempFiles(string $exportFile, ?string $processedDump = null): void
    {
        $paths = [
            $exportFile,
            $processedDump,
            str_replace('.gz', '', $exportFile),
            str_replace('.gz', '', $exportFile) . '.bak',
            str_replace('.gz', '', $exportFile) . '.rewrite',
        ];

        if ($this->preserveDumps) {
            $existing = array_values(
                array_filter($paths, static function ($path) {
                    return $path !== null && $path !== '' && file_exists($path);
                }),
            );

            if (!empty($existing)) {
                $this->io->note(array_merge(['Preserved database dump artifacts:'], $existing));
            }
            return;
        }

        foreach ($paths as $path) {
            if ($path === null || $path === '') {
                continue;
            }

            @unlink($path);
        }
    }

    private function ensureLocalDumpNotEmpty(string $path): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException('SQL dump not found: ' . $path);
        }

        $size = filesize($path);
        if ($size === false || $size === 0) {
            throw new \RuntimeException('SQL dump appears to be empty. Aborting to prevent data loss.');
        }
    }

    private function prepareSqlDumpForDestination(string $exportFile, string $sourceUrl, string $destUrl): string
    {
        if ($sourceUrl === $destUrl) {
            $this->io->text('Source and destination URLs match; skipping SQL rewrite.');
            return $exportFile;
        }

        $this->io->text("Rewriting SQL dump for {$sourceUrl} → {$destUrl}");

        $wasCompressed = str_ends_with($exportFile, '.gz');
        $plainPath = $wasCompressed ? $this->decompressGzip($exportFile) : $exportFile;
        $rewrittenPath = $plainPath . '.rewrite';

        $this->ensureLocalDumpNotEmpty($plainPath);

        $this->sqlProcessor->replaceInFile($plainPath, $rewrittenPath, [['from' => $sourceUrl, 'to' => $destUrl]]);

        if (!@rename($rewrittenPath, $plainPath)) {
            @unlink($rewrittenPath);
            throw new \RuntimeException('Failed to finalize rewritten SQL dump.');
        }

        if ($wasCompressed) {
            $this->compressGzip($plainPath, $exportFile);
            @unlink($plainPath);
            return $exportFile;
        }

        return $plainPath;
    }

    private function decompressGzip(string $gzipPath): string
    {
        $plainPath = substr($gzipPath, 0, -3);
        $input = gzopen($gzipPath, 'rb');

        if ($input === false) {
            throw new \RuntimeException('Unable to read compressed SQL dump: ' . $gzipPath);
        }

        $output = fopen($plainPath, 'wb');
        if ($output === false) {
            gzclose($input);
            throw new \RuntimeException('Unable to create temporary SQL file: ' . $plainPath);
        }

        try {
            while (!gzeof($input)) {
                $chunk = gzread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException('Failed while extracting SQL dump.');
                }

                if ($chunk === '') {
                    continue;
                }

                if (fwrite($output, $chunk) === false) {
                    throw new \RuntimeException('Failed to write extracted SQL dump.');
                }
            }
        } finally {
            gzclose($input);
            fclose($output);
        }

        return $plainPath;
    }

    private function compressGzip(string $plainPath, string $targetPath): void
    {
        $input = fopen($plainPath, 'rb');
        if ($input === false) {
            throw new \RuntimeException('Unable to open SQL dump for compression: ' . $plainPath);
        }

        $output = gzopen($targetPath, 'wb9');
        if ($output === false) {
            fclose($input);
            throw new \RuntimeException('Unable to recreate compressed SQL dump: ' . $targetPath);
        }

        try {
            while (!feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException('Failed while compressing SQL dump.');
                }

                if ($chunk === '') {
                    continue;
                }

                if (gzwrite($output, $chunk) === false) {
                    throw new \RuntimeException('Failed to write compressed SQL chunk.');
                }
            }
        } finally {
            fclose($input);
            gzclose($output);
        }
    }
}
