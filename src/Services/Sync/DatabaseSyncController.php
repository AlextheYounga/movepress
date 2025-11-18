<?php

declare(strict_types=1);

namespace Movepress\Services\Sync;

use Movepress\Services\DatabaseService;
use Movepress\Services\SshService;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class DatabaseSyncController
{
    public function __construct(
        private readonly OutputInterface $output,
        private readonly SymfonyStyle $io,
        private readonly bool $dryRun,
        private readonly bool $verbose,
    ) {}

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
            $this->io->text('Would import to destination database');
            $this->io->text('Would perform search-replace: ' . $sourceEnv['url'] . ' → ' . $destEnv['url']);
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

        try {
            $this->exportSourceDatabase($dbService, $sourceDb, $exportFile, $sourceSsh);

            if (!$noBackup) {
                $this->createDestinationBackup($dbService, $destDb, $destSsh, $destEnv);
            }

            $this->importDestinationDatabase($dbService, $destDb, $exportFile, $destSsh);

            $this->io->text("Performing search-replace: {$sourceUrl} → {$destUrl}");
            $destWordpressPath = $destEnv['wordpress_path'];
            $replacedSuccess = $dbService->searchReplace($destDb, $destWordpressPath, $sourceUrl, $destUrl, $destSsh);
            if (!$replacedSuccess) {
                throw new \RuntimeException('Failed to perform search-replace');
            }

            @unlink($exportFile);
            return true;
        } catch (\Exception $e) {
            $this->cleanupTempFiles($exportFile);
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
        $backupPath = $dbService->backup($destDb, $destSsh, $backupDir);
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

    private function cleanupTempFiles(string $exportFile): void
    {
        @unlink($exportFile);
        @unlink(str_replace('.gz', '', $exportFile));
        @unlink(str_replace('.gz', '', $exportFile) . '.bak');
    }
}
