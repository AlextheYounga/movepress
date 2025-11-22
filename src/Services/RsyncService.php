<?php

declare(strict_types=1);

namespace Movepress\Services;

use Movepress\Console\CommandFormatter;
use Movepress\Console\MovepressStyle;
use Movepress\Services\Sync\RsyncDryRunSummary;
use Movepress\Services\Sync\RsyncStats;
use Movepress\Services\Sync\RsyncStatsParser;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use xobotyi\rsync\Rsync;

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
        MovepressStyle::registerCustomStyles($this->output);
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
        $includes = [];
        $finalExcludes = $excludes;

        $gitignorePatterns = $this->getGitignorePatterns($gitignorePath);

        if (!empty($gitignorePatterns['include'])) {
            // Honor .gitignore by including only ignored files, then exclude everything else
            $includes = $gitignorePatterns['include'];
            $finalExcludes[] = '*';
        } elseif (!empty($gitignorePatterns['exclude'])) {
            // Fallback: exclude common tracked files when no .gitignore exists
            $finalExcludes = array_merge($finalExcludes, $gitignorePatterns['exclude']);
        }

        // Always avoid syncing VCS metadata, even if user excludes are missing
        $finalExcludes = array_values(array_unique(array_merge($finalExcludes, ['.git', '.git/'])));

        return $this->sync($sourcePath, $destPath, $finalExcludes, $includes, $sshService, null, $delete);
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
        array $includes = [],
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
        $command = $this->buildRsyncCommand($sourcePath, $destPath, $excludes, $includes, $sshService, $delete);

        if ($this->verbose || $this->dryRun) {
            $this->output->writeln(sprintf('<cmd>› %s</cmd>', CommandFormatter::forDisplay($command)));
        }

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600); // 1 hour timeout for large syncs

        $capturedOutput = '';
        $process->run(function ($type, $buffer) use (&$capturedOutput) {
            $capturedOutput .= $buffer;
            if ($this->verbose || $type === Process::OUT) {
                $this->output->write($buffer, false, OutputInterface::OUTPUT_RAW);
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
        array $includes,
        ?SshService $sshService,
        bool $delete = false,
    ): string {
        $rsync = new Rsync();
        $rsync
            ->setOption(Rsync::OPT_ARCHIVE)
            ->setOption(Rsync::OPT_VERBOSE)
            ->setOption(Rsync::OPT_COMPRESS)
            ->setOption(Rsync::OPT_STATS)
            ->setOption(Rsync::OPT_PROGRESS)
            ->setOption(Rsync::OPT_OMIT_DIR_TIMES);

        if ($delete) {
            $rsync->setOption(Rsync::OPT_DELETE);
        }

        if ($this->dryRun) {
            $rsync
                ->setOption(Rsync::OPT_DRY_RUN)
                ->setOption(Rsync::OPT_ITEMIZE_CHANGES)
                ->setOption(Rsync::OPT_OUT_FORMAT, 'INFO:%i:%l:%n%L');
        }

        if (!empty($excludes)) {
            $rsync->setOption(Rsync::OPT_EXCLUDE, $excludes);
        }

        if (!empty($includes)) {
            $rsync->setOption(Rsync::OPT_INCLUDE, $includes);
        }

        if ($sshService) {
            $sshCommand = trim('ssh ' . implode(' ', $sshService->getSshOptions()));
            $rsync->setSSH(
                new class ($sshCommand) extends \xobotyi\rsync\SSH {
                    public function __construct(private readonly string $command) {}

                    public function __toString(): string
                    {
                        return $this->command;
                    }
                },
            );
        }

        // Ensure trailing slash on source for proper sync behavior
        $source = rtrim($source, '/') . '/';

        $rsync->setParameters([escapeshellarg($source), escapeshellarg($dest)]);

        return (string) $rsync;
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
     * Return include/exclude patterns derived from .gitignore, or fall back to default excludes.
     *
     * @return array{include: array, exclude: array}
     */
    private function getGitignorePatterns(?string $gitignorePath): array
    {
        if ($gitignorePath === null || !file_exists($gitignorePath)) {
            return ['include' => [], 'exclude' => $this->getDefaultTrackedPatterns()];
        }

        if ($this->gitignorePatterns !== null) {
            return ['include' => $this->gitignorePatterns, 'exclude' => []];
        }

        $patterns = [];
        $lines = file($gitignorePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return ['include' => [], 'exclude' => $this->getDefaultTrackedPatterns()];
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            $patterns = array_merge($patterns, $this->expandIncludePattern($line));
        }

        $this->gitignorePatterns = $patterns;
        return ['include' => $patterns, 'exclude' => []];
    }

    /**
     * Ensure parent directories are included so rsync can descend into ignored paths.
     *
     * @return array<string>
     */
    private function expandIncludePattern(string $pattern): array
    {
        $expanded = [];

        if (!str_contains($pattern, '/')) {
            // File glob only; keep as-is so it matches files
            return [$pattern];
        }

        // Split on slashes to build parent directories
        $parts = explode('/', trim($pattern, '/'));
        $current = '';
        foreach ($parts as $index => $part) {
            $current .= ($current === '' ? '' : '/') . $part;
            $expanded[] = $current . '/';

            // Last part: add recursive include for files within
            if ($index === count($parts) - 1) {
                $expanded[] = $current . '/**';
            }
        }

        return array_values(array_unique($expanded));
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
            '*.xml',
            '*.yml',
            '*.yaml',
            '.git/',
            '.git',
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
