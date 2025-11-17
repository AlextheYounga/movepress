<?php

declare(strict_types=1);

namespace Movepress\Services;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class RsyncService
{
    private OutputInterface $output;
    private bool $dryRun;
    private bool $verbose;
    private ?array $gitignorePatterns = null;
    private ?array $lastStats = null;
    private ?array $lastDryRunSummary = null;

    public function __construct(OutputInterface $output, bool $dryRun = false, bool $verbose = false)
    {
        $this->output = $output;
        $this->dryRun = $dryRun;
        $this->verbose = $verbose;
    }

    /**
     * Sync untracked files (based on .gitignore) from source to destination
     *
     * @param string $sourcePath Source path (local or remote)
     * @param string $destPath Destination path (local or remote)
     * @param array $excludes Array of additional exclude patterns
     * @param SshService|null $sshService SSH service for remote connections
     * @param string|null $gitignorePath Path to .gitignore file (optional)
     * @param bool $delete Whether to delete destination files not present on source
     */
    public function syncUntrackedFiles(
        string $sourcePath,
        string $destPath,
        array $excludes = [],
        ?SshService $sshService = null,
        ?string $gitignorePath = null,
        bool $delete = false,
    ): bool {
        // Load .gitignore patterns if available
        $gitignoreExcludes = $this->getGitignoreExcludes($gitignorePath);

        // Merge .gitignore patterns with user-provided excludes
        // .gitignore patterns are inverted - we want to INCLUDE only what Git ignores
        $allExcludes = array_merge($excludes, $gitignoreExcludes);

        return $this->sync($sourcePath, $destPath, $allExcludes, $sshService, null, $delete);
    }

    /**
     * Sync files from source to destination
     *
     * @param string $sourcePath Source path (local or remote)
     * @param string $destPath Destination path (local or remote)
     * @param array $excludes Array of exclude patterns
     * @param SshService|null $sshService SSH service for remote connections
     * @param string|null $subfolder Optional subfolder to sync (e.g., 'wp-content/uploads')
     */
    private function sync(
        string $sourcePath,
        string $destPath,
        array $excludes = [],
        ?SshService $sshService = null,
        ?string $subfolder = null,
        bool $delete = false,
    ): bool {
        // Append subfolder if specified
        if ($subfolder) {
            $sourcePath = rtrim($sourcePath, '/') . '/' . trim($subfolder, '/');
            $destPath = rtrim($destPath, '/') . '/' . trim($subfolder, '/');
        }

        $this->lastStats = null;
        $this->lastDryRunSummary = null;

        // Build rsync command
        $command = $this->buildRsyncCommand($sourcePath, $destPath, $excludes, $sshService, $delete);

        if ($this->verbose || $this->dryRun) {
            $this->output->writeln("<comment>Executing: {$command}</comment>");
        }

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600); // 1 hour timeout for large syncs

        $capturedOutput = '';
        $process->run(function ($type, $buffer) use (&$capturedOutput) {
            $capturedOutput .= $buffer;
            if ($this->verbose) {
                $this->output->write($buffer);
            }
        });

        if (!$process->isSuccessful()) {
            $this->output->writeln('<error>Rsync failed:</error>');
            $this->output->writeln($process->getErrorOutput());
            return false;
        }

        $fullOutput = $capturedOutput !== '' ? $capturedOutput : $process->getOutput() . $process->getErrorOutput();
        $this->lastStats = $this->parseStats($fullOutput);
        $this->lastDryRunSummary = $this->dryRun ? $this->parseDryRunSummary($fullOutput) : null;

        return true;
    }

    private function buildRsyncCommand(
        string $source,
        string $dest,
        array $excludes,
        ?SshService $sshService,
        bool $delete = false,
    ): string {
        $options = [
            '-avz', // archive, verbose, compress
            '--stats',
        ];

        if ($delete) {
            $options[] = '--delete'; // delete files that don't exist on source
        }

        if ($this->dryRun) {
            $options[] = '--dry-run';
            $options[] = '--itemize-changes';
            $options[] = '--out-format=' . escapeshellarg('MPSTAT:%i:%l:%n%L');
        }

        if ($this->verbose) {
            $options[] = '--info=progress2';
        }

        // Add excludes
        foreach ($excludes as $exclude) {
            $options[] = '--exclude=' . escapeshellarg($exclude);
        }

        // Build SSH options if remote connection
        if ($sshService) {
            $sshOptions = implode(' ', $sshService->getSshOptions());
            $options[] = '-e';
            $options[] = escapeshellarg("ssh {$sshOptions}");
        }

        // Ensure trailing slash on source for proper sync behavior
        $source = rtrim($source, '/') . '/';

        $optionsString = implode(' ', $options);
        $sourceEscaped = escapeshellarg($source);
        $destEscaped = escapeshellarg($dest);

        return "rsync {$optionsString} {$sourceEscaped} {$destEscaped}";
    }

    public function getLastStats(): ?array
    {
        return $this->lastStats;
    }

    public function getLastDryRunSummary(): ?array
    {
        return $this->lastDryRunSummary;
    }

    /**
     * Get exclude patterns from .gitignore file
     * Returns patterns of files that Git tracks (to exclude from rsync)
     */
    private function getGitignoreExcludes(?string $gitignorePath): array
    {
        if ($gitignorePath === null || !file_exists($gitignorePath)) {
            // Return default patterns for common tracked files
            return $this->getDefaultTrackedPatterns();
        }

        if ($this->gitignorePatterns !== null) {
            return $this->gitignorePatterns;
        }

        $patterns = [];
        $lines = file($gitignorePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return $this->getDefaultTrackedPatterns();
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Add pattern as-is for rsync
            $patterns[] = $line;
        }

        $this->gitignorePatterns = $patterns;
        return $patterns;
    }

    /**
     * Get default patterns for commonly tracked files to exclude from rsync
     * These are files that should be in Git, not synced via rsync
     */
    private function getDefaultTrackedPatterns(): array
    {
        return [
            '*.php',
            '*.js',
            '*.css',
            '*.json',
            '*.md',
            '*.txt',
            '*.xml',
            '*.yml',
            '*.yaml',
            'wp-content/themes/',
            'wp-content/plugins/',
            'wp-includes/',
            'wp-admin/',
        ];
    }

    /**
     * Check if rsync is available
     */
    public static function isAvailable(): bool
    {
        $process = Process::fromShellCommandline('which rsync');
        $process->run();

        return $process->isSuccessful();
    }

    private function parseStats(string $output): ?array
    {
        if ($output === '') {
            return null;
        }

        $filesTotal = $this->matchInt('/Number of files:\s*([\d,]+)/', $output);
        $filesTransferred = $this->matchInt('/Number of regular files transferred:\s*([\d,]+)/', $output);
        $bytesTotal = $this->matchInt('/Total file size:\s*([\d,]+)\s+bytes/', $output);
        $bytesTransferred = $this->matchInt('/Total transferred file size:\s*([\d,]+)\s+bytes/', $output);

        if ($filesTotal === null && $filesTransferred === null && $bytesTotal === null && $bytesTransferred === null) {
            return null;
        }

        return [
            'files_total' => $filesTotal,
            'files_transferred' => $filesTransferred,
            'bytes_total' => $bytesTotal,
            'bytes_transferred' => $bytesTransferred,
        ];
    }

    private function matchInt(string $pattern, string $subject): ?int
    {
        if (!preg_match($pattern, $subject, $matches)) {
            return null;
        }

        $value = preg_replace('/[,\s]/', '', $matches[1]);
        if ($value === '') {
            return null;
        }

        return (int) $value;
    }

    private function parseDryRunSummary(string $output): ?array
    {
        $matches = [];
        preg_match_all('/^MPSTAT:([^:]+):(\d+):(.+)$/m', $output, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return null;
        }

        $files = 0;
        $bytes = 0;

        foreach ($matches as $match) {
            $flags = $match[1];
            $size = (int) $match[2];

            if (strlen($flags) < 2) {
                continue;
            }

            // Only count files (flag second char 'f')
            if ($flags[1] === 'f') {
                $files++;
                $bytes += $size;
            }
        }

        return [
            'files' => $files,
            'bytes' => $bytes,
        ];
    }
}
