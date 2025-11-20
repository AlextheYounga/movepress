<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Movepress\Console\MovepressStyle;
use Movepress\Config\ConfigLoader;
use Movepress\Services\RsyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PushCommand extends AbstractSyncCommand
{
    protected function configure(): void
    {
        $this->setName('push')->setDescription('Push database and/or files from source to destination environment');

        $this->configureArguments();
        $this->configureOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new MovepressStyle($input, $output);

        $source = $input->getArgument('source');
        $destination = $input->getArgument('destination');

        $io->title("Movepress: Push {$source} → {$destination}");

        try {
            $config = new ConfigLoader();
            $config->load();

            $sourceEnv = $config->getEnvironment($source);
            $destEnv = $config->getEnvironment($destination);

            $this->validateEnvironment($source, $sourceEnv);
            $this->validateEnvironment($destination, $destEnv);

            $flags = $this->parseSyncFlags($input);

            // Initialize context with all needed state
            $this->initializeContext(
                $output,
                $io,
                $sourceEnv,
                $destEnv,
                $flags,
                $input->getOption('dry-run'),
                $input->getOption('verbose'),
                $input->getOption('no-interaction'),
            );

            $this->displayConfiguration($source, $destination);

            // Validate all prerequisites
            if (!$this->validatePrerequisites()) {
                return Command::FAILURE;
            }

            // Confirm destructive operations
            if (!$this->dryRun && !$this->confirmDestructiveOperation($destination)) {
                $io->writeln('Operation cancelled.');
                return Command::SUCCESS;
            }

            // Sync untracked files if requested
            if ($this->flags['untracked_files']) {
                $io->section('File Synchronization');

                $excludes = $config->getExcludes($destination);
                $destSsh = $this->getSshService($destEnv);

                if (!$this->syncFiles($excludes, $destSsh)) {
                    return Command::FAILURE;
                }

                if (!$this->processSyncedFiles()) {
                    return Command::FAILURE;
                }

                $io->success('Untracked files synchronized successfully');
            }

            // Sync database if requested
            if ($this->flags['db']) {
                $io->section('Database Synchronization');

                if (!$this->syncDatabase($input->getOption('no-backup'))) {
                    return Command::FAILURE;
                }

                if (!$this->dryRun) {
                    $io->success('Database synchronized successfully');
                }
            }

            if (!$this->dryRun) {
                $io->success("Push from {$source} to {$destination} completed!");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
