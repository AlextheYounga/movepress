<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Movepress\Services\DatabaseService;
use Movepress\Services\RsyncService;
use Movepress\Services\SshService;
use Movepress\Services\ValidationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractSyncCommand extends Command
{
    protected OutputInterface $output;
    protected SymfonyStyle $io;
    protected bool $dryRun;
    protected bool $verbose;
    protected array $sourceEnv;
    protected array $destEnv;
    protected array $flags;

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
            'untracked-files',
            null,
            InputOption::VALUE_NONE,
            'Sync files not tracked by Git (uploads, caches, etc.)'
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

    protected function initializeContext(
        OutputInterface $output,
        SymfonyStyle $io,
        array $sourceEnv,
        array $destEnv,
        array $flags,
        bool $dryRun,
        bool $verbose
    ): void {
        $this->output = $output;
        $this->io = $io;
        $this->sourceEnv = $sourceEnv;
        $this->destEnv = $destEnv;
        $this->flags = $flags;
        $this->dryRun = $dryRun;
        $this->verbose = $verbose;
    }

    protected function parseSyncFlags(InputInterface $input): array
    {
        $syncDb = $input->getOption('db');
        $syncUntrackedFiles = $input->getOption('untracked-files');

        // If no flags are set, sync everything
        if (!$syncDb && !$syncUntrackedFiles) {
            $syncDb = true;
            $syncUntrackedFiles = true;
        }

        return [
            'db' => $syncDb,
            'untracked_files' => $syncUntrackedFiles,
        ];
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

    protected function displayConfiguration(string $source, string $destination): void
    {
        $this->io->section('Configuration');

        $items = [
            "Source: {$source}",
            "Destination: {$destination}",
            "Database: " . ($this->flags['db'] ? '✓' : '✗'),
            "Untracked Files: " . ($this->flags['untracked_files'] ? '✓' : '✗'),
            "Dry Run: " . ($this->dryRun ? 'Yes' : 'No'),
        ];

        $this->io->listing($items);

        if ($this->dryRun) {
            $this->io->warning('DRY RUN MODE - No changes will be made');
        }

        $this->io->note([
            'Tracked files (themes, plugins, core) should be deployed via Git.',
            'Use: git push ' . $destination . ' master',
        ]);
    }

    protected function syncFiles(array $excludes, ?SshService $remoteSsh): bool
    {
        $rsync = new RsyncService($this->output, $this->dryRun, $this->verbose);

        $sourcePath = $this->buildPath($this->sourceEnv);
        $destPath = $this->buildPath($this->destEnv);

        // Determine .gitignore path (use local path if source is local)
        $gitignorePath = null;
        if (!isset($this->sourceEnv['ssh'])) {
            $possiblePath = $this->sourceEnv['wordpress_path'] . '/.gitignore';
            if (file_exists($possiblePath)) {
                $gitignorePath = $possiblePath;
            }
        }

        $this->io->text("Syncing untracked files (uploads, caches, etc.)...");
        return $rsync->syncUntrackedFiles($sourcePath, $destPath, $excludes, $remoteSsh, $gitignorePath);
    }

    protected function syncDatabase(bool $noBackup): bool
    {
        if ($this->dryRun) {
            $this->io->text('Would export source database');
            $this->io->text('Would perform search-replace: ' . $this->sourceEnv['url'] . ' → ' . $this->destEnv['url']);
            if (!$noBackup) {
                $this->io->text('Would create backup of destination database');
            }
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

        $dbService = new DatabaseService($this->output, $this->verbose);

        $sourceDb = $this->sourceEnv['database'];
        $destDb = $this->destEnv['database'];
        $sourceUrl = $this->sourceEnv['url'];
        $destUrl = $this->destEnv['url'];

        $sourceSsh = $this->getSshService($this->sourceEnv);
        $destSsh = $this->getSshService($this->destEnv);

        $tempDir = sys_get_temp_dir();
        $exportFile = $tempDir . '/movepress_export_' . uniqid() . '.sql.gz';

        try {
            // Export source database
            $this->io->text("Exporting source database...");
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
            $this->performSearchReplace($exportFile, $sourceUrl, $destUrl);

            // Backup destination database
            if (!$noBackup) {
                $this->io->text("Creating backup of destination database...");
                $backupPath = $dbService->backup($destDb, $destSsh);
                $this->io->text("Backup created: {$backupPath}");
            }

            // Import to destination database
            $this->io->text("Importing to destination database...");
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
            $this->io->error($e->getMessage());
            return false;
        }
    }

    protected function performSearchReplace(string $exportFile, string $sourceUrl, string $destUrl): void
    {
        $this->io->text("Performing search-replace: {$sourceUrl} → {$destUrl}");
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

    protected function validatePrerequisites(): bool
    {
        $validator = new ValidationService();
        return $validator->validatePrerequisites($this->sourceEnv, $this->destEnv, $this->flags, $this->io, $this->dryRun);
    }

    protected function confirmDestructiveOperation(string $destination): bool
    {
        $validator = new ValidationService();
        return $validator->confirmDestructiveOperation($this->io, $destination, $this->flags);
    }
}
