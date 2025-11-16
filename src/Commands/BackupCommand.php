<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Movepress\Config\ConfigLoader;
use Movepress\Services\DatabaseService;
use Movepress\Services\SshService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class BackupCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('backup')
            ->setDescription('Create a database backup for an environment')
            ->addArgument('environment', InputArgument::REQUIRED, 'Environment to backup')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory for backup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $environmentName = $input->getArgument('environment');
        $outputDir = $input->getOption('output');

        $io->title("Database Backup: {$environmentName}");

        try {
            $config = new ConfigLoader();
            $config->load();

            $env = $config->getEnvironment($environmentName);

            // Validate environment
            if (empty($env['database'])) {
                throw new \RuntimeException("Environment '{$environmentName}' has no database configuration");
            }

            $dbConfig = $env['database'];
            $sshService = isset($env['ssh']) ? new SshService($env['ssh']) : null;

            // Check prerequisites
            if (!DatabaseService::isMysqldumpAvailable()) {
                $io->error('mysqldump is not available. Please install MySQL client tools.');
                return Command::FAILURE;
            }

            // Test SSH connection if remote
            if ($sshService !== null) {
                $io->writeln('Testing SSH connection...');
                if (!$sshService->testConnection()) {
                    $io->error('Failed to connect via SSH');
                    return Command::FAILURE;
                }
                $io->writeln('<fg=green>✓</> SSH connection successful');
            }

            // Display configuration
            $io->section('Backup Configuration');
            $rows = [
                ['Environment', $environmentName],
                ['Database', $dbConfig['name']],
                ['Type', $sshService ? 'Remote (via SSH)' : 'Local'],
                ['Output Directory', $outputDir ?? 'Current directory'],
            ];
            $io->table(['Setting', 'Value'], $rows);

            if (!$io->confirm('Create backup?', true)) {
                $io->writeln('Backup cancelled.');
                return Command::SUCCESS;
            }

            // Create backup
            $io->section('Creating Backup');
            $dbService = new DatabaseService($output, true);

            // Use --output flag, or backup_path from config, or default to current directory
            $backupDir = $outputDir ?? ($env['backup_path'] ?? null);
            $backupFile = $dbService->backup($dbConfig, $sshService, $backupDir);

            if ($backupFile) {
                $fileSize = filesize($backupFile);
                $humanSize = $this->formatBytes($fileSize);

                $io->success(['Backup created successfully!', "File: {$backupFile}", "Size: {$humanSize}"]);
                return Command::SUCCESS;
            } else {
                $io->error('Failed to create backup');
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);

        return sprintf('%.2f %s', $bytes / pow(1024, $factor), $units[$factor]);
    }
}
