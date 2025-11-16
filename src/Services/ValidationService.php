<?php

declare(strict_types=1);

namespace Movepress\Services;

use Symfony\Component\Console\Style\SymfonyStyle;

class ValidationService
{
    public function validatePrerequisites(
        array $sourceEnv,
        array $destEnv,
        array $flags,
        SymfonyStyle $io,
        bool $dryRun = false
    ): bool {
        $io->section('Validating prerequisites');

        // Check rsync availability for untracked file operations
        if ($flags['untracked_files']) {
            if (!RsyncService::isAvailable()) {
                $io->error('rsync is not available. Please install rsync to sync files.');
                return false;
            }
            $io->writeln('✓ rsync is available');
        }

        // Check database tools for database operations
        if ($flags['db']) {
            if (!DatabaseService::isMysqldumpAvailable()) {
                $io->error('mysqldump is not available. Please install MySQL client tools.');
                return false;
            }
            $io->writeln('✓ mysqldump is available');

            if (!DatabaseService::isMysqlAvailable()) {
                $io->error('mysql is not available. Please install MySQL client tools.');
                return false;
            }
            $io->writeln('✓ mysql is available');
            $io->writeln('✓ wp-cli is available (bundled)');
        }

        // Test SSH connections (skip in dry-run mode)
        if (!$dryRun) {
            if (!$this->testSshConnection($sourceEnv, 'source', $io)) {
                return false;
            }

            if (!$this->testSshConnection($destEnv, 'destination', $io)) {
                return false;
            }
        }

        $io->success('All prerequisites validated');
        return true;
    }

    public function confirmDestructiveOperation(SymfonyStyle $io, string $destination, array $flags): bool
    {
        if ($flags['db']) {
            $io->warning([
                'This operation will REPLACE the database in: ' . $destination,
                'All existing data in the destination database will be lost.',
            ]);

            return $io->confirm('Do you want to continue?', false);
        }

        return true;
    }

    private function testSshConnection(array $env, string $label, SymfonyStyle $io): bool
    {
        if (!isset($env['ssh'])) {
            return true;
        }

        $io->write("Testing {$label} SSH connection... ");
        
        $sshService = new SshService($env['ssh']);
        if (!$sshService->testConnection()) {
            $io->error("Failed to connect to {$label} via SSH. Please check your SSH configuration.");
            return false;
        }
        
        $io->writeln('✓');
        return true;
    }
}
