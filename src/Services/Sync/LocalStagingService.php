<?php

declare(strict_types=1);

namespace Movepress\Services\Sync;

use Movepress\Services\RsyncService;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates a temporary local copy of files matching the rsync filters so URL replacements
 * can be applied before uploading to a remote server.
 */
class LocalStagingService
{
    public function __construct(private readonly OutputInterface $output, private readonly bool $verbose) {}

    /**
     * Stage files into a temporary directory that mirrors what rsync would upload.
     */
    public function stage(string $sourcePath, array $excludes, ?string $excludeFromFile, bool $delete): string
    {
        $tempDir = rtrim(sys_get_temp_dir(), '/') . '/movepress_stage_' . uniqid();

        if (!is_dir($tempDir) && !mkdir($tempDir, 0700, true)) {
            throw new RuntimeException('Failed to create temporary staging directory.');
        }

        $rsync = new RsyncService($this->output, false, $this->verbose);
        if (!$rsync->syncFiles($sourcePath, $tempDir, $excludes, null, $excludeFromFile, $delete)) {
            $this->cleanup($tempDir);
            throw new RuntimeException('Failed to stage files locally for remote sync.');
        }

        return $tempDir;
    }

    /**
     * Remove the staged directory and its contents.
     */
    public function cleanup(?string $path): void
    {
        if ($path === null || $path === '' || !is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
