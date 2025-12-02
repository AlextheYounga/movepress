<?php

declare(strict_types=1);

namespace Movepress\Services;

class FileSyncPreviewService
{
    private array $excludePatterns;
    private array $includePatterns;
    private bool $restrictToSelection;

    public function __construct(array $excludePatterns, array $includePatterns = [], bool $restrictToSelection = false)
    {
        $this->excludePatterns = $excludePatterns;
        $this->includePatterns = $includePatterns;
        $this->restrictToSelection = $restrictToSelection && !empty($includePatterns);
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

                if ($this->restrictToSelection && !$this->isIncluded($itemRelativePath, $itemRelativePath . '/')) {
                    continue;
                }

                // Special case: wp-content/uploads - don't recurse, just count all files
                if (
                    $itemRelativePath === 'wp-content/uploads' ||
                    str_ends_with($itemRelativePath, '/wp-content/uploads')
                ) {
                    $subFileCount = $this->countFilesRecursive($fullPath);
                    if ($subFileCount > 0) {
                        $result[] = [
                            'type' => 'dir',
                            'path' => $itemRelativePath,
                            'count' => $subFileCount,
                        ];
                        $fileCount += $subFileCount;
                    }
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
                if ($this->restrictToSelection && !$this->isIncluded($itemRelativePath, $itemRelativePath)) {
                    continue;
                }

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
     * Count all files recursively without building detailed directory structure.
     * Used for wp-content/uploads to avoid showing every subdirectory.
     */
    private function countFilesRecursive(string $path): int
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
                $count += $this->countFilesRecursive($fullPath);
            } else {
                $count++;
            }
        }

        return $count;
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

    private function isIncluded(string $relativePath, string $relativePathWithSlash): bool
    {
        if (empty($this->includePatterns)) {
            return true;
        }

        foreach ($this->includePatterns as $pattern) {
            if ($pattern === $relativePath || $pattern === $relativePathWithSlash) {
                return true;
            }

            if (str_ends_with($pattern, '/***')) {
                $prefix = rtrim(substr($pattern, 0, -4), '/');
                if ($relativePath === $prefix || str_starts_with($relativePathWithSlash, $prefix . '/')) {
                    return true;
                }
            }

            if (str_ends_with($pattern, '/') && ($pattern === $relativePathWithSlash || $pattern === $relativePath)) {
                return true;
            }

            if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
                if (fnmatch($pattern, $relativePath) || fnmatch($pattern, $relativePathWithSlash)) {
                    return true;
                }
            }
        }

        return false;
    }
}
