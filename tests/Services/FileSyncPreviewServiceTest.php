<?php

declare(strict_types=1);

namespace Movepress\Tests\Services;

use Movepress\Services\FileSyncPreviewService;
use PHPUnit\Framework\TestCase;

class FileSyncPreviewServiceTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/movepress_preview_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->cleanupDir($this->testDir);
    }

    public function test_excludes_directories_by_full_path(): void
    {
        $this->createFile('wp-content/uploads/image.jpg');
        $this->createFile('wp-content/plugins/myplugin/plugin.php');
        $this->createFile('wp-content/themes/mytheme/style.css');

        // Exclude git-tracked plugin and theme by full path
        $excludes = ['wp-content/plugins/myplugin/plugin.php', 'wp-content/themes/mytheme/style.css'];

        $preview = new FileSyncPreviewService($excludes);
        $result = $preview->scanDirectoriesWithCounts($this->testDir, $this->testDir);

        // Should show wp-content and uploads, but not plugins or themes directories
        $paths = array_column($result, 'path');

        $this->assertContains('wp-content', $paths);
        $this->assertContains('wp-content/uploads', $paths);
        $this->assertNotContains('wp-content/plugins', $paths);
        $this->assertNotContains('wp-content/themes', $paths);
    }

    public function test_excludes_nested_directories_by_pattern(): void
    {
        $this->createFile('wp-content/cache/page.html');
        $this->createFile('wp-content/uploads/2024/photo.jpg');
        $this->createFile('node_modules/package/index.js');

        $excludes = ['wp-content/cache/', 'node_modules/'];

        $preview = new FileSyncPreviewService($excludes);
        $result = $preview->scanDirectoriesWithCounts($this->testDir, $this->testDir);

        $paths = array_column($result, 'path');

        $this->assertContains('wp-content', $paths);
        $this->assertContains('wp-content/uploads', $paths);
        $this->assertNotContains('wp-content/cache', $paths);
        $this->assertNotContains('node_modules', $paths);
    }

    public function test_shows_correct_file_counts(): void
    {
        $this->createFile('wp-content/uploads/2024/01/photo1.jpg');
        $this->createFile('wp-content/uploads/2024/01/photo2.jpg');
        $this->createFile('wp-content/uploads/2024/02/photo3.jpg');

        $preview = new FileSyncPreviewService([]);
        $result = $preview->scanDirectoriesWithCounts($this->testDir, $this->testDir);

        // wp-content/uploads should be collapsed into single entry with total count
        $uploads = array_filter($result, fn($item) => $item['path'] === 'wp-content/uploads');
        $uploads = reset($uploads);

        $this->assertNotFalse($uploads);
        $this->assertEquals(3, $uploads['count']); // All 3 files in uploads

        // Subdirectories under uploads should NOT appear
        $subdirs = array_filter($result, fn($item) => str_starts_with($item['path'], 'wp-content/uploads/'));
        $this->assertEmpty($subdirs);
    }

    public function test_excludes_files_by_glob_pattern(): void
    {
        $this->createFile('debug.log');
        $this->createFile('error.log');
        $this->createFile('important.txt');
        $this->createFile('folder/test.log');

        $excludes = ['*.log'];

        $preview = new FileSyncPreviewService($excludes);
        $result = $preview->scanDirectoriesWithCounts($this->testDir, $this->testDir);

        $paths = array_column($result, 'path');

        // Should show important.txt but not .log files
        $this->assertContains('important.txt', $paths);
        $this->assertNotContains('debug.log', $paths);
        $this->assertNotContains('error.log', $paths);
    }

    public function test_mixed_patterns_and_full_paths(): void
    {
        $this->createFile('wp-content/plugins/plugin1/index.php');
        $this->createFile('wp-content/uploads/file.pdf');
        $this->createFile('debug.log');
        $this->createFile('cache/page.html');

        $excludes = [
            'wp-content/plugins/plugin1/index.php', // Full path (git-tracked)
            '*.log', // Pattern
            'cache/', // Directory
        ];

        $preview = new FileSyncPreviewService($excludes);
        $result = $preview->scanDirectoriesWithCounts($this->testDir, $this->testDir);

        $paths = array_column($result, 'path');

        $this->assertContains('wp-content', $paths);
        $this->assertContains('wp-content/uploads', $paths);
        $this->assertNotContains('wp-content/plugins', $paths);
        $this->assertNotContains('debug.log', $paths);
        $this->assertNotContains('cache', $paths);
    }

    public function test_includes_limit_results_when_restricting(): void
    {
        $this->createFile('wp-content/uploads/file.pdf');
        $this->createFile('wp-content/cache/page.html');
        $this->createFile('logs/error.txt');

        $includes = ['wp-content/', 'wp-content/uploads/', 'wp-content/uploads/***'];

        $preview = new FileSyncPreviewService([], $includes, true);
        $result = $preview->scanDirectoriesWithCounts($this->testDir, $this->testDir);

        $paths = array_column($result, 'path');

        $this->assertContains('wp-content/uploads', $paths);
        $this->assertNotContains('wp-content/cache', $paths);
        $this->assertNotContains('logs', $paths);
    }

    private function createFile(string $relativePath): void
    {
        $fullPath = $this->testDir . '/' . $relativePath;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($fullPath, 'test content');
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->cleanupDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
