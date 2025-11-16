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
        string $wordpressPath,
        string $oldUrl,
        string $newUrl,
        ?SshService $sshService = null,
    ): bool {
        if ($this->verbose) {
            $this->output->writeln("Search-replace: {$oldUrl} → {$newUrl}");
        }

        if ($sshService !== null) {
            return $this->executeRemoteSearchReplace($sshService, $wordpressPath, $oldUrl, $newUrl);
        }

        return $this->executeLocalSearchReplace($wordpressPath, $oldUrl, $newUrl);
    }

    /**
     * Execute search-replace locally using wp-cli as a library
     */
    private function executeLocalSearchReplace(string $wordpressPath, string $oldUrl, string $newUrl): bool
    {
        try {
            // Load wp-cli classes from bundled vendor
            $vendorPath = dirname(__DIR__, 2) . '/vendor';
            require_once $vendorPath . '/autoload.php';

            // WordPress is already running, just instantiate and call the command
            $searchReplace = new \Search_Replace_Command();
            $searchReplace->__invoke(
                [$oldUrl, $newUrl],
                [
                    'skip-columns' => 'guid',
                    'quiet' => true,
                ],
            );

            return true;
        } catch (\Exception $e) {
            $this->output->writeln('<error>Search-replace failed: ' . $e->getMessage() . '</error>');
            return false;
        }
    }

    /**
     * Execute search-replace on remote server using wp-cli as a library
     */
    private function executeRemoteSearchReplace(
        SshService $sshService,
        string $wordpressPath,
        string $oldUrl,
        string $newUrl,
    ): bool {
        // Generate PHP script that uses wp-cli as a library
        // WordPress is already running on the remote server
        $script = $this->generateSearchReplaceScript($wordpressPath, $oldUrl, $newUrl);
        $remoteTempScript = rtrim($wordpressPath, '/') . '/movepress-search-replace-' . uniqid() . '.php';

        if ($this->verbose) {
            $this->output->writeln('Transferring search-replace script to remote...');
        }

        // Create temporary script file locally
        $localTempScript = sys_get_temp_dir() . '/movepress-search-replace-' . uniqid() . '.php';
        file_put_contents($localTempScript, $script);

        // Transfer script to remote
        if (!$this->remoteTransfer->uploadFile($sshService, $localTempScript, $remoteTempScript)) {
            unlink($localTempScript);
            throw new RuntimeException('Failed to transfer search-replace script to remote server');
        }

        unlink($localTempScript);

        // Execute the script
        if ($this->verbose) {
            $this->output->writeln('Executing wp-cli search-replace on remote...');
        }

        $command = sprintf('cd %s && php %s', escapeshellarg($wordpressPath), escapeshellarg($remoteTempScript));
        $success = $this->remoteTransfer->executeRemoteCommand($sshService, $command);

        // Clean up remote script
        if ($this->verbose) {
            $this->output->writeln('Cleaning up temporary script on remote...');
        }
        $this->remoteTransfer->executeRemoteCommand($sshService, "rm -f {$remoteTempScript}");

        return $success;
    }

    /**
     * Generate PHP script for remote search-replace execution
     */
    private function generateSearchReplaceScript(string $wordpressPath, string $oldUrl, string $newUrl): string
    {
        $oldUrlEscaped = addslashes($oldUrl);
        $newUrlEscaped = addslashes($newUrl);
        $wordpressPathEscaped = addslashes($wordpressPath);

        $script = "<?php\n";
        $script .= "/**\n";
        $script .= " * Movepress search-replace script\n";
        $script .= " * WordPress is already running, we just use wp-cli as a library\n";
        $script .= " */\n\n";
        $script .= "// Find vendor autoloader\n";
        $script .= "\$vendorPaths = [\n";
        $script .= "    '{$wordpressPathEscaped}/vendor/autoload.php',\n";
        $script .= "    '{$wordpressPathEscaped}/../vendor/autoload.php',\n";
        $script .= "    '{$wordpressPathEscaped}/wp-content/vendor/autoload.php',\n";
        $script .= "];\n\n";
        $script .= "\$autoloaderFound = false;\n";
        $script .= "foreach (\$vendorPaths as \$vendorPath) {\n";
        $script .= "    if (file_exists(\$vendorPath)) {\n";
        $script .= "        require_once \$vendorPath;\n";
        $script .= "        \$autoloaderFound = true;\n";
        $script .= "        break;\n";
        $script .= "    }\n";
        $script .= "}\n\n";
        $script .= "if (!\$autoloaderFound) {\n";
        $script .= "    fwrite(STDERR, \"Error: Could not find vendor autoloader\\n\");\n";
        $script .= "    exit(1);\n";
        $script .= "}\n\n";
        $script .= "try {\n";
        $script .= "    // WordPress is already running, just use wp-cli classes\n";
        $script .= "    \$searchReplace = new Search_Replace_Command();\n";
        $script .= "    \$searchReplace->__invoke(\n";
        $script .= "        ['{$oldUrlEscaped}', '{$newUrlEscaped}'],\n";
        $script .= "        [\n";
        $script .= "            'skip-columns' => 'guid',\n";
        $script .= "            'quiet' => true,\n";
        $script .= "        ]\n";
        $script .= "    );\n";
        $script .= "    exit(0);\n";
        $script .= "} catch (Exception \$e) {\n";
        $script .= "    fwrite(STDERR, 'Error: ' . \$e->getMessage() . \"\\n\");\n";
        $script .= "    exit(1);\n";
        $script .= "}\n";

        return $script;
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
