<?php

declare(strict_types=1);

namespace Movepress\Services;

/**
 * Performs search/replace on text files within a directory tree.
 */
class FileSearchReplaceService
{
    private const TEXT_EXTENSIONS = [
        'php',
        'phtml',
        'html',
        'htm',
        'css',
        'scss',
        'less',
        'js',
        'jsx',
        'ts',
        'tsx',
        'json',
        'xml',
        'yml',
        'yaml',
        'md',
        'txt',
        'twig',
        'mustache',
        'vue',
    ];

    public function __construct(private readonly bool $verbose = false) {}

    /**
     * Run search/replace across the provided path.
     *
     * @return array{filesChecked:int,filesModified:int}
     */
    public function replaceInPath(string $path, string $search, string $replace): array
    {
        $filesChecked = 0;
        $filesModified = 0;

        if (!is_dir($path)) {
            throw new \RuntimeException("Path not found: {$path}");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $extension = strtolower($fileInfo->getExtension());
            if (!in_array($extension, self::TEXT_EXTENSIONS, true)) {
                continue;
            }

            if (!$this->isTextFile($fileInfo->getPathname())) {
                continue;
            }

            $filesChecked++;

            $contents = file_get_contents($fileInfo->getPathname());
            if ($contents === false) {
                continue;
            }

            $updated = str_replace($search, $replace, $contents);
            if ($updated === $contents) {
                continue;
            }

            if (file_put_contents($fileInfo->getPathname(), $updated) === false) {
                throw new \RuntimeException('Failed to write updated contents to ' . $fileInfo->getPathname());
            }

            $filesModified++;
            if ($this->verbose) {
                echo "Updated: {$fileInfo->getPathname()}\n";
            }
        }

        return ['filesChecked' => $filesChecked, 'filesModified' => $filesModified];
    }

    private function isTextFile(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $chunk = fread($handle, 2048);
        fclose($handle);

        if ($chunk === false) {
            return false;
        }

        return strpos($chunk, "\0") === false;
    }
}
