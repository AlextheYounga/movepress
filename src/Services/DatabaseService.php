<?php

declare(strict_types=1);

namespace Movepress\Services;

use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class DatabaseService
{
    private OutputInterface $output;
    private bool $verbose;
    private DatabaseCommandBuilder $commandBuilder;
    private RemoteTransferService $remoteTransfer;

    public function __construct(OutputInterface $output, bool $verbose = false)
    {
        $this->output = $output;
        $this->verbose = $verbose;
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

        $isCompressed = str_ends_with($inputPath, '.gz');
        $remoteTempFile = '/tmp/movepress_import_' . uniqid() . ($isCompressed ? '.sql.gz' : '.sql');
        $remoteSqlPath = $isCompressed ? substr($remoteTempFile, 0, -3) : $remoteTempFile;
        $leaveRemoteDump = (bool) getenv('MOVEPRESS_DEBUG_KEEP_REMOTE_IMPORT');

        // Transfer file to remote
        if (!$this->remoteTransfer->uploadFile($sshService, $inputPath, $remoteTempFile)) {
            return false;
        }

        // Decompress on remote server if needed so we can detect failures explicitly
        if ($isCompressed) {
            $decompressCommand = sprintf('gunzip -f %s', escapeshellarg($remoteTempFile));
            if ($this->verbose) {
                $this->output->writeln("Remote decompress: {$decompressCommand}");
            }
            if (!$this->remoteTransfer->executeRemoteCommand($sshService, $decompressCommand)) {
                if (!$leaveRemoteDump) {
                    $this->remoteTransfer->executeRemoteCommand(
                        $sshService,
                        sprintf('rm -f %s %s', escapeshellarg($remoteTempFile), escapeshellarg($remoteSqlPath)),
                    );
                }
                return false;
            }
        }

        // Execute import on remote server using the decompressed file
        $mysqlCmd = $this->commandBuilder->buildImportCommand($dbConfig, $remoteSqlPath);

        if ($this->verbose) {
            $this->output->writeln("Remote import: {$mysqlCmd}");
        }

        if (!$this->remoteTransfer->executeRemoteCommand($sshService, $mysqlCmd)) {
            $this->maybeCleanupRemoteImportFiles($sshService, $remoteTempFile, $remoteSqlPath, $leaveRemoteDump);
            return false;
        }

        // Clean up remote temp file unless debugging is enabled
        if ($leaveRemoteDump) {
            $this->output->writeln(
                sprintf(
                    '<comment>Leaving remote import artifacts for inspection: %s%s</comment>',
                    $remoteSqlPath,
                    $isCompressed ? ' (original gz left as well)' : '',
                ),
            );
        } else {
            $this->maybeCleanupRemoteImportFiles($sshService, $remoteTempFile, $remoteSqlPath, false);
        }

        return true;
    }

    private function maybeCleanupRemoteImportFiles(
        SshService $sshService,
        string $remoteTempFile,
        string $remoteSqlPath,
        bool $skipCleanup,
    ): void {
        if ($skipCleanup) {
            return;
        }

        $this->remoteTransfer->executeRemoteCommand(
            $sshService,
            sprintf('rm -f %s %s', escapeshellarg($remoteTempFile), escapeshellarg($remoteSqlPath)),
        );
    }

    /**
     * Create a backup of a database
     */
    public function backup(
        array $dbConfig,
        ?SshService $sshService = null,
        ?string $backupDir = null,
        ?string $wordpressPath = null,
    ): string {
        $backupDir = $this->resolveBackupDirectory($backupDir, $wordpressPath);
        $timestamp = date('Y-m-d_H-i-s');
        $dbName = $dbConfig['name'] ?? 'unknown';
        $filename = "backup_{$dbName}_{$timestamp}.sql.gz";
        $backupPath = rtrim($backupDir, '/') . '/' . $filename;

        $this->ensureBackupDirectoryExists($backupDir, $sshService);

        if ($sshService === null) {
            if (!$this->exportLocal($dbConfig, $backupPath, true)) {
                throw new RuntimeException('Failed to create local backup');
            }
        } else {
            $command = $this->commandBuilder->buildExportCommand($dbConfig, $backupPath, true);
            if (!$this->remoteTransfer->executeRemoteCommand($sshService, $command)) {
                throw new RuntimeException('Failed to create remote backup');
            }
        }

        return $backupPath;
    }

    private function resolveBackupDirectory(?string $backupDir, ?string $wordpressPath): string
    {
        if ($backupDir !== null && $backupDir !== '') {
            return rtrim($backupDir, '/');
        }

        if ($wordpressPath !== null && $wordpressPath !== '') {
            return rtrim($wordpressPath, '/') . '/backups';
        }

        return rtrim(sys_get_temp_dir(), '/');
    }

    private function ensureBackupDirectoryExists(string $path, ?SshService $sshService): void
    {
        if ($sshService === null) {
            if (!is_dir($path) && !mkdir($path, 0755, true)) {
                throw new RuntimeException("Failed to create backup directory: {$path}");
            }
            return;
        }

        $command = sprintf('mkdir -p %s', escapeshellarg($path));
        if (!$this->remoteTransfer->executeRemoteCommand($sshService, $command)) {
            throw new RuntimeException("Failed to create remote backup directory: {$path}");
        }
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
