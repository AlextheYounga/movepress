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

    public function __construct(OutputInterface $output, bool $verbose = false)
    {
        $this->output = $output;
        $this->verbose = $verbose;
        
        // Locate wp-cli binary
        $this->wpCliBinary = $this->findWpCliBinary();
    }

    /**
     * Export a local database to a SQL file
     */
    public function exportLocal(array $dbConfig, string $outputPath, bool $compress = true): bool
    {
        $this->validateDatabaseConfig($dbConfig);

        $command = $this->buildMysqldumpCommand($dbConfig, $outputPath, $compress);

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
        bool $compress = true
    ): bool {
        $this->validateDatabaseConfig($dbConfig);

        // Generate remote temp filename
        $remoteTempFile = '/tmp/movepress_export_' . uniqid() . ($compress ? '.sql.gz' : '.sql');

        // Build mysqldump command to run remotely
        $mysqldumpCmd = $this->buildMysqldumpCommand($dbConfig, $remoteTempFile, $compress);

        // Execute mysqldump on remote server
        $sshCommand = $this->buildSshCommand($sshService, $mysqldumpCmd);
        
        if ($this->verbose) {
            $this->output->writeln("Remote export: {$sshCommand}");
        }

        if (!$this->executeCommand($sshCommand)) {
            return false;
        }

        // Transfer file from remote to local using SCP
        $scpCommand = $this->buildScpCommand(
            $sshService,
            $remoteTempFile,
            $outputPath,
            true // from remote
        );

        if ($this->verbose) {
            $this->output->writeln("Transfer: {$scpCommand}");
        }

        if (!$this->executeCommand($scpCommand)) {
            return false;
        }

        // Clean up remote temp file
        $cleanupCommand = $this->buildSshCommand($sshService, "rm -f {$remoteTempFile}");
        $this->executeCommand($cleanupCommand);

        return true;
    }

    /**
     * Import a SQL file into a local database
     */
    public function importLocal(array $dbConfig, string $inputPath): bool
    {
        $this->validateDatabaseConfig($dbConfig);

        if (!file_exists($inputPath)) {
            throw new RuntimeException("SQL file not found: {$inputPath}");
        }

        $command = $this->buildMysqlImportCommand($dbConfig, $inputPath);

        if ($this->verbose) {
            $this->output->writeln("Executing: {$command}");
        }

        return $this->executeCommand($command);
    }

    /**
     * Import a SQL file into a remote database via SSH
     */
    public function importRemote(
        array $dbConfig,
        SshService $sshService,
        string $inputPath
    ): bool {
        $this->validateDatabaseConfig($dbConfig);

        if (!file_exists($inputPath)) {
            throw new RuntimeException("SQL file not found: {$inputPath}");
        }

        // Generate remote temp filename
        $remoteTempFile = '/tmp/movepress_import_' . uniqid() . 
            (str_ends_with($inputPath, '.gz') ? '.sql.gz' : '.sql');

        // Transfer file to remote using SCP
        $scpCommand = $this->buildScpCommand(
            $sshService,
            $inputPath,
            $remoteTempFile,
            false // to remote
        );

        if ($this->verbose) {
            $this->output->writeln("Transfer: {$scpCommand}");
        }

        if (!$this->executeCommand($scpCommand)) {
            return false;
        }

        // Build mysql import command to run remotely
        $mysqlCmd = $this->buildMysqlImportCommand($dbConfig, $remoteTempFile);

        // Execute import on remote server
        $sshCommand = $this->buildSshCommand($sshService, $mysqlCmd);
        
        if ($this->verbose) {
            $this->output->writeln("Remote import: {$sshCommand}");
        }

        if (!$this->executeCommand($sshCommand)) {
            return false;
        }

        // Clean up remote temp file
        $cleanupCommand = $this->buildSshCommand($sshService, "rm -f {$remoteTempFile}");
        $this->executeCommand($cleanupCommand);

        return true;
    }

    /**
     * Create a backup of a database
     */
    public function backup(
        array $dbConfig,
        ?SshService $sshService = null,
        ?string $backupDir = null
    ): string {
        $backupDir = $backupDir ?? sys_get_temp_dir();
        $timestamp = date('Y-m-d_H-i-s');
        $dbName = $dbConfig['name'] ?? 'unknown';
        $filename = "backup_{$dbName}_{$timestamp}.sql.gz";
        $backupPath = $backupDir . '/' . $filename;

        if ($sshService === null) {
            // Local backup
            if (!$this->exportLocal($dbConfig, $backupPath, true)) {
                throw new RuntimeException("Failed to create local backup");
            }
        } else {
            // Remote backup
            if (!$this->exportRemote($dbConfig, $sshService, $backupPath, true)) {
                throw new RuntimeException("Failed to create remote backup");
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
        ?SshService $sshService = null
    ): bool {
        $command = sprintf(
            '%s search-replace %s %s --path=%s --skip-columns=guid --quiet',
            escapeshellarg($this->wpCliBinary),
            escapeshellarg($oldUrl),
            escapeshellarg($newUrl),
            escapeshellarg($wordpressPath)
        );

        if ($sshService !== null) {
            // Execute wp-cli remotely
            $command = $this->buildSshCommand($sshService, $command);
        }

        if ($this->verbose) {
            $this->output->writeln("Search-replace: {$oldUrl} → {$newUrl}");
            $this->output->writeln("Executing: {$command}");
        }

        return $this->executeCommand($command);
    }

    /**
     * Build mysqldump command
     */
    private function buildMysqldumpCommand(array $dbConfig, string $outputPath, bool $compress): string
    {
        $parts = [
            'mysqldump',
            '--user=' . escapeshellarg($dbConfig['user']),
            '--host=' . escapeshellarg($dbConfig['host']),
        ];

        if (!empty($dbConfig['password'])) {
            $parts[] = '--password=' . escapeshellarg($dbConfig['password']);
        }

        // Add common mysqldump options
        $parts[] = '--single-transaction';
        $parts[] = '--quick';
        $parts[] = '--lock-tables=false';

        $parts[] = escapeshellarg($dbConfig['name']);

        if ($compress) {
            $command = implode(' ', $parts) . ' | gzip > ' . escapeshellarg($outputPath);
        } else {
            $command = implode(' ', $parts) . ' > ' . escapeshellarg($outputPath);
        }

        return $command;
    }

    /**
     * Build mysql import command
     */
    private function buildMysqlImportCommand(array $dbConfig, string $inputPath): string
    {
        $parts = [
            'mysql',
            '--user=' . escapeshellarg($dbConfig['user']),
            '--host=' . escapeshellarg($dbConfig['host']),
        ];

        if (!empty($dbConfig['password'])) {
            $parts[] = '--password=' . escapeshellarg($dbConfig['password']);
        }

        $parts[] = escapeshellarg($dbConfig['name']);

        if (str_ends_with($inputPath, '.gz')) {
            $command = 'gunzip < ' . escapeshellarg($inputPath) . ' | ' . implode(' ', $parts);
        } else {
            $command = implode(' ', $parts) . ' < ' . escapeshellarg($inputPath);
        }

        return $command;
    }

    /**
     * Build SSH command for remote execution
     */
    private function buildSshCommand(SshService $sshService, string $remoteCommand): string
    {
        $sshOptions = $sshService->getSshOptions();
        $connectionString = $sshService->buildConnectionString();

        $parts = ['ssh'];
        $parts = array_merge($parts, $sshOptions);
        $parts[] = $connectionString;
        $parts[] = escapeshellarg($remoteCommand);

        return implode(' ', $parts);
    }

    /**
     * Build SCP command for file transfer
     */
    private function buildScpCommand(
        SshService $sshService,
        string $sourcePath,
        string $destPath,
        bool $fromRemote
    ): string {
        $sshOptions = $sshService->getSshOptions();
        $connectionString = $sshService->buildConnectionString();

        // Convert SSH options for SCP
        // SSH returns ['-p', '2222', '-i', '/path/key', '-o', 'StrictHostKeyChecking=no']
        // SCP needs -P for port instead of -p, and can skip -o options
        $scpOptions = [];
        $i = 0;
        while ($i < count($sshOptions)) {
            $option = $sshOptions[$i];
            
            if ($option === '-p' && isset($sshOptions[$i + 1])) {
                // Convert -p to -P for SCP port
                $scpOptions[] = '-P';
                $scpOptions[] = $sshOptions[$i + 1];
                $i += 2;
            } elseif ($option === '-i' && isset($sshOptions[$i + 1])) {
                // Keep SSH key
                $scpOptions[] = $option;
                $scpOptions[] = $sshOptions[$i + 1];
                $i += 2;
            } elseif ($option === '-o') {
                // Skip -o options for SCP
                $i += 2;
            } else {
                $i++;
            }
        }

        $parts = ['scp'];
        $parts = array_merge($parts, $scpOptions);

        if ($fromRemote) {
            $parts[] = $connectionString . ':' . escapeshellarg($sourcePath);
            $parts[] = escapeshellarg($destPath);
        } else {
            $parts[] = escapeshellarg($sourcePath);
            $parts[] = $connectionString . ':' . escapeshellarg($destPath);
        }

        return implode(' ', $parts);
    }

    /**
     * Execute a shell command
     */
    private function executeCommand(string $command): bool
    {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600); // 1 hour timeout for large databases
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
     * Validate database configuration has required fields
     */
    private function validateDatabaseConfig(array $dbConfig): void
    {
        $required = ['name', 'user', 'host'];
        foreach ($required as $field) {
            if (empty($dbConfig[$field])) {
                throw new RuntimeException("Database configuration missing required field: {$field}");
            }
        }
    }

    /**
     * Find wp-cli binary (bundled or system)
     */
    private function findWpCliBinary(): string
    {
        // Check for bundled wp-cli first
        $bundledPath = dirname(__DIR__, 2) . '/vendor/bin/wp';
        if (file_exists($bundledPath)) {
            return $bundledPath;
        }

        // Fall back to system wp-cli
        return 'wp';
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

    /**
     * Check if wp-cli is available
     */
    public static function isWpCliAvailable(): bool
    {
        $process = Process::fromShellCommandline('which wp');
        $process->run();
        return $process->isSuccessful();
    }
}
