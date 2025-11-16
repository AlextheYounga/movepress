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

class PushCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('push')
            ->setDescription('Push database and/or files from source to destination environment');

        $this->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Source environment name (e.g., local, staging, production)'
            )
            ->addArgument(
                'destination',
                InputArgument::REQUIRED,
                'Destination environment name (e.g., local, staging, production)'
            )
            ->addOption(
                'db',
                null,
                InputOption::VALUE_NONE,
                'Push database only'
            )
            ->addOption(
                'files',
                null,
                InputOption::VALUE_NONE,
                'Push all files (themes, plugins, uploads)'
            )
            ->addOption(
                'content',
                null,
                InputOption::VALUE_NONE,
                'Push themes and plugins only (excludes uploads)'
            )
            ->addOption(
                'uploads',
                null,
                InputOption::VALUE_NONE,
                'Push wp-content/uploads only'
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $source = $input->getArgument('source');
        $destination = $input->getArgument('destination');
        
        $pushDb = $input->getOption('db');
        $pushFiles = $input->getOption('files');
        $pushContent = $input->getOption('content');
        $pushUploads = $input->getOption('uploads');
        
        // If no flags are set, push everything
        if (!$pushDb && !$pushFiles && !$pushContent && !$pushUploads) {
            $pushDb = true;
            $pushFiles = true;
        }
        
        // Validate flag combinations
        if ($pushFiles && ($pushContent || $pushUploads)) {
            $io->error('Cannot use --files with --content or --uploads. Use either --files (all) or specific flags.');
            return Command::FAILURE;
        }

        $io->title("Movepress: Push {$source} → {$destination}");

        try {
            // Load configuration
            $config = new ConfigLoader();
            $config->load();
            
            $sourceEnv = $config->getEnvironment($source);
            $destEnv = $config->getEnvironment($destination);
            
            // Validate environments
            $this->validateEnvironment($source, $sourceEnv, $io);
            $this->validateEnvironment($destination, $destEnv, $io);
            
            // Display what will be pushed
            $io->section('Configuration');
            $items = [
                "Source: {$source}",
                "Destination: {$destination}",
                "Database: " . ($pushDb ? '✓' : '✗'),
            ];
            
            if ($pushFiles) {
                $items[] = "Files: ✓ (all)";
            } else {
                if ($pushContent) {
                    $items[] = "Content: ✓ (themes + plugins)";
                }
                if ($pushUploads) {
                    $items[] = "Uploads: ✓ (wp-content/uploads)";
                }
                if (!$pushContent && !$pushUploads) {
                    $items[] = "Files: ✗";
                }
            }
            
            $items[] = "Dry Run: " . ($input->getOption('dry-run') ? 'Yes' : 'No');
            
            $io->listing($items);
            
            if ($input->getOption('dry-run')) {
                $io->warning('DRY RUN MODE - No changes will be made');
            }
            
            // Check if rsync is available
            if (($pushFiles || $pushContent || $pushUploads) && !RsyncService::isAvailable()) {
                $io->error('rsync is not installed or not available in PATH');
                return Command::FAILURE;
            }
            
            // Get exclude patterns
            $excludes = $config->getExcludes($destination);
            
            // Sync files if requested
            if ($pushFiles || $pushContent || $pushUploads) {
                $io->section('File Synchronization');
                
                $success = $this->syncFiles(
                    $sourceEnv,
                    $destEnv,
                    $excludes,
                    $pushFiles,
                    $pushContent,
                    $pushUploads,
                    $input->getOption('dry-run'),
                    $input->getOption('verbose'),
                    $output,
                    $io
                );
                
                if (!$success) {
                    return Command::FAILURE;
                }
                
                $io->success('Files synchronized successfully');
            }
            
            // Sync database if requested
            if ($pushDb) {
                $io->section('Database Synchronization');
                
                // Skip actual database operations in dry-run mode
                if ($input->getOption('dry-run')) {
                    $io->text('Would export source database');
                    $io->text('Would perform search-replace: ' . $sourceEnv['url'] . ' → ' . $destEnv['url']);
                    if (!$input->getOption('no-backup')) {
                        $io->text('Would create backup of destination database');
                    }
                    $io->text('Would import to destination database');
                } else {
                    // Check if mysql tools are available
                    if (!DatabaseService::isMysqldumpAvailable()) {
                        $io->error('mysqldump is not installed or not available in PATH');
                        return Command::FAILURE;
                    }
                    if (!DatabaseService::isMysqlAvailable()) {
                        $io->error('mysql is not installed or not available in PATH');
                        return Command::FAILURE;
                    }
                    
                    $success = $this->syncDatabase(
                        $sourceEnv,
                        $destEnv,
                        $input->getOption('no-backup'),
                        $input->getOption('verbose'),
                        $output,
                        $io
                    );
                    
                    if (!$success) {
                        return Command::FAILURE;
                    }
                    
                    $io->success('Database synchronized successfully');
                }
            }
            
            if (!$input->getOption('dry-run')) {
                $io->success("Push from {$source} to {$destination} completed!");
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function validateEnvironment(string $name, array $env, SymfonyStyle $io): void
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

    private function syncFiles(
        array $sourceEnv,
        array $destEnv,
        array $excludes,
        bool $syncAll,
        bool $syncContent,
        bool $syncUploads,
        bool $dryRun,
        bool $verbose,
        OutputInterface $output,
        SymfonyStyle $io
    ): bool {
        $rsync = new RsyncService($output, $dryRun, $verbose);
        
        // Determine source and destination paths
        $sourcePath = $this->buildPath($sourceEnv);
        $destPath = $this->buildPath($destEnv);
        
        // Get SSH service for destination if it's remote
        $sshService = $this->getSshService($destEnv);
        
        // Perform the appropriate sync
        if ($syncAll) {
            $io->text("Syncing all files...");
            return $rsync->sync($sourcePath, $destPath, $excludes, $sshService);
        }
        
        $success = true;
        
        if ($syncContent) {
            $io->text("Syncing themes and plugins...");
            $success = $rsync->syncContent($sourcePath, $destPath, $excludes, $sshService);
        }
        
        if ($syncUploads && $success) {
            $io->text("Syncing uploads...");
            $success = $rsync->syncUploads($sourcePath, $destPath, $excludes, $sshService);
        }
        
        return $success;
    }

    private function buildPath(array $env): string
    {
        $path = $env['wordpress_path'];
        
        // If environment has SSH config, build remote path
        if (isset($env['ssh'])) {
            $sshService = new SshService($env['ssh']);
            $connectionString = $sshService->buildConnectionString();
            return "{$connectionString}:{$path}";
        }
        
        return $path;
    }

    private function getSshService(array $env): ?SshService
    {
        if (isset($env['ssh'])) {
            return new SshService($env['ssh']);
        }
        
        return null;
    }

    private function syncDatabase(
        array $sourceEnv,
        array $destEnv,
        bool $noBackup,
        bool $verbose,
        OutputInterface $output,
        SymfonyStyle $io
    ): bool {
        $dbService = new DatabaseService($output, $verbose);
        
        $sourceDb = $sourceEnv['database'];
        $destDb = $destEnv['database'];
        $sourceUrl = $sourceEnv['url'];
        $destUrl = $destEnv['url'];
        $sourceWpPath = $sourceEnv['wordpress_path'];
        $destWpPath = $destEnv['wordpress_path'];
        
        // Get SSH services
        $sourceSsh = $this->getSshService($sourceEnv);
        $destSsh = $this->getSshService($destEnv);
        
        $tempDir = sys_get_temp_dir();
        $exportFile = $tempDir . '/movepress_export_' . uniqid() . '.sql.gz';
        
        try {
            // Step 1: Export source database
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
            
            // Step 2: Search-replace on exported file (decompress, replace, recompress)
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
            
            // Use wp-cli search-replace on the SQL file
            // For now, use sed as a simpler approach (wp-cli can't work on SQL files directly)
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
            
            // Remove sed backup file
            @unlink($decompressedFile . '.bak');
            
            // Recompress
            $process = \Symfony\Component\Process\Process::fromShellCommandline(
                'gzip ' . escapeshellarg($decompressedFile)
            );
            $process->run();
            
            if (!$process->isSuccessful()) {
                throw new \RuntimeException('Failed to recompress database export');
            }
            
            // Step 3: Backup destination database (unless --no-backup)
            if (!$noBackup) {
                $io->text("Creating backup of destination database...");
                $backupPath = $dbService->backup($destDb, $destSsh);
                $io->text("Backup created: {$backupPath}");
            }
            
            // Step 4: Import to destination database
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
            
            // Clean up temp file
            @unlink($exportFile);
            
            return true;
            
        } catch (\Exception $e) {
            // Clean up temp files on error
            @unlink($exportFile);
            @unlink(str_replace('.gz', '', $exportFile));
            @unlink(str_replace('.gz', '', $exportFile) . '.bak');
            
            $io->error($e->getMessage());
            return false;
        }
    }
}
