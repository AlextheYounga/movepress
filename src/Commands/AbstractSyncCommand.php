<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Movepress\Services\FileSearchReplaceService;
use Movepress\Services\FileSyncPreviewService;
use Movepress\Services\RsyncService;
use Movepress\Services\SshService;
use Movepress\Services\Sync\DatabaseSyncController;
use Movepress\Services\Sync\FileSyncController;
use Movepress\Services\Sync\InteractivePathSelector;
use Movepress\Services\Sync\LocalStagingService;
use Movepress\Services\Sync\SelectionRulesBuilder;
use Movepress\Services\ValidationService;
use Movepress\Console\MovepressStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
    private array $includePatterns = [];
    private bool $restrictToSelection = false;

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
            'Select which paths to sync (tracked and untracked); movefile excludes still apply.',
            'You can deploy code via Git as usual: git push ' . $destination . ' master',
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
        $this->includePatterns = [];

        $sourceSelectionSsh = isset($this->sourceEnv['ssh']) ? new SshService($this->sourceEnv['ssh']) : null;
        $selectionRules = $this->selectPathsForSync($sourcePath, $sourceSelectionSsh);
        $restrictToSelection = $selectionRules['restrict'];
        $this->restrictToSelection = $restrictToSelection;
        $this->includePatterns = $selectionRules['includes'];

        $stagingService = null;
        $stagedPath = null;
        $previewPath = $sourcePath;
        $isPull = isset($this->sourceEnv['ssh']);

        // Stage files first (for both push and pull) so preview is accurate
        if ($this->shouldStageRemoteFiles()) {
            $message = $isPull
                ? 'Pulling files to temporary local directory from remote source for analysis and search-replace. Hold tight...'
                : 'Staging files to temporary local directory for analysis and search-replace before remote upload. Hold tight...';
            $this->io->text($message);

            $stagingService = new LocalStagingService($this->output, false);
            $stagedPath = $stagingService->stage(
                $sourcePath,
                $this->excludePatterns,
                $this->flags['delete'],
                $this->includePatterns,
                $restrictToSelection,
            );
            $this->applySearchReplaceToStagedFiles($stagedPath);
            $this->remoteFilesPreparedLocally = true;

            // For push: staged files go to remote destination
            // For pull: staged files go to local destination
            if ($isPull) {
                $destPath = $this->destEnv['wordpress_path'];
            }

            $sourcePath = $stagedPath;
            $previewPath = $stagedPath;
        }

        // Confirm file sync operation with user (after staging for accurate preview)
        if (!$this->dryRun && !$this->noInteraction) {
            if (!$this->confirmFileSyncOperation($previewPath, $destPath)) {
                $this->io->writeln('File sync cancelled.');
                if ($stagingService !== null) {
                    $stagingService->cleanup($stagedPath);
                }
                return false;
            }
        }

        try {
            // For pull: no SSH needed since we're syncing staged (local) → local
            $sshForSync = $isPull ? null : $remoteSsh;
            return $executor->sync(
                $sourcePath,
                $destPath,
                $this->excludePatterns,
                $sshForSync,
                $this->flags['delete'],
                $this->includePatterns,
                $restrictToSelection,
            );
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
        $excludes = array_values(array_unique(array_filter($baseExcludes, fn($pattern) => $pattern !== '')));

        if ($this->flags['include_git_tracked']) {
            $this->io->note('Git-tracked files are included by default now; movefile excludes control filtering.');
        }

        return $excludes;
    }

    /**
     * @return array{restrict: bool, includes: array<int, string>}
     */
    private function selectPathsForSync(string $sourcePath, ?SshService $sourceSsh): array
    {
        $rootPath = $this->getLocalPath($sourcePath);
        $selector = new InteractivePathSelector($this->io, $this->excludePatterns, $this->noInteraction, $sourceSsh);
        $builder = new SelectionRulesBuilder();

        try {
            $result = $selector->select($rootPath);
        } catch (\RuntimeException $e) {
            $this->io->warning($e->getMessage());
            $this->io->warning('Proceeding with all paths selected.');
            return ['restrict' => false, 'includes' => []];
        }

        $rules = $builder->build($result['selection'], $result['selectAll']);

        if ($rules['restrict']) {
            $chosen = array_map(
                fn($item) => $item['path'] . ($item['type'] === 'dir' ? '/' : ''),
                $result['selection'],
            );
            $this->io->text('Selected paths: ' . implode(', ', $chosen));
        } else {
            $this->io->text('All paths selected.');
        }

        return $rules;
    }

    private function shouldStageRemoteFiles(): bool
    {
        if ($this->dryRun) {
            return false;
        }

        // Stage for both push (local→remote) and pull (remote→local)
        // This allows accurate preview and search-replace before final sync
        return isset($this->sourceEnv['ssh']) || isset($this->destEnv['ssh']);
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
     * Scans the actual source path (which may be staged) for accurate preview.
     */
    private function confirmFileSyncOperation(string $sourcePath, string $destPath): bool
    {
        $this->io->section('File Sync Preview');

        $localSourcePath = $this->getLocalPath($sourcePath);

        // Scan and display all directories with file counts
        $this->io->text('Analyzing files to sync...');

        $preview = new FileSyncPreviewService(
            $this->excludePatterns,
            $this->includePatterns,
            $this->restrictToSelection,
        );
        $directorySummary = $preview->scanDirectoriesWithCounts($localSourcePath, $localSourcePath);

        if (empty($directorySummary)) {
            $this->io->success('No files need to be synchronized.');
            return true;
        }

        $totalFiles = array_sum(array_column($directorySummary, 'count'));
        $this->io->writeln("\n<info>Total files to sync: " . number_format($totalFiles) . "</info>\n");

        $this->io->writeln('Directories and files:');

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

        if ($this->flags['delete']) {
            $this->io->warning('Delete mode is enabled - files missing from source will be removed from destination.');
        }

        return $this->io->confirm('Proceed with file sync?', false);
    }

    /**
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
}
