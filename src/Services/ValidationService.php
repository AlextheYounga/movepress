<?php

declare(strict_types=1);

namespace Movepress\Services;

use Symfony\Component\Console\Style\SymfonyStyle;

class ValidationService
{
    private SymfonyStyle $io;

    public function __construct(SymfonyStyle $io)
    {
        $this->io = $io;
    }

    public function validatePrerequisites(array $sourceEnv, array $destEnv, array $flags, bool $dryRun = false): bool
    {
        $this->io->section('Validating prerequisites');

        // Check rsync availability for untracked file operations
        if ($flags['untracked_files']) {
            if (!RsyncService::isAvailable()) {
                $this->io->error('rsync is not available. Please install rsync to sync files.');
                return false;
            }
            $this->io->writeln('✓ rsync is available');
        }

        // Check database tools for database operations
        if ($flags['db']) {
            if (!DatabaseService::isMysqldumpAvailable()) {
                $this->io->error('mysqldump is not available. Please install MySQL client tools.');
                return false;
            }
            $this->io->writeln('✓ mysqldump is available');

            if (!DatabaseService::isMysqlAvailable()) {
                $this->io->error('mysql is not available. Please install MySQL client tools.');
                return false;
            }
            $this->io->writeln('✓ mysql is available');
            $this->io->writeln('✓ wp-cli is available (bundled)');
        }

        // Test SSH connections (skip in dry-run mode)
        if (!$dryRun) {
            if (!$this->testSshConnection($sourceEnv, 'source')) {
                return false;
            }

            if (!$this->testSshConnection($destEnv, 'destination')) {
                return false;
            }
        }

        $this->io->success('All prerequisites validated');
        return true;
    }

    public function confirmDestructiveOperation(string $destination, array $flags, bool $noInteraction = false): bool
    {
        $warnings = [];

        if (!empty($flags['db']) && !empty($flags['no_backup'])) {
            $warnings[] = 'This operation will REPLACE the database in: ' . $destination;
            $warnings[] = 'All existing data in the destination database will be lost with no backup.';
        }

        if (!empty($flags['delete'])) {
            $warnings[] = 'This operation will DELETE files on the destination that are missing from the source.';
        }

        if (empty($warnings)) {
            return true;
        }

        $this->io->warning($warnings);

        if ($noInteraction) {
            return true;
        }

        return $this->io->confirm('Do you want to continue?', false);
    }

    private function testSshConnection(array $env, string $label): bool
    {
        if (!isset($env['ssh'])) {
            return true;
        }

        $this->io->write("Testing {$label} SSH connection... ");

        $sshService = new SshService($env['ssh']);
        if (!$sshService->testConnection()) {
            $this->io->error("Failed to connect to {$label} via SSH. Please check your SSH configuration.");
            return false;
        }

        $this->io->writeln('✓');
        return true;
    }
}
