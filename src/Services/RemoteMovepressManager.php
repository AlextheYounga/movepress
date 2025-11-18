<?php

declare(strict_types=1);

namespace Movepress\Services;

use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

class RemoteMovepressManager
{
    public function __construct(
        private readonly RemoteTransferService $transferService,
        private readonly OutputInterface $output,
        private readonly bool $verbose = false,
    ) {}

    public function stage(SshService $sshService, string $wordpressPath): string
    {
        $localExecutable = $this->resolveLocalExecutablePath();
        $remoteDir = rtrim($wordpressPath, '/') . '/.movepress';
        $remotePath = $remoteDir . '/movepress.phar';

        $command = sprintf('mkdir -p %s', escapeshellarg($remoteDir));
        if (!$this->transferService->executeRemoteCommand($sshService, $command)) {
            throw new RuntimeException('Failed to prepare remote Movepress directory.');
        }

        if ($this->verbose) {
            $this->output->writeln(sprintf('Uploading Movepress executable to %s', $remotePath));
        }

        if (!$this->transferService->uploadFile($sshService, $localExecutable, $remotePath)) {
            throw new RuntimeException('Failed to upload Movepress executable to remote server.');
        }

        return $remotePath;
    }

    public function cleanup(SshService $sshService, string $remotePath): void
    {
        $remoteDir = dirname($remotePath);
        $command = sprintf(
            'rm -f %s && rmdir %s 2>/dev/null || true',
            escapeshellarg($remotePath),
            escapeshellarg($remoteDir),
        );

        $this->transferService->executeRemoteCommand($sshService, $command);
    }

    private function resolveLocalExecutablePath(): string
    {
        $pharPath = \Phar::running(false);
        if ($pharPath !== '') {
            return $pharPath;
        }

        $script = $_SERVER['argv'][0] ?? null;
        if ($script === null) {
            throw new RuntimeException('Unable to determine Movepress executable path.');
        }

        $realPath = realpath($script);
        if ($realPath === false || !is_file($realPath)) {
            throw new RuntimeException('Movepress executable not found at: ' . $script);
        }

        return $realPath;
    }
}
