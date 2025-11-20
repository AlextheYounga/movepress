<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Movepress\Console\MovepressStyle;
use Movepress\Config\ConfigLoader;
use Movepress\Services\GitService;
use Movepress\Services\SshService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GitSetupCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('git-setup')
            ->setDescription('Set up Git deployment for a remote environment')
            ->addArgument('environment', InputArgument::REQUIRED, 'Environment name (e.g., staging, production)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new MovepressStyle($input, $output);
        $envName = $input->getArgument('environment');
        $verbose = $output->isVerbose();

        $io->title("Git Deployment Setup: {$envName}");

        try {
            // Check if Git is available
            if (!GitService::isAvailable()) {
                $io->error('Git is not installed or not available in PATH');
                return Command::FAILURE;
            }

            // Load configuration
            $config = new ConfigLoader();
            $config->load();

            $env = $config->getEnvironment($envName);

            // Validate environment configuration
            $this->validateEnvironment($envName, $env);

            // Check if this is a local environment
            if (!isset($env['ssh'])) {
                $io->error(
                    "Environment '{$envName}' does not have SSH configuration. Git deployment is only for remote environments.",
                );
                return Command::FAILURE;
            }

            // Get paths
            $wordpressPath = $env['wordpress_path'];
            $repoPath = $this->getRepoPath($env, $wordpressPath);

            // Display configuration
            $this->displayConfiguration($io, $envName, $wordpressPath, $repoPath, $env['ssh']);

            if (!$input->getOption('no-interaction') && !$io->confirm('Proceed with Git setup?', true)) {
                $io->writeln('Operation cancelled.');
                return Command::SUCCESS;
            }

            // Create services
            $sshService = new SshService($env['ssh']);
            $gitService = new GitService($output, $verbose);

            // Check if local directory is a Git repo
            $localPath = getcwd();
            if (!$gitService->isGitRepo($localPath)) {
                $io->error("Current directory is not a Git repository. Please run 'git init' first.");
                return Command::FAILURE;
            }

            // Set up remote repository
            $io->section('Setting up remote Git repository');
            if (!$gitService->setupRemoteRepo($repoPath, $wordpressPath, $sshService)) {
                $io->error('Failed to set up remote Git repository');
                return Command::FAILURE;
            }

            $io->success('Remote Git repository configured successfully');

            // Add Git remote locally
            $io->section('Configuring local Git remote');
            $remoteUrl = $gitService->buildRemoteUrl($repoPath, $sshService);

            if (!$gitService->addRemote($envName, $remoteUrl, $localPath)) {
                $io->error('Failed to add Git remote');
                return Command::FAILURE;
            }

            $io->success("Git remote '{$envName}' configured successfully");

            // Display next steps
            $this->displayNextSteps($io, $envName);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function validateEnvironment(string $name, array $env): void
    {
        if (empty($env['wordpress_path'])) {
            throw new \RuntimeException("Environment '{$name}' missing 'wordpress_path' configuration");
        }

        if (empty($env['ssh'])) {
            throw new \RuntimeException("Environment '{$name}' missing 'ssh' configuration");
        }
    }

    private function getRepoPath(array $env, string $wordpressPath): string
    {
        if (isset($env['git']['repo_path'])) {
            return $env['git']['repo_path'];
        }

        $siteName = basename($wordpressPath);
        return "/var/repos/{$siteName}.git";
    }

    private function displayConfiguration(
        MovepressStyle $io,
        string $envName,
        string $wordpressPath,
        string $repoPath,
        array $sshConfig,
    ): void {
        $io->section('Configuration');

        $io->listing([
            "Environment: {$envName}",
            "SSH Host: {$sshConfig['host']}",
            "SSH User: {$sshConfig['user']}",
            "WordPress Path: {$wordpressPath}",
            "Git Repository: {$repoPath}",
        ]);

        $io->note([
            'This will create a bare Git repository on the remote server',
            'and configure a post-receive hook to deploy code automatically.',
        ]);
    }

    private function displayNextSteps(MovepressStyle $io, string $envName): void
    {
        $io->section('Next Steps');
        $io->writeln("Git deployment is now configured for <info>{$envName}</info>.");
        $io->newLine();

        $io->writeln('To deploy your code, run:');
        $io->writeln("  <comment>git push {$envName} master</comment>");
        $io->newLine();

        $io->writeln('To sync database and untracked files (uploads, etc.), use:');
        $io->writeln("  <comment>movepress push local {$envName} --db --untracked-files</comment>");
    }
}
