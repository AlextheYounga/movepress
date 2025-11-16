<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Movepress\Config\ConfigLoader;
use Movepress\Services\RsyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class PushCommand extends AbstractSyncCommand
{
    protected function configure(): void
    {
        $this->setName('push')
            ->setDescription('Push database and/or files from source to destination environment');

        $this->configureArguments();
        $this->configureOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $source = $input->getArgument('source');
        $destination = $input->getArgument('destination');

        $flags = $this->parseSyncFlags($input);

        if (!$this->validateFlagCombinations($flags, $io)) {
            return Command::FAILURE;
        }

        $io->title("Movepress: Push {$source} → {$destination}");

        try {
            $config = new ConfigLoader();
            $config->load();

            $sourceEnv = $config->getEnvironment($source);
            $destEnv = $config->getEnvironment($destination);

            $this->validateEnvironment($source, $sourceEnv);
            $this->validateEnvironment($destination, $destEnv);

            $this->displayConfiguration($io, $source, $destination, $flags, $input->getOption('dry-run'));

            // Validate all prerequisites
            if (!$this->validatePrerequisites($sourceEnv, $destEnv, $flags, $io, $input->getOption('dry-run'))) {
                return Command::FAILURE;
            }

            // Confirm destructive operations
            if (!$input->getOption('dry-run') && !$this->confirmDestructiveOperation($io, $destination, $flags)) {
                $io->writeln('Operation cancelled.');
                return Command::SUCCESS;
            }

            // Sync untracked files if requested
            if ($flags['untracked_files']) {
                $io->section('File Synchronization');

                $excludes = $config->getExcludes($destination);
                $destSsh = $this->getSshService($destEnv);

                $success = $this->syncFiles(
                    $sourceEnv,
                    $destEnv,
                    $excludes,
                    $input->getOption('dry-run'),
                    $input->getOption('verbose'),
                    $output,
                    $io,
                    $destSsh
                );

                if (!$success) {
                    return Command::FAILURE;
                }

                $io->success('Untracked files synchronized successfully');
            }

            // Sync database if requested
            if ($flags['db']) {
                $io->section('Database Synchronization');

                $success = $this->syncDatabase(
                    $sourceEnv,
                    $destEnv,
                    $input->getOption('no-backup'),
                    $input->getOption('dry-run'),
                    $input->getOption('verbose'),
                    $output,
                    $io
                );

                if (!$success) {
                    return Command::FAILURE;
                }

                if (!$input->getOption('dry-run')) {
                    $io->success('Database synchronized successfully');
                }
            }

            if (!$input->getOption('dry-run')) {
                $io->success("Push from {$source} to {$destination} completed!");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
