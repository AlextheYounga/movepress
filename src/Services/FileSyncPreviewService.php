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
        $result = [];
        $this->scanDirectoriesRecursive($currentPath, $basePath, $result);

        // Sort directories by path
        usort($result, fn($a, $b) => strcmp($a['path'], $b['path']));

        return $result;
    }

    /**
     * Recursively scan directories and build result array.
     */
    private function scanDirectoriesRecursive(string $currentPath, string $basePath, array &$result): int
    {
        if (!is_dir($currentPath)) {
            return 0;
        }

        $items = @scandir($currentPath);
        if ($items === false) {
            return 0;
        }

        $relativePath = $currentPath === $basePath ? '' : substr($currentPath, strlen($basePath) + 1);
        $fileCount = 0;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $currentPath . '/' . $item;
            $itemRelativePath = $relativePath === '' ? $item : $relativePath . '/' . $item;

            if (is_dir($fullPath)) {
                // Check if directory should be excluded (check both name and full path)
                if ($this->isExcluded($item, $item . '/', $itemRelativePath, $itemRelativePath . '/')) {
                    continue;
                }

                // Recursively scan subdirectory and count its files
                $subFileCount = $this->scanDirectoriesRecursive($fullPath, $basePath, $result);

                if ($subFileCount > 0) {
                    $result[] = [
                        'type' => 'dir',
                        'path' => $itemRelativePath,
                        'count' => $subFileCount,
                    ];
                    $fileCount += $subFileCount;
                }
            } else {
                // Check if file should be excluded (check both name and full path)
                if (!$this->isExcluded($item, $item, $itemRelativePath, $itemRelativePath)) {
                    $fileCount++;

                    // Only add root-level files to result
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

        return $fileCount;
    }

    /**
     * Check if a path matches any exclusion pattern.
     */
    private function isExcluded(
        string $name,
        string $nameWithSlash,
        string $relativePath,
        string $relativePathWithSlash,
    ): bool {
        foreach ($this->excludePatterns as $pattern) {
            // Exact match on name
            if ($pattern === $name || $pattern === $nameWithSlash) {
                return true;
            }

            // Exact match on full relative path (for git-tracked files)
            if ($pattern === $relativePath || $pattern === $relativePathWithSlash) {
                return true;
            }

            // Directory pattern (ends with /)
            if (str_ends_with($pattern, '/') && ($pattern === $nameWithSlash || $pattern === $relativePathWithSlash)) {
                return true;
            }

            // Glob pattern matching (check both name and full path)
            if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
                if (
                    fnmatch($pattern, $name) ||
                    fnmatch($pattern, $nameWithSlash) ||
                    fnmatch($pattern, $relativePath) ||
                    fnmatch($pattern, $relativePathWithSlash)
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}
