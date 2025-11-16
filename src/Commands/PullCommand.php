<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Movepress\Config\ConfigLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class PullCommand extends AbstractSyncCommand
{
    protected function configure(): void
    {
        $this->setName('pull')
            ->setDescription('Pull database and/or files from source to destination environment');

        $this->configureArguments();
        $this->configureOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $source = $input->getArgument('source');
        $destination = $input->getArgument('destination');

        $io->title("Movepress: Pull {$source} → {$destination}");

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
                $input->getOption('verbose')
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
                $sourceSsh = $this->getSshService($sourceEnv);

                if (!$this->syncFiles($excludes, $sourceSsh)) {
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
                $io->success("Pull from {$source} to {$destination} completed!");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
