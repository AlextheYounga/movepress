<?php

declare(strict_types=1);

namespace Movepress\Services;

use Symfony\Component\Process\Process;

class SshService
{
    private array $sshConfig;

    public function __construct(array $sshConfig)
    {
        $this->sshConfig = $sshConfig;
    }

    public function buildConnectionString(): string
    {
        $user = $this->sshConfig['user'] ?? throw new \RuntimeException('SSH user not configured');
        $host = $this->sshConfig['host'] ?? throw new \RuntimeException('SSH host not configured');

        return "{$user}@{$host}";
    }

    public function getSshOptions(): array
    {
        $options = [];

        // Port
        if (isset($this->sshConfig['port']) && $this->sshConfig['port'] !== 22) {
            $options[] = '-p';
            $options[] = (string) $this->sshConfig['port'];
        }

        // SSH key
        if (isset($this->sshConfig['key'])) {
            $keyPath = $this->expandPath($this->sshConfig['key']);
            if (!file_exists($keyPath)) {
                throw new \RuntimeException("SSH key not found: {$keyPath}");
            }
            $options[] = '-i';
            $options[] = $keyPath;
        }

        // Disable strict host key checking for common use cases
        $options[] = '-o';
        $options[] = 'StrictHostKeyChecking=no';

        return $options;
    }

    public function testConnection(): bool
    {
        $connectionString = $this->buildConnectionString();
        $sshOptions = implode(' ', array_map('escapeshellarg', $this->getSshOptions()));

        $command = "ssh {$sshOptions} {$connectionString} 'exit 0'";

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful();
    }

    private function expandPath(string $path): string
    {
        // Expand ~ to home directory
        if (strpos($path, '~') === 0) {
            $home = getenv('HOME') ?: getenv('USERPROFILE');
            return str_replace('~', $home, $path);
        }

        return $path;
    }
}
