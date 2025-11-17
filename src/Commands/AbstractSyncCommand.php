<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Movepress\Services\SshService;
use Movepress\Services\Sync\DatabaseSyncController;
use Movepress\Services\Sync\FileSyncController;
use Movepress\Services\ValidationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractSyncCommand extends Command
{
    protected OutputInterface $output;
    protected SymfonyStyle $io;
    protected bool $dryRun;
    protected bool $verbose;
    protected bool $noInteraction;
    protected array $sourceEnv;
    protected array $destEnv;
    protected array $flags;

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
            ->addOption(
                'untracked-files',
                null,
                InputOption::VALUE_NONE,
                'Sync files not tracked by Git (uploads, caches, etc.)',
            )
            ->addOption(
                'delete',
                null,
                InputOption::VALUE_NONE,
                'Delete destination files missing from source when syncing untracked files',
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
        SymfonyStyle $io,
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
    }

    protected function parseSyncFlags(InputInterface $input): array
    {
        $syncDb = $input->getOption('db');
        $syncUntrackedFiles = $input->getOption('untracked-files');
        $deleteMissingFiles = $syncUntrackedFiles ? (bool) $input->getOption('delete') : false;
        $skipBackup = $syncDb ? (bool) $input->getOption('no-backup') : false;

        // If no flags are set, sync everything
        if (!$syncDb && !$syncUntrackedFiles) {
            $syncDb = true;
            $syncUntrackedFiles = true;
            $skipBackup = (bool) $input->getOption('no-backup');
            $deleteMissingFiles = (bool) $input->getOption('delete');
        }

        return [
            'db' => $syncDb,
            'untracked_files' => $syncUntrackedFiles,
            'delete' => $deleteMissingFiles,
            'no_backup' => $skipBackup,
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
            'Untracked Files: ' . ($this->flags['untracked_files'] ? '✓' : '✗'),
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
        $executor = new FileSyncController($this->output, $this->io, $this->dryRun, $this->verbose);

        return $executor->sync(
            $this->buildPath($this->sourceEnv),
            $this->buildPath($this->destEnv),
            $excludes,
            $remoteSsh,
            $this->getGitignorePath(),
            $this->flags['delete'],
        );
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

    private function getGitignorePath(): ?string
    {
        // Determine .gitignore path (use local path if source is local)
        $gitignorePath = null;
        if (!isset($this->sourceEnv['ssh'])) {
            $possiblePath = $this->sourceEnv['wordpress_path'] . '/.gitignore';
            if (file_exists($possiblePath)) {
                $gitignorePath = $possiblePath;
            }
        }
        return $gitignorePath;
    }
}
