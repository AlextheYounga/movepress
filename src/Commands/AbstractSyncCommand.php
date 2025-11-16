<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Movepress\Config\ConfigLoader;
use Movepress\Services\DatabaseService;
use Movepress\Services\RsyncService;
use Movepress\Services\SshService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractSyncCommand extends Command
{
    protected function configureArguments(): void
    {
        $this->addArgument(
            'source',
            InputArgument::REQUIRED,
            'Source environment name'
        )
        ->addArgument(
            'destination',
            InputArgument::REQUIRED,
            'Destination environment name'
        );
    }

    protected function configureOptions(): void
    {
        $this->addOption(
            'db',
            null,
            InputOption::VALUE_NONE,
            'Sync database only'
        )
        ->addOption(
            'files',
            null,
            InputOption::VALUE_NONE,
            'Sync all files (themes, plugins, uploads)'
        )
        ->addOption(
            'content',
            null,
            InputOption::VALUE_NONE,
            'Sync themes and plugins only (excludes uploads)'
        )
        ->addOption(
            'uploads',
            null,
            InputOption::VALUE_NONE,
            'Sync wp-content/uploads only'
        )
        ->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Show what would be transferred without actually doing it'
        )
        ->addOption(
            'no-backup',
            null,
            InputOption::VALUE_NONE,
            'Skip backing up destination database before import'
        );
    }

    protected function parseSyncFlags(InputInterface $input): array
    {
        $syncDb = $input->getOption('db');
        $syncFiles = $input->getOption('files');
        $syncContent = $input->getOption('content');
        $syncUploads = $input->getOption('uploads');

        // If no flags are set, sync everything
        if (!$syncDb && !$syncFiles && !$syncContent && !$syncUploads) {
            $syncDb = true;
            $syncFiles = true;
        }

        return [
            'db' => $syncDb,
            'files' => $syncFiles,
            'content' => $syncContent,
            'uploads' => $syncUploads,
        ];
    }

    protected function validateFlagCombinations(array $flags, SymfonyStyle $io): bool
    {
        if ($flags['files'] && ($flags['content'] || $flags['uploads'])) {
            $io->error('Cannot use --files with --content or --uploads. Use either --files (all) or specific flags.');
            return false;
        }

        return true;
    }

    protected function validateEnvironment(string $name, array $env): void
    {
        if (empty($env['wordpress_path'])) {
            throw new \RuntimeException("Environment '{$name}' missing 'wordpress_path' configuration");
        }

        if (empty($env['url'])) {
            throw new \RuntimeException("Environment '{$name}' missing 'url' configuration");
        }

        if (empty($env['database'])) {
            throw new \RuntimeException("Environment '{$name}' missing 'database' configuration");
        }
    }

    protected function displayConfiguration(
        SymfonyStyle $io,
        string $source,
        string $destination,
        array $flags,
        bool $dryRun
    ): void {
        $io->section('Configuration');

        $items = [
            "Source: {$source}",
            "Destination: {$destination}",
            "Database: " . ($flags['db'] ? '✓' : '✗'),
        ];

        if ($flags['files']) {
            $items[] = "Files: ✓ (all)";
        } else {
            if ($flags['content']) {
                $items[] = "Content: ✓ (themes + plugins)";
            }
            if ($flags['uploads']) {
                $items[] = "Uploads: ✓ (wp-content/uploads)";
            }
            if (!$flags['content'] && !$flags['uploads']) {
                $items[] = "Files: ✗";
            }
        }

        $items[] = "Dry Run: " . ($dryRun ? 'Yes' : 'No');

        $io->listing($items);

        if ($dryRun) {
            $io->warning('DRY RUN MODE - No changes will be made');
        }
    }

    protected function syncFiles(
        array $sourceEnv,
        array $destEnv,
        array $excludes,
        array $flags,
        bool $dryRun,
        bool $verbose,
        OutputInterface $output,
        SymfonyStyle $io,
        ?SshService $remoteSsh
    ): bool {
        $rsync = new RsyncService($output, $dryRun, $verbose);

        $sourcePath = $this->buildPath($sourceEnv);
        $destPath = $this->buildPath($destEnv);

        if ($flags['files']) {
            $io->text("Syncing all files...");
            return $rsync->sync($sourcePath, $destPath, $excludes, $remoteSsh);
        }

        $success = true;

        if ($flags['content']) {
            $io->text("Syncing themes and plugins...");
            $success = $rsync->syncContent($sourcePath, $destPath, $excludes, $remoteSsh);
        }

        if ($flags['uploads'] && $success) {
            $io->text("Syncing uploads...");
            $success = $rsync->syncUploads($sourcePath, $destPath, $excludes, $remoteSsh);
        }

        return $success;
    }

    protected function syncDatabase(
        array $sourceEnv,
        array $destEnv,
        bool $noBackup,
        bool $dryRun,
        bool $verbose,
        OutputInterface $output,
        SymfonyStyle $io
    ): bool {
        if ($dryRun) {
            $io->text('Would export source database');
            $io->text('Would perform search-replace: ' . $sourceEnv['url'] . ' → ' . $destEnv['url']);
            if (!$noBackup) {
                $io->text('Would create backup of destination database');
            }
            $io->text('Would import to destination database');
            return true;
        }

        if (!DatabaseService::isMysqldumpAvailable()) {
            $io->error('mysqldump is not installed or not available in PATH');
            return false;
        }
        if (!DatabaseService::isMysqlAvailable()) {
            $io->error('mysql is not installed or not available in PATH');
            return false;
        }

        $dbService = new DatabaseService($output, $verbose);

        $sourceDb = $sourceEnv['database'];
        $destDb = $destEnv['database'];
        $sourceUrl = $sourceEnv['url'];
        $destUrl = $destEnv['url'];

        $sourceSsh = $this->getSshService($sourceEnv);
        $destSsh = $this->getSshService($destEnv);

        $tempDir = sys_get_temp_dir();
        $exportFile = $tempDir . '/movepress_export_' . uniqid() . '.sql.gz';

        try {
            // Export source database
            $io->text("Exporting source database...");
            if ($sourceSsh === null) {
                if (!$dbService->exportLocal($sourceDb, $exportFile, true)) {
                    throw new \RuntimeException('Failed to export source database');
                }
            } else {
                if (!$dbService->exportRemote($sourceDb, $sourceSsh, $exportFile, true)) {
                    throw new \RuntimeException('Failed to export source database');
                }
            }

            // Search-replace on exported file
            $this->performSearchReplace($exportFile, $sourceUrl, $destUrl, $io);

            // Backup destination database
            if (!$noBackup) {
                $io->text("Creating backup of destination database...");
                $backupPath = $dbService->backup($destDb, $destSsh);
                $io->text("Backup created: {$backupPath}");
            }

            // Import to destination database
            $io->text("Importing to destination database...");
            if ($destSsh === null) {
                if (!$dbService->importLocal($destDb, $exportFile)) {
                    throw new \RuntimeException('Failed to import to destination database');
                }
            } else {
                if (!$dbService->importRemote($destDb, $destSsh, $exportFile)) {
                    throw new \RuntimeException('Failed to import to destination database');
                }
            }

            @unlink($exportFile);
            return true;

        } catch (\Exception $e) {
            $this->cleanupTempFiles($exportFile);
            $io->error($e->getMessage());
            return false;
        }
    }

    protected function performSearchReplace(string $exportFile, string $sourceUrl, string $destUrl, SymfonyStyle $io): void
    {
        $io->text("Performing search-replace: {$sourceUrl} → {$destUrl}");
        $decompressedFile = str_replace('.gz', '', $exportFile);

        // Decompress
        $process = \Symfony\Component\Process\Process::fromShellCommandline(
            'gunzip ' . escapeshellarg($exportFile)
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Failed to decompress database export');
        }

        // Search-replace using sed
        $sedCommand = sprintf(
            'sed -i.bak %s %s',
            escapeshellarg('s|' . addcslashes($sourceUrl, '|/') . '|' . addcslashes($destUrl, '|/') . '|g'),
            escapeshellarg($decompressedFile)
        );

        $process = \Symfony\Component\Process\Process::fromShellCommandline($sedCommand);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Failed to perform search-replace');
        }

        @unlink($decompressedFile . '.bak');

        // Recompress
        $process = \Symfony\Component\Process\Process::fromShellCommandline(
            'gzip ' . escapeshellarg($decompressedFile)
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Failed to recompress database export');
        }
    }

    protected function cleanupTempFiles(string $exportFile): void
    {
        @unlink($exportFile);
        @unlink(str_replace('.gz', '', $exportFile));
        @unlink(str_replace('.gz', '', $exportFile) . '.bak');
    }

    protected function buildPath(array $env): string
    {
        $path = $env['wordpress_path'];

        if (isset($env['ssh'])) {
            $sshService = new SshService($env['ssh']);
            $connectionString = $sshService->buildConnectionString();
            return "{$connectionString}:{$path}";
        }

        return $path;
    }

    protected function getSshService(array $env): ?SshService
    {
        if (isset($env['ssh'])) {
            return new SshService($env['ssh']);
        }

        return null;
    }
}
