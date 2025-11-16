<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Movepress\Config\ConfigLoader;
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
            
            // TODO: Implement database sync
            if ($pushDb) {
                $io->section('Database Synchronization');
                $io->warning('Database sync not yet implemented');
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
}
