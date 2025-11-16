<?php

declare(strict_types=1);

namespace Movepress\Services;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use RuntimeException;

class DatabaseService
{
    private OutputInterface $output;
    private bool $verbose;
    private string $wpCliBinary;
    private DatabaseCommandBuilder $commandBuilder;
    private RemoteTransferService $remoteTransfer;

    public function __construct(OutputInterface $output, bool $verbose = false)
    {
        $this->output = $output;
        $this->verbose = $verbose;
        $this->wpCliBinary = $this->getWpCliBinary();
        $this->commandBuilder = new DatabaseCommandBuilder();
        $this->remoteTransfer = new RemoteTransferService($output, $verbose);
    }

    /**
     * Export a local database to a SQL file
     */
    public function exportLocal(array $dbConfig, string $outputPath, bool $compress = true): bool
    {
        $this->commandBuilder->validateDatabaseConfig($dbConfig);

        $command = $this->commandBuilder->buildExportCommand($dbConfig, $outputPath, $compress);

        if ($this->verbose) {
            $this->output->writeln("Executing: {$command}");
        }

        return $this->executeCommand($command);
    }

    /**
     * Export a remote database via SSH to a local file
     */
    public function exportRemote(
        array $dbConfig,
        SshService $sshService,
        string $outputPath,
        bool $compress = true,
    ): bool {
        $this->commandBuilder->validateDatabaseConfig($dbConfig);

        $remoteTempFile = '/tmp/movepress_export_' . uniqid() . ($compress ? '.sql.gz' : '.sql');

        // Execute mysqldump on remote server
        $mysqldumpCmd = $this->commandBuilder->buildExportCommand($dbConfig, $remoteTempFile, $compress);

        if ($this->verbose) {
            $this->output->writeln("Remote export: {$mysqldumpCmd}");
        }

        if (!$this->remoteTransfer->executeRemoteCommand($sshService, $mysqldumpCmd)) {
            return false;
        }

        // Transfer file from remote to local
        if (!$this->remoteTransfer->downloadFile($sshService, $remoteTempFile, $outputPath)) {
            return false;
        }

        // Clean up remote temp file
        $this->remoteTransfer->executeRemoteCommand($sshService, "rm -f {$remoteTempFile}");

        return true;
    }

    /**
     * Import a SQL file into a local database
     */
    public function importLocal(array $dbConfig, string $inputPath): bool
    {
        $this->commandBuilder->validateDatabaseConfig($dbConfig);

        if (!file_exists($inputPath)) {
            throw new RuntimeException("SQL file not found: {$inputPath}");
        }

        $command = $this->commandBuilder->buildImportCommand($dbConfig, $inputPath);

        if ($this->verbose) {
            $this->output->writeln("Executing: {$command}");
        }

        return $this->executeCommand($command);
    }

    /**
     * Import a SQL file into a remote database via SSH
     */
    public function importRemote(array $dbConfig, SshService $sshService, string $inputPath): bool
    {
        $this->commandBuilder->validateDatabaseConfig($dbConfig);

        if (!file_exists($inputPath)) {
            throw new RuntimeException("SQL file not found: {$inputPath}");
        }

        $remoteTempFile = '/tmp/movepress_import_' . uniqid() . (str_ends_with($inputPath, '.gz') ? '.sql.gz' : '.sql');

        // Transfer file to remote
        if (!$this->remoteTransfer->uploadFile($sshService, $inputPath, $remoteTempFile)) {
            return false;
        }

        // Execute import on remote server
        $mysqlCmd = $this->commandBuilder->buildImportCommand($dbConfig, $remoteTempFile);

        if ($this->verbose) {
            $this->output->writeln("Remote import: {$mysqlCmd}");
        }

        if (!$this->remoteTransfer->executeRemoteCommand($sshService, $mysqlCmd)) {
            return false;
        }

        // Clean up remote temp file
        $this->remoteTransfer->executeRemoteCommand($sshService, "rm -f {$remoteTempFile}");

        return true;
    }

    /**
     * Create a backup of a database
     */
    public function backup(array $dbConfig, ?SshService $sshService = null, ?string $backupDir = null): string
    {
        $backupDir = $backupDir ?? sys_get_temp_dir();
        $timestamp = date('Y-m-d_H-i-s');
        $dbName = $dbConfig['name'] ?? 'unknown';
        $filename = "backup_{$dbName}_{$timestamp}.sql.gz";
        $backupPath = $backupDir . '/' . $filename;

        // Always ensure local backup directory exists (backups are downloaded locally)
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
            throw new RuntimeException("Failed to create backup directory: {$backupDir}");
        }

        if ($sshService === null) {
            if (!$this->exportLocal($dbConfig, $backupPath, true)) {
                throw new RuntimeException('Failed to create local backup');
            }
        } else {
            // exportRemote downloads the backup to local $backupPath
            if (!$this->exportRemote($dbConfig, $sshService, $backupPath, true)) {
                throw new RuntimeException('Failed to create remote backup');
            }
        }

        return $backupPath;
    }

    /**
     * Perform search-replace using wp-cli
     */
    public function searchReplace(
        string $wordpressPath,
        string $oldUrl,
        string $newUrl,
        ?SshService $sshService = null,
    ): bool {
        $command = $this->commandBuilder->buildSearchReplaceCommand(
            $this->wpCliBinary,
            $wordpressPath,
            $oldUrl,
            $newUrl,
        );

        if ($sshService !== null) {
            if ($this->verbose) {
                $this->output->writeln("Search-replace: {$oldUrl} → {$newUrl}");
            }

            // For remote execution, we need to transfer movepress PHAR and execute bundled wp-cli from there
            // This ensures we use our bundled wp-cli version, not a global installation
            return $this->executeRemoteWpCli($sshService, $wordpressPath, $oldUrl, $newUrl);
        }

        if ($this->verbose) {
            $this->output->writeln("Search-replace: {$oldUrl} → {$newUrl}");
            $this->output->writeln("Executing: {$command}");
        }

        return $this->executeCommand($command);
    }

    /**
     * Execute bundled wp-cli on remote server by temporarily transferring movepress PHAR
     */
    private function executeRemoteWpCli(
        SshService $sshService,
        string $wordpressPath,
        string $oldUrl,
        string $newUrl,
    ): bool {
        // Get the movepress PHAR path (Phar::running() returns phar:// path or empty string)
        $pharPath = \Phar::running(false);

        if (empty($pharPath)) {
            // Running in dev mode - need to find the actual PHAR or fail gracefully
            throw new RuntimeException(
                'Cannot execute remote wp-cli in development mode. ' . 'Build the PHAR with: ./vendor/bin/box compile',
            );
        }

        // Transfer movepress PHAR to WordPress root (temporary)
        $remotePhar = rtrim($wordpressPath, '/') . '/movepress.phar';

        if ($this->verbose) {
            $this->output->writeln('Transferring movepress PHAR to remote WordPress root for wp-cli execution...');
        }

        if (!$this->remoteTransfer->uploadFile($sshService, $pharPath, $remotePhar)) {
            throw new RuntimeException('Failed to transfer movepress PHAR to remote server');
        }

        // Build command to execute bundled wp-cli from the transferred PHAR
        // Use the PHAR directly with -- to pass wp-cli commands
        $wpBinary = sprintf('php %s --', escapeshellarg($remotePhar));

        $command = $this->commandBuilder->buildSearchReplaceCommand($wpBinary, $wordpressPath, $oldUrl, $newUrl);

        if ($this->verbose) {
            $this->output->writeln('Executing wp-cli search-replace on remote...');
        }

        $success = $this->remoteTransfer->executeRemoteCommand($sshService, $command);

        // Clean up remote PHAR
        if ($this->verbose) {
            $this->output->writeln('Cleaning up temporary PHAR on remote...');
        }
        $this->remoteTransfer->executeRemoteCommand($sshService, "rm -f {$remotePhar}");

        return $success;
    }

    /**
     * Execute a shell command
     */
    private function executeCommand(string $command): bool
    {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->output->writeln('<error>Command failed: ' . $process->getErrorOutput() . '</error>');
            return false;
        }

        if ($this->verbose && $process->getOutput()) {
            $this->output->writeln($process->getOutput());
        }

        return true;
    }

    /**
     * Get wp-cli binary path (always bundled)
     */
    private function getWpCliBinary(): string
    {
        // Use bundled wp-cli PHP entry point (works in PHAR and dev)
        $bundledBootstrap = dirname(__DIR__, 2) . '/vendor/wp-cli/wp-cli/php/boot-fs.php';
        return PHP_BINARY . ' ' . escapeshellarg($bundledBootstrap);
    }

    /**
     * Check if mysqldump is available
     */
    public static function isMysqldumpAvailable(): bool
    {
        $process = Process::fromShellCommandline('which mysqldump');
        $process->run();
        return $process->isSuccessful();
    }

    /**
     * Check if mysql is available
     */
    public static function isMysqlAvailable(): bool
    {
        $process = Process::fromShellCommandline('which mysql');
        $process->run();
        return $process->isSuccessful();
    }
}
