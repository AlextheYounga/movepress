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

        // Additional SSH options from config
        if (isset($this->sshConfig['options']) && is_string($this->sshConfig['options'])) {
            $configOptions = $this->parseOptionsString($this->sshConfig['options']);
            $options = array_merge($options, $configOptions);
        } else {
            // Default: Disable strict host key checking for common use cases
            $options[] = '-o';
            $options[] = 'StrictHostKeyChecking=no';
        }

        return $options;
    }

    private function parseOptionsString(string $optionsString): array
    {
        $parsed = [];
        $parts = preg_split('/\s+/', trim($optionsString));

        for ($i = 0; $i < count($parts); $i++) {
            $part = $parts[$i];

            // Handle flags that take values (like -o, -p, -i)
            if (in_array($part, ['-o', '-p', '-i', '-c', '-l']) && isset($parts[$i + 1])) {
                $parsed[] = $part;
                $parsed[] = $parts[$i + 1];
                $i++; // Skip next part since we consumed it
            } else {
                $parsed[] = $part;
            }
        }

        return $parsed;
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

    /**
     * Check if a remote file exists
     */
    public function fileExists(string $remotePath): bool
    {
        $connectionString = $this->buildConnectionString();
        $sshOptions = array_map('escapeshellarg', $this->getSshOptions());

        $parts = array_merge(['ssh'], $sshOptions, [
            $connectionString,
            escapeshellarg('test -f ' . escapeshellarg($remotePath)),
        ]);
        $command = implode(' ', $parts);

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Check if a remote directory exists
     */
    public function directoryExists(string $remotePath): bool
    {
        $connectionString = $this->buildConnectionString();
        $sshOptions = array_map('escapeshellarg', $this->getSshOptions());

        $parts = array_merge(['ssh'], $sshOptions, [
            $connectionString,
            escapeshellarg('test -d ' . escapeshellarg($remotePath)),
        ]);
        $command = implode(' ', $parts);

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
