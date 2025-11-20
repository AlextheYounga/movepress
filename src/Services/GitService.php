<?php

declare(strict_types=1);

namespace Movepress\Services;

use Movepress\Console\MovepressStyle;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class GitService
{
    private OutputInterface $output;
    private bool $verbose;

    public function __construct(OutputInterface $output, bool $verbose = false)
    {
        $this->output = $output;
        $this->verbose = $verbose;
        MovepressStyle::registerCustomStyles($this->output);
    }

    /**
     * Set up bare Git repository on remote server with post-receive hook
     */
    public function setupRemoteRepo(string $repoPath, string $wordpressPath, SshService $sshService): bool
    {
        $connectionString = $sshService->buildConnectionString();
        $sshOptions = implode(' ', $sshService->getSshOptions());

        // Create bare repo directory
        $mkdirCommand = sprintf(
            'ssh %s %s "mkdir -p %s"',
            $sshOptions,
            escapeshellarg($connectionString),
            escapeshellarg($repoPath),
        );

        if (!$this->runCommand($mkdirCommand, 'Creating remote repository directory')) {
            return false;
        }

        // Initialize bare repo
        $initCommand = sprintf(
            'ssh %s %s "cd %s && git init --bare"',
            $sshOptions,
            escapeshellarg($connectionString),
            escapeshellarg($repoPath),
        );

        if (!$this->runCommand($initCommand, 'Initializing bare Git repository')) {
            return false;
        }

        // Create post-receive hook
        $hookContent = $this->generatePostReceiveHook($wordpressPath);
        $hookPath = rtrim($repoPath, '/') . '/hooks/post-receive';

        $createHookCommand = sprintf(
            'ssh %s %s "cat > %s && chmod +x %s"',
            $sshOptions,
            escapeshellarg($connectionString),
            escapeshellarg($hookPath),
            escapeshellarg($hookPath),
        );

        $process = Process::fromShellCommandline($createHookCommand);
        $process->setInput($hookContent);
        $process->setTimeout(30);

        if ($this->verbose) {
            $this->output->writeln('<muted>Creating post-receive hook</muted>');
        }

        $process->run(function ($type, $buffer) {
            if ($this->verbose) {
                $this->output->write($buffer);
            }
        });

        if (!$process->isSuccessful()) {
            $this->output->writeln('<error>Failed to create post-receive hook:</error>');
            $this->output->writeln($process->getErrorOutput());
            return false;
        }

        return true;
    }

    /**
     * Add Git remote to local repository
     */
    public function addRemote(string $remoteName, string $remoteUrl, string $localPath): bool
    {
        // Check if remote already exists
        $checkCommand = sprintf(
            'cd %s && git remote get-url %s 2>/dev/null',
            escapeshellarg($localPath),
            escapeshellarg($remoteName),
        );

        $process = Process::fromShellCommandline($checkCommand);
        $process->run();

        if ($process->isSuccessful()) {
            // Remote exists, update it
            $updateCommand = sprintf(
                'cd %s && git remote set-url %s %s',
                escapeshellarg($localPath),
                escapeshellarg($remoteName),
                escapeshellarg($remoteUrl),
            );

            return $this->runCommand($updateCommand, "Updating Git remote '{$remoteName}'");
        }

        // Remote doesn't exist, add it
        $addCommand = sprintf(
            'cd %s && git remote add %s %s',
            escapeshellarg($localPath),
            escapeshellarg($remoteName),
            escapeshellarg($remoteUrl),
        );

        return $this->runCommand($addCommand, "Adding Git remote '{$remoteName}'");
    }

    /**
     * Build Git remote URL from SSH service
     */
    public function buildRemoteUrl(string $repoPath, SshService $sshService): string
    {
        $connectionString = $sshService->buildConnectionString();
        return "{$connectionString}:{$repoPath}";
    }

    /**
     * Generate post-receive hook script
     */
    private function generatePostReceiveHook(string $wordpressPath): string
    {
        return <<<BASH
        #!/bin/bash
        # Movepress post-receive hook
        # Deploys pushed commits to WordPress directory

        GIT_DIR=\$(pwd)
        TARGET_DIR="$wordpressPath"

        echo "Deploying to \$TARGET_DIR..."

        # Create target directory if it doesn't exist
        mkdir -p "\$TARGET_DIR"

        # Use git archive to export the pushed commits
        while read oldrev newrev refname; do
            branch=\$(echo \$refname | sed 's/refs\/heads\///')

            if [ "\$newrev" = "0000000000000000000000000000000000000000" ]; then
                echo "Branch \$branch deleted, skipping deployment."
                continue
            fi

            echo "Deploying branch: \$branch"

            # Export files to target directory
            git --work-tree="\$TARGET_DIR" --git-dir="\$GIT_DIR" checkout -f \$branch

            echo "Deployment complete!"
        done

        BASH;
    }

    /**
     * Check if Git is available
     */
    public static function isAvailable(): bool
    {
        $process = Process::fromShellCommandline('which git');
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Check if current directory is a Git repository
     */
    public function isGitRepo(string $path): bool
    {
        $command = sprintf('cd %s && git rev-parse --git-dir 2>/dev/null', escapeshellarg($path));

        $process = Process::fromShellCommandline($command);
        $process->run();

        return $process->isSuccessful();
    }

    private function runCommand(string $command, string $description): bool
    {
        if ($this->verbose) {
            $this->output->writeln("<muted>{$description}</muted>");
            $this->output->writeln(sprintf('<cmd>› %s</cmd>', $command));
        }

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(60);

        $process->run(function ($type, $buffer) {
            if ($this->verbose) {
                $this->output->write($buffer);
            }
        });

        if (!$process->isSuccessful()) {
            $this->output->writeln("<error>Failed: {$description}</error>");
            $this->output->writeln($process->getErrorOutput());
            return false;
        }

        return true;
    }
}
