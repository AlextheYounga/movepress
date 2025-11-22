<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Movepress\Services\FileSearchReplaceService;
use Movepress\Services\FileSyncPreviewService;
use Movepress\Services\RsyncService;
use Movepress\Services\SshService;
use Movepress\Services\Sync\DatabaseSyncController;
use Movepress\Services\Sync\FileSyncController;
use Movepress\Services\Sync\LocalStagingService;
use Movepress\Services\ValidationService;
use Movepress\Console\MovepressStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

abstract class AbstractSyncCommand extends Command
{
    protected OutputInterface $output;
    protected MovepressStyle $io;
    protected bool $dryRun;
    protected bool $verbose;
    protected bool $noInteraction;
    protected array $sourceEnv;
    protected array $destEnv;
    protected array $flags;
    private bool $remoteFilesPreparedLocally = false;
    private array $excludePatterns = [];

    /**
     * Return git-tracked files relative to the provided WordPress path.
     *
     * @return array<string>
     */
    public function getGitTrackedFiles(string $wordpressPath): array
    {
        $resolvedPath = realpath($wordpressPath);
        if ($resolvedPath === false) {
            return [];
        }

        $rootProcess = Process::fromShellCommandline('git rev-parse --show-toplevel', $resolvedPath);
        $rootProcess->run();
        if (!$rootProcess->isSuccessful()) {
            return [];
        }

        $repoRoot = rtrim(trim($rootProcess->getOutput()), "/ \n");
        if ($repoRoot === '') {
            return [];
        }

        $normalizedRoot = rtrim($repoRoot, '/');
        $normalizedPath = rtrim($resolvedPath, '/');
        if (!str_starts_with($normalizedPath, $normalizedRoot)) {
            return [];
        }

        $relativePrefix = ltrim(str_replace($normalizedRoot, '', $normalizedPath), '/');
        $pathspec = $relativePrefix === '' ? '.' : $relativePrefix;

        $listProcess = Process::fromShellCommandline(
            sprintf('git -C %s ls-files -z -- %s', escapeshellarg($repoRoot), escapeshellarg($pathspec)),
        );
        $listProcess->run();
        if (!$listProcess->isSuccessful()) {
            return [];
        }

        $raw = rtrim($listProcess->getOutput(), "\0");
        if ($raw === '') {
            return [];
        }

        $files = [];
        foreach (explode("\0", $raw) as $file) {
            if ($relativePrefix !== '') {
                $prefix = $relativePrefix . '/';
                if (str_starts_with($file, $prefix)) {
                    $file = substr($file, strlen($prefix));
                } elseif ($file === $relativePrefix) {
                    $file = basename($file);
                } else {
                    continue;
                }
            }

            if ($file !== '') {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    protected function configureArguments(): void
    {
        $this->addArgument('source', InputArgument::REQUIRED, 'Source environment name')->addArgument(
            'destination',
            InputArgument::REQUIRED,
            'Destination environment name',
        );
    }

    protected function configureOptions(): void
    {
        $this->addOption('db', null, InputOption::VALUE_NONE, 'Sync database only')
            ->addOption('files', null, InputOption::VALUE_NONE, 'Sync files (uploads, caches, etc.)')
            ->addOption(
                'delete',
                null,
                InputOption::VALUE_NONE,
                'Delete destination files missing from source when syncing files',
            )
            ->addOption(
                'include-git-tracked',
                null,
                InputOption::VALUE_NONE,
                'Include git-tracked files instead of excluding them automatically',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be transferred without actually doing it',
            )
            ->addOption(
                'no-backup',
                null,
                InputOption::VALUE_NONE,
                'Skip backing up destination database before import',
            );
    }

    protected function initializeContext(
        OutputInterface $output,
        MovepressStyle $io,
        array $sourceEnv,
        array $destEnv,
        array $flags,
        bool $dryRun,
        bool $verbose,
        bool $noInteraction,
    ): void {
        $this->output = $output;
        $this->io = $io;
        $this->sourceEnv = $sourceEnv;
        $this->destEnv = $destEnv;
        $this->flags = $flags;
        $this->dryRun = $dryRun;
        $this->verbose = $verbose;
        $this->noInteraction = $noInteraction;
        $this->remoteFilesPreparedLocally = false;
    }

    protected function parseSyncFlags(InputInterface $input): array
    {
        $syncDb = $input->getOption('db');
        $syncFiles = $input->getOption('files');
        $deleteMissingFiles = $syncFiles ? (bool) $input->getOption('delete') : false;
        $skipBackup = $syncDb ? (bool) $input->getOption('no-backup') : false;

        // If no flags are set, sync everything
        if (!$syncDb && !$syncFiles) {
            $syncDb = true;
            $syncFiles = true;
            $skipBackup = (bool) $input->getOption('no-backup');
            $deleteMissingFiles = (bool) $input->getOption('delete');
        }

        return [
            'db' => $syncDb,
            'files' => $syncFiles,
            'delete' => $deleteMissingFiles,
            'no_backup' => $skipBackup,
            'include_git_tracked' => (bool) $input->getOption('include-git-tracked'),
        ];
    }

    protected function validateEnvironment(string $name, array $env): void
    {
        if (empty($env['wordpress_path'])) {
            throw new \RuntimeException("Environment '{$name}' missing 'wordpress_path' configuration");
        }

        if (empty($env['url'])) {
            throw new \RuntimeException("Environment '{$name}' missing 'url' configuration");
        }

        if (empty($env['database'])) {
            throw new \RuntimeException("Environment '{$name}' missing 'database' configuration");
        }
    }

    protected function displayConfiguration(string $source, string $destination): void
    {
        $this->io->section('Configuration');

        $items = [
            "Source: {$source}",
            "Destination: {$destination}",
            'Database: ' . ($this->flags['db'] ? '✓' : '✗'),
            'Files: ' . ($this->flags['files'] ? '✓' : '✗'),
            'Dry Run: ' . ($this->dryRun ? 'Yes' : 'No'),
            'Delete Missing Files: ' . ($this->flags['delete'] ? 'Yes' : 'No'),
            'Create DB Backup: ' . ($this->flags['db'] ? ($this->flags['no_backup'] ? 'No' : 'Yes') : 'N/A'),
        ];

        $this->io->listing($items);

        if ($this->dryRun) {
            $this->io->warning('DRY RUN MODE - No changes will be made');
        }

        $this->io->note([
            'Tracked files (themes, plugins, core) should be deployed via Git.',
            'Use: git push ' . $destination . ' master',
        ]);
    }

    protected function syncFiles(array $excludes, ?SshService $remoteSsh): bool
    {
        if ($this->isRemoteToRemoteSync()) {
            $this->io->error('Remote-to-remote file syncs are not supported. Sync via a local environment.');
            return false;
        }

        $executor = new FileSyncController($this->output, $this->io, $this->dryRun, $this->verbose);
        $sourcePath = $this->buildPath($this->sourceEnv);
        $destPath = $this->buildPath($this->destEnv);
        $this->excludePatterns = $this->prepareFileFilters($excludes);

        // Confirm file sync operation with user
        if (!$this->dryRun && !$this->noInteraction && !$this->confirmFileSyncOperation($sourcePath, $destPath)) {
            $this->io->writeln('File sync cancelled.');
            return false;
        }

        $stagingService = null;
        $stagedPath = null;

        if ($this->shouldStageRemoteFiles()) {
            $this->io->text('Staging files locally for remote upload...');
            $stagingService = new LocalStagingService($this->output, $this->verbose);
            $stagedPath = $stagingService->stage($sourcePath, $this->excludePatterns, $this->flags['delete']);
            $this->applySearchReplaceToStagedFiles($stagedPath);
            $this->remoteFilesPreparedLocally = true;
            $sourcePath = $stagedPath;
        }

        try {
            return $executor->sync($sourcePath, $destPath, $this->excludePatterns, $remoteSsh, $this->flags['delete']);
        } finally {
            if ($stagingService !== null) {
                $stagingService->cleanup($stagedPath);
            }
        }
    }

    protected function processSyncedFiles(): bool
    {
        if ($this->dryRun || !$this->flags['files']) {
            return true;
        }

        $this->io->text('Updating hardcoded URLs in synced files...');

        $sourceUrl = $this->sourceEnv['url'];
        $destUrl = $this->destEnv['url'];
        $basePath = rtrim($this->destEnv['wordpress_path'], '/');
        $targetPath = $this->getFileReplacementPath($basePath, !isset($this->destEnv['ssh']));

        if (isset($this->destEnv['ssh'])) {
            if ($this->remoteFilesPreparedLocally) {
                $this->io->writeln('✓ Remote files were updated locally before upload');
            } else {
                $this->io->warning('Remote replacements were skipped (no staged files available).');
            }
            return true;
        }

        $service = new FileSearchReplaceService($this->verbose);
        $result = $service->replaceInPath($targetPath, $sourceUrl, $destUrl);
        $this->io->writeln(
            sprintf('✓ Updated %d files (checked %d)', $result['filesModified'], $result['filesChecked']),
        );

        return true;
    }

    private function getFileReplacementPath(string $basePath, bool $checkLocal = true): string
    {
        $wpContentPath = $basePath . '/wp-content';

        if ($checkLocal) {
            return is_dir($wpContentPath) ? $wpContentPath : $basePath;
        }

        return $wpContentPath;
    }

    private function applySearchReplaceToStagedFiles(string $stagedPath): void
    {
        $sourceUrl = $this->sourceEnv['url'];
        $destUrl = $this->destEnv['url'];
        $targetPath = $stagedPath;

        $service = new FileSearchReplaceService($this->verbose);
        $result = $service->replaceInPath($targetPath, $sourceUrl, $destUrl);

        $this->io->writeln(
            sprintf(
                '✓ Staged files updated (%d modified, %d checked)',
                $result['filesModified'],
                $result['filesChecked'],
            ),
        );
    }

    /**
     * Build merged array of excludes based on git tracked files and movefile excludes.
     *
     * @return array
     */
    private function prepareFileFilters(array $baseExcludes): array
    {
        $excludes = array_values(array_unique(array_merge($baseExcludes, ['.git', '.git/', '.gitignore'])));

        if ($this->flags['include_git_tracked']) {
            $this->io->text('Including git-tracked files (flag provided).');
            return $excludes;
        }

        $localPath = $this->getLocalWordpressPath();
        if ($localPath === null) {
            return $excludes;
        }

        $trackedFiles = $this->getGitTrackedFiles($localPath);
        if (!empty($trackedFiles)) {
            $excludes = array_values(array_unique(array_merge($excludes, $trackedFiles)));
            $this->io->text(sprintf('Excluding %d git-tracked files from sync', count($trackedFiles)));
        } else {
            // Fall back to default tracked patterns to avoid syncing code when git data is unavailable
            $defaults = $this->getDefaultTrackedPatterns();
            $excludes = array_values(array_unique(array_merge($excludes, $defaults)));
            $this->io->text('Git metadata not available; using default code excludes for safety.');
        }

        return $excludes;
    }

    private function getLocalWordpressPath(): ?string
    {
        if (!isset($this->sourceEnv['ssh'])) {
            return $this->sourceEnv['wordpress_path'];
        }

        if (!isset($this->destEnv['ssh'])) {
            return $this->destEnv['wordpress_path'];
        }

        return null;
    }

    /**
     * Default patterns representing tracked code assets that should not be rsynced.
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
            'wp-content/themes/',
            'wp-content/plugins/',
            'wp-includes/',
            'wp-admin/',
        ];
    }

    private function shouldStageRemoteFiles(): bool
    {
        if ($this->dryRun) {
            return false;
        }

        if (!isset($this->destEnv['ssh'])) {
            return false;
        }

        if (isset($this->sourceEnv['ssh'])) {
            return false;
        }

        return true;
    }

    private function isRemoteToRemoteSync(): bool
    {
        return isset($this->sourceEnv['ssh']) && isset($this->destEnv['ssh']);
    }

    protected function syncDatabase(bool $noBackup): bool
    {
        $executor = new DatabaseSyncController($this->output, $this->io, $this->dryRun, $this->verbose);

        return $executor->sync(
            $this->sourceEnv,
            $this->destEnv,
            $noBackup,
            $this->getSshService($this->sourceEnv),
            $this->getSshService($this->destEnv),
        );
    }

    protected function buildPath(array $env): string
    {
        $path = $env['wordpress_path'];

        if (isset($env['ssh'])) {
            $sshService = new SshService($env['ssh']);
            $connectionString = $sshService->buildConnectionString();
            return "{$connectionString}:{$path}";
        }

        return $path;
    }

    protected function getSshService(array $env): ?SshService
    {
        if (isset($env['ssh'])) {
            return new SshService($env['ssh']);
        }

        return null;
    }

    protected function validatePrerequisites(): bool
    {
        $validator = new ValidationService($this->io);
        return $validator->validatePrerequisites($this->sourceEnv, $this->destEnv, $this->flags, $this->dryRun);
    }

    protected function confirmDestructiveOperation(string $destination): bool
    {
        $validator = new ValidationService($this->io);
        return $validator->confirmDestructiveOperation($destination, $this->flags, $this->noInteraction);
    }

    /**
     * Preview and confirm file sync operation with the user.
     */
    private function confirmFileSyncOperation(string $sourcePath, string $destPath): bool
    {
        $this->io->section('File Sync Preview');

        $localSourcePath = $this->getLocalPath($sourcePath);

        // Scan and display all directories with file counts
        $this->io->text('Analyzing directories to sync...');
        
        $preview = new FileSyncPreviewService($this->excludePatterns);
        $directorySummary = $preview->scanDirectoriesWithCounts($localSourcePath, $localSourcePath);

        if (!empty($directorySummary)) {
            $this->io->writeln("\nDirectories and files to sync:");
            
            $displayLimit = 50;
            $displayed = 0;
            
            foreach ($directorySummary as $item) {
                if ($displayed >= $displayLimit) {
                    $remaining = count($directorySummary) - $displayed;
                    $this->io->writeln("  ... and {$remaining} more directories");
                    break;
                }
                
                if ($item['type'] === 'file') {
                    $this->io->writeln("  • {$item['path']}");
                } else {
                    $fileCount = number_format($item['count']);
                    $this->io->writeln("  • {$item['path']}/ <comment>({$fileCount} files)</comment>");
                }
                $displayed++;
            }
            $this->io->newLine();
        }

        if ($this->flags['delete']) {
            $this->io->warning('Delete mode is enabled - files missing from source will be removed from destination.');
        }

        $exclusionCount = count($this->excludePatterns);
        $this->io->writeln("Exclusion patterns applied: <info>{$exclusionCount}</info>");

        if ($this->verbose && $exclusionCount > 0) {
            $this->io->writeln("\nExcluding:");
            $displayLimit = 20;
            $patterns = array_slice($this->excludePatterns, 0, $displayLimit);
            foreach ($patterns as $pattern) {
                $this->io->writeln("  - {$pattern}");
            }
            if ($exclusionCount > $displayLimit) {
                $remaining = $exclusionCount - $displayLimit;
                $this->io->writeln("  ... and {$remaining} more");
            }
        }

        return $this->io->confirm('Proceed with file sync?', false);
    }    /**
     * Get local path from a potentially remote path string.
     */
    private function getLocalPath(string $path): string
    {
        if (str_contains($path, ':')) {
            // Extract path after colon for remote paths (user@host:/path -> /path)
            return substr($path, strpos($path, ':') + 1);
        }
        return $path;
    }

    /**
     * Check if path is local (not remote SSH path).
     */
    private function isLocalPath(string $path): bool
    {
        return !str_contains($path, ':');
    }

    /**
     * Format bytes into human-readable size.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= 1 << 10 * $pow;

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
