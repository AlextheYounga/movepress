<?php

declare(strict_types=1);

namespace Movepress\Services;

use Movepress\Application;
use RuntimeException;
use Search_Replace_Command;
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
     * Perform search-replace using wp-cli as a library
     */
    public function searchReplace(
        array $dbConfig,
        string $wordpressPath,
        string $oldUrl,
        string $newUrl,
        ?SshService $sshService = null,
    ): bool {
        if ($this->verbose) {
            $this->output->writeln("Search-replace: {$oldUrl} → {$newUrl}");
        }

        if ($sshService !== null) {
            return $this->executeRemoteSearchReplace($sshService, $wordpressPath, $oldUrl, $newUrl, $dbConfig);
        }

        return $this->executeLocalSearchReplace($wordpressPath, $oldUrl, $newUrl, $dbConfig);
    }

    /**
     * Execute search-replace locally using bundled wp-cli classes directly
     */
    private function executeLocalSearchReplace(
        string $wordpressPath,
        string $oldUrl,
        string $newUrl,
        array $dbConfig,
    ): bool {
        try {
            Application::loadWpCliClasses();

            define('WP_USE_THEMES', false);
            $this->bootstrapWordpressEnvironment($dbConfig, $newUrl);

            if (!file_exists($wordpressPath . '/wp-load.php')) {
                throw new \RuntimeException("WordPress not found at: {$wordpressPath}");
            }

            require_once $wordpressPath . '/wp-load.php';

            if (!defined('WP_CLI')) {
                define('WP_CLI', true);
            }

            // Initialize WP_CLI Runner with minimal config using reflection
            $runner = \WP_CLI::get_runner();
            $reflection = new \ReflectionClass($runner);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $configProperty->setValue($runner, ['quiet' => !$this->verbose]);

            if ($this->verbose) {
                $this->output->writeln('Executing search-replace locally...');
            }

            $searchReplace = new Search_Replace_Command();
            $searchReplace->__invoke(
                [$oldUrl, $newUrl],
                [
                    'skip-columns' => 'guid',
                    'quiet' => !$this->verbose,
                    'all-tables' => true,
                ],
            );

            return true;
        } catch (\Exception $e) {
            $this->output->writeln('<error>Search-replace failed: ' . $e->getMessage() . '</error>');
            return false;
        }
    }

    /**
     * Execute search-replace on remote server by calling movepress post-import command
     */
    private function executeRemoteSearchReplace(
        SshService $sshService,
        string $wordpressPath,
        string $oldUrl,
        string $newUrl,
        array $dbConfig,
    ): bool {
        if ($this->verbose) {
            $this->output->writeln('Executing search-replace on remote via movepress post-import...');
        }

        $remotePharPath = '/usr/local/bin/movepress';
        $envExports = $this->buildDatabaseEnvExportString($dbConfig);
        $command = sprintf(
            'cd %s && %s%s post-import %s %s',
            escapeshellarg($wordpressPath),
            $envExports !== '' ? $envExports . ' ' : '',
            $remotePharPath,
            escapeshellarg($oldUrl),
            escapeshellarg($newUrl),
        );

        return $this->remoteTransfer->executeRemoteCommand($sshService, $command);
    }

    /**
     * Prepare environment variables and server globals so WordPress boots with provided credentials and URL context
     */
    private function bootstrapWordpressEnvironment(array $dbConfig, string $targetUrl): void
    {
        foreach ($this->buildDatabaseEnvVars($dbConfig) as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }

        $parsed = parse_url($targetUrl);
        $hostOnly = $parsed !== false && isset($parsed['host']) ? $parsed['host'] : 'localhost';
        $scheme = $parsed !== false && isset($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : 'http';
        $isHttps = $scheme === 'https';
        $port = $parsed !== false && isset($parsed['port']) ? (int) $parsed['port'] : ($isHttps ? 443 : 80);
        $hostHeader = $hostOnly;
        if ($parsed !== false && isset($parsed['port'])) {
            $hostHeader .= ':' . $parsed['port'];
        }

        $_SERVER['HTTP_HOST'] = $hostHeader;
        $_SERVER['SERVER_NAME'] = $hostOnly;
        $_SERVER['SERVER_PORT'] = (string) $port;
        $_SERVER['REQUEST_SCHEME'] = $isHttps ? 'https' : 'http';
        $_SERVER['HTTPS'] = $isHttps ? 'on' : 'off';
    }

    /**
     * @return array<string,string>
     */
    private function buildDatabaseEnvVars(array $dbConfig): array
    {
        $host = (string) ($dbConfig['host'] ?? '');
        $user = (string) ($dbConfig['user'] ?? '');
        $password = (string) ($dbConfig['password'] ?? '');
        $name = (string) ($dbConfig['name'] ?? '');

        return [
            'WORDPRESS_DB_HOST' => $host,
            'WORDPRESS_DB_USER' => $user,
            'WORDPRESS_DB_PASSWORD' => $password,
            'WORDPRESS_DB_NAME' => $name,
            'DB_HOST' => $host,
            'DB_USER' => $user,
            'DB_PASSWORD' => $password,
            'DB_NAME' => $name,
            'MYSQL_HOST' => $host,
            'MYSQL_USER' => $user,
            'MYSQL_PASSWORD' => $password,
            'MYSQL_DATABASE' => $name,
        ];
    }

    private function buildDatabaseEnvExportString(array $dbConfig): string
    {
        $parts = [];
        foreach ($this->buildDatabaseEnvVars($dbConfig) as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = sprintf('%s=%s', $key, escapeshellarg($value));
        }

        return implode(' ', $parts);
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
