<?php

declare(strict_types=1);

namespace Movepress\Services;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class RsyncService
{
    private OutputInterface $output;
    private bool $dryRun;
    private bool $verbose;

    public function __construct(
        OutputInterface $output,
        bool $dryRun = false,
        bool $verbose = false
    ) {
        $this->output = $output;
        $this->dryRun = $dryRun;
        $this->verbose = $verbose;
    }

    /**
     * Sync files from source to destination
     * 
     * @param string $sourcePath Source path (local or remote)
     * @param string $destPath Destination path (local or remote)
     * @param array $excludes Array of exclude patterns
     * @param SshService|null $sshService SSH service for remote connections
     * @param string|null $subfolder Optional subfolder to sync (e.g., 'wp-content/uploads')
     */
    public function sync(
        string $sourcePath,
        string $destPath,
        array $excludes = [],
        ?SshService $sshService = null,
        ?string $subfolder = null
    ): bool {
        // Append subfolder if specified
        if ($subfolder) {
            $sourcePath = rtrim($sourcePath, '/') . '/' . trim($subfolder, '/');
            $destPath = rtrim($destPath, '/') . '/' . trim($subfolder, '/');
        }

        // Build rsync command
        $command = $this->buildRsyncCommand(
            $sourcePath,
            $destPath,
            $excludes,
            $sshService
        );

        if ($this->verbose || $this->dryRun) {
            $this->output->writeln("<comment>Executing: {$command}</comment>");
        }

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600); // 1 hour timeout for large syncs

        $process->run(function ($type, $buffer) {
            if ($this->verbose) {
                $this->output->write($buffer);
            }
        });

        if (!$process->isSuccessful()) {
            $this->output->writeln("<error>Rsync failed:</error>");
            $this->output->writeln($process->getErrorOutput());
            return false;
        }

        return true;
    }

    /**
     * Sync WordPress content (themes + plugins, excluding uploads)
     */
    public function syncContent(
        string $sourcePath,
        string $destPath,
        array $excludes,
        ?SshService $sshService = null
    ): bool {
        // Add uploads to excludes for content sync
        $contentExcludes = array_merge($excludes, ['wp-content/uploads/']);
        
        return $this->sync(
            $sourcePath,
            $destPath,
            $contentExcludes,
            $sshService
        );
    }

    /**
     * Sync only WordPress uploads
     */
    public function syncUploads(
        string $sourcePath,
        string $destPath,
        array $excludes,
        ?SshService $sshService = null
    ): bool {
        return $this->sync(
            $sourcePath,
            $destPath,
            $excludes,
            $sshService,
            'wp-content/uploads'
        );
    }

    private function buildRsyncCommand(
        string $source,
        string $dest,
        array $excludes,
        ?SshService $sshService
    ): string {
        $options = [
            '-avz',  // archive, verbose, compress
            '--delete', // delete files that don't exist on source
        ];

        if ($this->dryRun) {
            $options[] = '--dry-run';
        }

        if ($this->verbose) {
            $options[] = '--progress';
        }

        // Add excludes
        foreach ($excludes as $exclude) {
            $options[] = '--exclude=' . escapeshellarg($exclude);
        }

        // Build SSH options if remote connection
        if ($sshService) {
            $sshOptions = implode(' ', $sshService->getSshOptions());
            $options[] = '-e';
            $options[] = escapeshellarg("ssh {$sshOptions}");
        }

        // Ensure trailing slash on source for proper sync behavior
        $source = rtrim($source, '/') . '/';

        $optionsString = implode(' ', $options);
        $sourceEscaped = escapeshellarg($source);
        $destEscaped = escapeshellarg($dest);

        return "rsync {$optionsString} {$sourceEscaped} {$destEscaped}";
    }

    /**
     * Check if rsync is available
     */
    public static function isAvailable(): bool
    {
        $process = Process::fromShellCommandline('which rsync');
        $process->run();
        
        return $process->isSuccessful();
    }
}
