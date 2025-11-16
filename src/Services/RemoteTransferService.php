<?php

declare(strict_types=1);

namespace Movepress\Services;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class RemoteTransferService
{
    private OutputInterface $output;
    private bool $verbose;

    public function __construct(OutputInterface $output, bool $verbose = false)
    {
        $this->output = $output;
        $this->verbose = $verbose;
    }

    /**
     * Execute a command on a remote server via SSH
     */
    public function executeRemoteCommand(SshService $sshService, string $remoteCommand): bool
    {
        $command = $this->buildSshCommand($sshService, $remoteCommand);

        if ($this->verbose) {
            $this->output->writeln("Remote command: {$command}");
        }

        return $this->executeCommand($command);
    }

    /**
     * Transfer a file from local to remote via SCP
     */
    public function uploadFile(SshService $sshService, string $localPath, string $remotePath): bool
    {
        $command = $this->buildScpCommand($sshService, $localPath, $remotePath, false);

        if ($this->verbose) {
            $this->output->writeln("Uploading: {$localPath} → {$remotePath}");
        }

        return $this->executeCommand($command);
    }

    /**
     * Transfer a file from remote to local via SCP
     */
    public function downloadFile(SshService $sshService, string $remotePath, string $localPath): bool
    {
        $command = $this->buildScpCommand($sshService, $remotePath, $localPath, true);

        if ($this->verbose) {
            $this->output->writeln("Downloading: {$remotePath} → {$localPath}");
        }

        return $this->executeCommand($command);
    }

    /**
     * Build SSH command for remote execution
     */
    private function buildSshCommand(SshService $sshService, string $remoteCommand): string
    {
        $sshOptions = $sshService->getSshOptions();
        $connectionString = $sshService->buildConnectionString();

        $parts = ['ssh'];
        $parts = array_merge($parts, $sshOptions);
        $parts[] = $connectionString;
        $parts[] = escapeshellarg($remoteCommand);

        return implode(' ', $parts);
    }

    /**
     * Build SCP command for file transfer
     */
    private function buildScpCommand(
        SshService $sshService,
        string $sourcePath,
        string $destPath,
        bool $fromRemote,
    ): string {
        $sshOptions = $sshService->getSshOptions();
        $connectionString = $sshService->buildConnectionString();

        // Convert SSH options for SCP
        // SSH returns ['-p', '2222', '-i', '/path/key', '-o', 'StrictHostKeyChecking=no']
        // SCP needs -P for port instead of -p, but supports -o options
        $scpOptions = [];
        $i = 0;
        while ($i < count($sshOptions)) {
            $option = $sshOptions[$i];

            if ($option === '-p' && isset($sshOptions[$i + 1])) {
                // Convert -p to -P for SCP port
                $scpOptions[] = '-P';
                $scpOptions[] = $sshOptions[$i + 1];
                $i += 2;
            } elseif (($option === '-i' || $option === '-o') && isset($sshOptions[$i + 1])) {
                // Keep SSH key and -o options (SCP supports both)
                $scpOptions[] = $option;
                $scpOptions[] = $sshOptions[$i + 1];
                $i += 2;
            } else {
                $i++;
            }
        }

        $parts = ['scp'];
        $parts = array_merge($parts, $scpOptions);

        if ($fromRemote) {
            $parts[] = $connectionString . ':' . escapeshellarg($sourcePath);
            $parts[] = escapeshellarg($destPath);
        } else {
            $parts[] = escapeshellarg($sourcePath);
            $parts[] = $connectionString . ':' . escapeshellarg($destPath);
        }

        return implode(' ', $parts);
    }

    /**
     * Execute a shell command
     */
    private function executeCommand(string $command): bool
    {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600); // 1 hour timeout for large operations
        $process->run();

        if (!$process->isSuccessful()) {
            $this->output->writeln('<error>Command failed: ' . $process->getErrorOutput() . '</error>');
            return false;
        }

        if ($this->verbose && $process->getOutput()) {
            $this->output->writeln($process->getOutput());
        }

        return true;
    }
}
