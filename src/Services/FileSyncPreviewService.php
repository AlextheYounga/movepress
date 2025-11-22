<?php

declare(strict_types=1);

namespace Movepress\Services;

class FileSyncPreviewService
{
    private array $excludePatterns;

    public function __construct(array $excludePatterns)
    {
        $this->excludePatterns = $excludePatterns;
    }

    /**
     * Recursively scan directories and count files that will be synced.
     * Returns array of items with path, type (file/dir), and count.
     */
    public function scanDirectoriesWithCounts(string $currentPath, string $basePath): array
    {
        if (!is_dir($currentPath)) {
            return [];
        }

        $items = @scandir($currentPath);
        if ($items === false) {
            return [];
        }

        $result = [];
        $relativePath = $currentPath === $basePath ? '' : substr($currentPath, strlen($basePath) + 1);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $currentPath . '/' . $item;
            $itemRelativePath = $relativePath === '' ? $item : $relativePath . '/' . $item;

            if (is_dir($fullPath)) {
                // Check if directory should be excluded
                if ($this->isExcluded($item, $item . '/')) {
                    continue;
                }

                // Recursively scan subdirectory
                $fileCount = $this->countFilesInDirectory($fullPath);

                if ($fileCount > 0) {
                    $result[] = [
                        'type' => 'dir',
                        'path' => $itemRelativePath,
                        'count' => $fileCount,
                    ];
                }
            } else {
                // Check if file should be excluded
                if (!$this->isExcluded($item, $item)) {
                    // Only add root-level files
                    if ($relativePath === '') {
                        $result[] = [
                            'type' => 'file',
                            'path' => $item,
                            'count' => 1,
                        ];
                    }
                }
            }
        }

        // Sort directories by path
        usort($result, fn($a, $b) => strcmp($a['path'], $b['path']));

        return $result;
    }

    /**
     * Count all files in a directory recursively (excluding excluded patterns).
     */
    private function countFilesInDirectory(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $items = @scandir($path);
        if ($items === false) {
            return 0;
        }

        $count = 0;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . '/' . $item;

            if (is_dir($fullPath)) {
                if (!$this->isExcluded($item, $item . '/')) {
                    $count += $this->countFilesInDirectory($fullPath);
                }
            } else {
                if (!$this->isExcluded($item, $item)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Check if a path matches any exclusion pattern.
     */
    private function isExcluded(string $name, string $nameWithSlash): bool
    {
        foreach ($this->excludePatterns as $pattern) {
            // Exact match
            if ($pattern === $name || $pattern === $nameWithSlash) {
                return true;
            }

            // Directory pattern (ends with /)
            if (str_ends_with($pattern, '/') && $pattern === $nameWithSlash) {
                return true;
            }

            // Glob pattern matching
            if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
                // Remove leading ** or * for matching
                $cleanPattern = ltrim($pattern, '*');
                $cleanPattern = ltrim($cleanPattern, '/');

                if (fnmatch($pattern, $name) || fnmatch($pattern, $nameWithSlash)) {
                    return true;
                }
            }
        }

        return false;
    }
}
