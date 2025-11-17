<?php

declare(strict_types=1);

namespace Movepress\Services;

use Movepress\Services\Sync\RsyncDryRunSummary;
use Movepress\Services\Sync\RsyncStats;
use Movepress\Services\Sync\RsyncStatsParser;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class RsyncService
{
    private OutputInterface $output;
    private bool $dryRun;
    private bool $verbose;
    private ?array $gitignorePatterns = null;
    private ?RsyncStats $lastStats = null;
    private ?RsyncDryRunSummary $lastDryRunSummary = null;
    private RsyncStatsParser $parser;

    public function __construct(OutputInterface $output, bool $dryRun = false, bool $verbose = false)
    {
        $this->output = $output;
        $this->dryRun = $dryRun;
        $this->verbose = $verbose;
        $this->parser = new RsyncStatsParser();
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
            if ($this->verbose || $type === Process::OUT) {
                $this->output->write($buffer);
            }
        });

        if (!$process->isSuccessful()) {
            $this->output->writeln('<error>Rsync failed:</error>');
            $this->output->writeln($process->getErrorOutput());
            return false;
        }

        $fullOutput = $capturedOutput !== '' ? $capturedOutput : $process->getOutput() . $process->getErrorOutput();
        $this->lastStats = $this->parser->parse($fullOutput);
        $this->lastDryRunSummary = $this->dryRun ? $this->parser->parseDryRunSummary($fullOutput) : null;

        return true;
    }

    protected function buildRsyncCommand(
        string $source,
        string $dest,
        array $excludes,
        ?SshService $sshService,
        bool $delete = false,
    ): string {
        $options = [
            '-avz', // archive, verbose, compress
            '--stats',
            '--info=progress2',
        ];

        if ($delete) {
            $options[] = '--delete'; // delete files that don't exist on source
        }

        if ($this->dryRun) {
            $options[] = '--dry-run';
            $options[] = '--itemize-changes';
            $options[] = '--out-format=' . escapeshellarg('MPSTAT:%i:%l:%n%L');
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

    public function getLastStats(): ?RsyncStats
    {
        return $this->lastStats;
    }

    public function getLastDryRunSummary(): ?RsyncDryRunSummary
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
}
