<?php

declare(strict_types=1);

namespace Movepress\Tests\Services;

use Movepress\Services\RsyncService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Integration tests for file exclusion patterns
 */
class FileExclusionTest extends TestCase
{
    private string $sourceDir;
    private string $destDir;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
        $this->sourceDir = sys_get_temp_dir() . '/movepress_exclude_test_source_' . uniqid();
        $this->destDir = sys_get_temp_dir() . '/movepress_exclude_test_dest_' . uniqid();

        mkdir($this->sourceDir, 0777, true);
        mkdir($this->destDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->cleanupDir($this->sourceDir);
        $this->cleanupDir($this->destDir);
    }

    public function test_excludes_specific_file_by_name(): void
    {
        $this->createFile('keep.txt', 'keep this');
        $this->createFile('exclude.txt', 'exclude this');
        $this->createFile('also-keep.txt', 'keep this too');

        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, ['exclude.txt']);

        // Positive: these files should be included with correct content
        $this->assertFileExists($this->destDir . '/keep.txt');
        $this->assertSame('keep this', file_get_contents($this->destDir . '/keep.txt'));
        $this->assertFileExists($this->destDir . '/also-keep.txt');
        $this->assertSame('keep this too', file_get_contents($this->destDir . '/also-keep.txt'));

        // Negative: this file should be excluded
        $this->assertFileDoesNotExist($this->destDir . '/exclude.txt');
    }

    public function test_excludes_file_by_wildcard_pattern(): void
    {
        $this->createFile('test.log', 'log content');
        $this->createFile('debug.log', 'debug content');
        $this->createFile('important.txt', 'important');
        $this->createFile('data.json', 'json data');

        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, ['*.log']);

        // Positive: non-.log files should be included with correct content
        $this->assertFileExists($this->destDir . '/important.txt');
        $this->assertSame('important', file_get_contents($this->destDir . '/important.txt'));
        $this->assertFileExists($this->destDir . '/data.json');
        $this->assertSame('json data', file_get_contents($this->destDir . '/data.json'));

        // Negative: .log files should be excluded
        $this->assertFileDoesNotExist($this->destDir . '/test.log');
        $this->assertFileDoesNotExist($this->destDir . '/debug.log');
    }

    public function test_excludes_entire_directory(): void
    {
        $this->createFile('root.txt', 'root');
        $this->createFile('node_modules/package.json', 'package');
        $this->createFile('node_modules/lib/index.js', 'js');
        $this->createFile('src/app.js', 'app');
        $this->createFile('src/utils.js', 'utils');

        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, ['node_modules/']);

        // Positive: files should be included
        $this->assertFileExists($this->destDir . '/root.txt');
        $this->assertFileExists($this->destDir . '/src/app.js');
        $this->assertFileExists($this->destDir . '/src/utils.js');
        $this->assertSame('root', file_get_contents($this->destDir . '/root.txt'));
        $this->assertSame('app', file_get_contents($this->destDir . '/src/app.js'));

        // Negative: excluded directory should not exist
        $this->assertDirectoryDoesNotExist($this->destDir . '/node_modules');
        $this->assertFileDoesNotExist($this->destDir . '/node_modules/package.json');
        $this->assertFileDoesNotExist($this->destDir . '/node_modules/lib/index.js');
    }

    public function test_excludes_nested_directory(): void
    {
        $this->createFile('wp-content/uploads/image.jpg', 'image');
        $this->createFile('wp-content/cache/page.html', 'cached');
        $this->createFile('wp-content/themes/style.css', 'styles');
        $this->createFile('wp-content/themes/functions.php', 'functions');

        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, ['wp-content/cache/']);

        // Positive: non-cache directories should be included
        $this->assertDirectoryExists($this->destDir . '/wp-content/uploads');
        $this->assertFileExists($this->destDir . '/wp-content/uploads/image.jpg');
        $this->assertSame('image', file_get_contents($this->destDir . '/wp-content/uploads/image.jpg'));
        $this->assertDirectoryExists($this->destDir . '/wp-content/themes');
        $this->assertFileExists($this->destDir . '/wp-content/themes/style.css');
        $this->assertFileExists($this->destDir . '/wp-content/themes/functions.php');

        // Negative: cache directory should be excluded
        $this->assertDirectoryDoesNotExist($this->destDir . '/wp-content/cache');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/cache/page.html');
    }

    public function test_excludes_files_in_any_directory_with_wildcard(): void
    {
        $this->createFile('root.txt', 'root');
        $this->createFile('folder1/test.log', 'log1');
        $this->createFile('folder1/keep.txt', 'keep1');
        $this->createFile('folder2/nested/debug.log', 'log2');
        $this->createFile('folder2/data.txt', 'data');

        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, ['**/*.log']);

        // Positive: non-.log files should be included at all levels
        $this->assertFileExists($this->destDir . '/root.txt');
        $this->assertSame('root', file_get_contents($this->destDir . '/root.txt'));
        $this->assertFileExists($this->destDir . '/folder1/keep.txt');
        $this->assertSame('keep1', file_get_contents($this->destDir . '/folder1/keep.txt'));
        $this->assertFileExists($this->destDir . '/folder2/data.txt');
        $this->assertSame('data', file_get_contents($this->destDir . '/folder2/data.txt'));

        // Negative: .log files should be excluded at all levels
        $this->assertFileDoesNotExist($this->destDir . '/folder1/test.log');
        $this->assertFileDoesNotExist($this->destDir . '/folder2/nested/debug.log');
    }

    public function test_excludes_hidden_files(): void
    {
        $this->createFile('visible.txt', 'visible');
        $this->createFile('another.md', 'markdown');
        $this->createFile('.hidden', 'hidden');
        $this->createFile('.env', 'environment');
        $this->createFile('.git/config', 'git config');

        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, ['.*']);

        // Positive: visible files should be included
        $this->assertFileExists($this->destDir . '/visible.txt');
        $this->assertSame('visible', file_get_contents($this->destDir . '/visible.txt'));
        $this->assertFileExists($this->destDir . '/another.md');
        $this->assertSame('markdown', file_get_contents($this->destDir . '/another.md'));

        // Negative: hidden files should be excluded
        $this->assertFileDoesNotExist($this->destDir . '/.hidden');
        $this->assertFileDoesNotExist($this->destDir . '/.env');
        $this->assertDirectoryDoesNotExist($this->destDir . '/.git');
        $this->assertFileDoesNotExist($this->destDir . '/.git/config');
    }

    public function test_excludes_specific_file_extension_in_nested_paths(): void
    {
        $this->createFile('wp-content/themes/theme1/style.css', 'css');
        $this->createFile('wp-content/themes/theme1/temp.tmp', 'temp1');
        $this->createFile('wp-content/plugins/plugin1/script.js', 'js');
        $this->createFile('wp-content/plugins/plugin1/cache.tmp', 'temp2');
        $this->createFile('root.tmp', 'temp3');

        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, ['*.tmp']);

        $this->assertFileExists($this->destDir . '/wp-content/themes/theme1/style.css');
        $this->assertFileExists($this->destDir . '/wp-content/plugins/plugin1/script.js');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/themes/theme1/temp.tmp');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/plugins/plugin1/cache.tmp');
        $this->assertFileDoesNotExist($this->destDir . '/root.tmp');
    }

    public function test_excludes_multiple_patterns_simultaneously(): void
    {
        $this->createFile('keep.txt', 'keep');
        $this->createFile('test.log', 'log');
        $this->createFile('.env', 'env');
        $this->createFile('node_modules/pkg.json', 'pkg');
        $this->createFile('cache/page.html', 'cached');
        $this->createFile('important.json', 'important');
        $this->createFile('data.xml', 'xml');

        $excludes = ['*.log', '.env', 'node_modules/', 'cache/'];

        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, $excludes);

        // Positive: files not matching any pattern should be included
        $this->assertFileExists($this->destDir . '/keep.txt');
        $this->assertSame('keep', file_get_contents($this->destDir . '/keep.txt'));
        $this->assertFileExists($this->destDir . '/important.json');
        $this->assertSame('important', file_get_contents($this->destDir . '/important.json'));
        $this->assertFileExists($this->destDir . '/data.xml');
        $this->assertSame('xml', file_get_contents($this->destDir . '/data.xml'));

        // Negative: files matching any exclude pattern should be excluded
        $this->assertFileDoesNotExist($this->destDir . '/test.log');
        $this->assertFileDoesNotExist($this->destDir . '/.env');
        $this->assertDirectoryDoesNotExist($this->destDir . '/node_modules');
        $this->assertFileDoesNotExist($this->destDir . '/node_modules/pkg.json');
        $this->assertDirectoryDoesNotExist($this->destDir . '/cache');
        $this->assertFileDoesNotExist($this->destDir . '/cache/page.html');
    }

    public function test_excludes_with_negation_pattern(): void
    {
        // Exclude all .txt files except important.txt
        $this->createFile('file1.txt', 'content1');
        $this->createFile('file2.txt', 'content2');
        $this->createFile('important.txt', 'important');
        $this->createFile('data.json', 'json');

        // Note: rsync negation patterns start with !
        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, ['*.txt']);

        $this->assertFileExists($this->destDir . '/data.json');
        $this->assertFileDoesNotExist($this->destDir . '/file1.txt');
        $this->assertFileDoesNotExist($this->destDir . '/file2.txt');
        $this->assertFileDoesNotExist($this->destDir . '/important.txt');
    }

    public function test_excludes_directory_but_not_similarly_named_file(): void
    {
        $this->createFile('cache/page.html', 'cached page');
        $this->createFile('cache.txt', 'cache file');
        $this->createFile('caches/data.txt', 'other dir');

        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, ['cache/']);

        // Should exclude cache/ directory but not cache.txt or caches/
        $this->assertFileExists($this->destDir . '/cache.txt');
        $this->assertFileExists($this->destDir . '/caches/data.txt');
        $this->assertDirectoryDoesNotExist($this->destDir . '/cache');
    }

    public function test_excludes_deep_nested_paths(): void
    {
        $this->createFile('a/b/c/d/e/deep.txt', 'deep');
        $this->createFile('a/b/keep.txt', 'keep');
        $this->createFile('a/b/c/exclude.txt', 'exclude');

        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, ['a/b/c/']);

        $this->assertFileExists($this->destDir . '/a/b/keep.txt');
        $this->assertDirectoryDoesNotExist($this->destDir . '/a/b/c');
    }

    public function test_excludes_wordpress_common_patterns(): void
    {
        // Common WordPress patterns that should be excludable
        $this->createFile('wp-content/uploads/2024/image.jpg', 'image');
        $this->createFile('wp-content/uploads/2024/11/photo.png', 'photo');
        $this->createFile('wp-content/cache/object-cache.php', 'cache');
        $this->createFile('wp-content/debug.log', 'debug');
        $this->createFile('.htaccess', 'htaccess');
        $this->createFile('wp-config.php', 'config');
        $this->createFile('index.php', 'index');
        $this->createFile('error_log', 'errors');

        $excludes = ['wp-content/cache/', '*.log', 'error_log', '.htaccess'];

        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, $excludes);

        // Positive: WordPress core files and uploads should be included
        $this->assertFileExists($this->destDir . '/wp-content/uploads/2024/image.jpg');
        $this->assertSame('image', file_get_contents($this->destDir . '/wp-content/uploads/2024/image.jpg'));
        $this->assertFileExists($this->destDir . '/wp-content/uploads/2024/11/photo.png');
        $this->assertSame('photo', file_get_contents($this->destDir . '/wp-content/uploads/2024/11/photo.png'));
        $this->assertFileExists($this->destDir . '/wp-config.php');
        $this->assertSame('config', file_get_contents($this->destDir . '/wp-config.php'));
        $this->assertFileExists($this->destDir . '/index.php');
        $this->assertSame('index', file_get_contents($this->destDir . '/index.php'));

        // Negative: cache, logs, and config files should be excluded
        $this->assertDirectoryDoesNotExist($this->destDir . '/wp-content/cache');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/cache/object-cache.php');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/debug.log');
        $this->assertFileDoesNotExist($this->destDir . '/.htaccess');
        $this->assertFileDoesNotExist($this->destDir . '/error_log');
    }

    public function test_excludes_full_file_paths_like_git_ls_files(): void
    {
        // Simulate git ls-files output: full relative paths
        $this->createFile('wp-content/themes/twentytwenty/style.css', 'theme css');
        $this->createFile('wp-content/themes/twentytwenty/functions.php', 'theme functions');
        $this->createFile('wp-content/plugins/akismet/akismet.php', 'plugin');
        $this->createFile('wp-content/uploads/2024/image.jpg', 'image');
        $this->createFile('wp-includes/version.php', 'version');
        $this->createFile('index.php', 'index');

        // Exclude specific files by full path (like git-tracked files)
        $excludes = [
            'wp-content/themes/twentytwenty/style.css',
            'wp-content/themes/twentytwenty/functions.php',
            'wp-content/plugins/akismet/akismet.php',
            'wp-includes/version.php',
            'index.php',
        ];

        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, $excludes);

        // Positive: uploads should be included (not git-tracked)
        $this->assertFileExists($this->destDir . '/wp-content/uploads/2024/image.jpg');
        $this->assertSame('image', file_get_contents($this->destDir . '/wp-content/uploads/2024/image.jpg'));

        // Negative: all git-tracked files should be excluded
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/themes/twentytwenty/style.css');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/themes/twentytwenty/functions.php');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/plugins/akismet/akismet.php');
        $this->assertFileDoesNotExist($this->destDir . '/wp-includes/version.php');
        $this->assertFileDoesNotExist($this->destDir . '/index.php');
    }

    public function test_excludes_mix_of_full_paths_and_patterns(): void
    {
        $this->createFile('wp-content/themes/custom/style.css', 'custom theme');
        $this->createFile('wp-content/themes/custom/functions.php', 'functions');
        $this->createFile('wp-content/plugins/myplugin/plugin.php', 'plugin');
        $this->createFile('wp-content/uploads/2024/photo.jpg', 'photo');
        $this->createFile('wp-content/uploads/file.pdf', 'pdf');
        $this->createFile('debug.log', 'debug');
        $this->createFile('wp-content/cache/page.html', 'cached');

        // Mix of full paths (git-tracked) and patterns (generated files)
        $excludes = [
            'wp-content/themes/custom/style.css',
            'wp-content/themes/custom/functions.php',
            'wp-content/plugins/myplugin/plugin.php',
            '*.log',
            'wp-content/cache/',
        ];

        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, $excludes);

        // Positive: uploads should be included
        $this->assertFileExists($this->destDir . '/wp-content/uploads/2024/photo.jpg');
        $this->assertSame('photo', file_get_contents($this->destDir . '/wp-content/uploads/2024/photo.jpg'));
        $this->assertFileExists($this->destDir . '/wp-content/uploads/file.pdf');
        $this->assertSame('pdf', file_get_contents($this->destDir . '/wp-content/uploads/file.pdf'));

        // Negative: git-tracked files (full paths) should be excluded
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/themes/custom/style.css');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/themes/custom/functions.php');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/plugins/myplugin/plugin.php');

        // Negative: pattern-matched files should be excluded
        $this->assertFileDoesNotExist($this->destDir . '/debug.log');
        $this->assertDirectoryDoesNotExist($this->destDir . '/wp-content/cache');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/cache/page.html');
    }

    public function test_excludes_nested_full_paths_without_affecting_siblings(): void
    {
        $this->createFile('wp-content/themes/theme1/template-parts/header.php', 'header');
        $this->createFile('wp-content/themes/theme1/template-parts/footer.php', 'footer');
        $this->createFile('wp-content/themes/theme1/assets/script.js', 'script');
        $this->createFile('wp-content/themes/theme1/screenshot.png', 'screenshot');
        $this->createFile('wp-content/uploads/theme-backup.zip', 'backup');

        // Exclude specific nested files by full path
        $excludes = ['wp-content/themes/theme1/template-parts/header.php', 'wp-content/themes/theme1/assets/script.js'];

        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, $excludes);

        // Positive: sibling files should be included
        $this->assertFileExists($this->destDir . '/wp-content/themes/theme1/template-parts/footer.php');
        $this->assertSame(
            'footer',
            file_get_contents($this->destDir . '/wp-content/themes/theme1/template-parts/footer.php'),
        );
        $this->assertFileExists($this->destDir . '/wp-content/themes/theme1/screenshot.png');
        $this->assertSame('screenshot', file_get_contents($this->destDir . '/wp-content/themes/theme1/screenshot.png'));
        $this->assertFileExists($this->destDir . '/wp-content/uploads/theme-backup.zip');
        $this->assertSame('backup', file_get_contents($this->destDir . '/wp-content/uploads/theme-backup.zip'));

        // Negative: excluded files should not exist
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/themes/theme1/template-parts/header.php');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/themes/theme1/assets/script.js');
    }

    public function test_excludes_full_paths_with_special_characters(): void
    {
        $this->createFile('wp-content/uploads/my file with spaces.jpg', 'spaces');
        $this->createFile('wp-content/plugins/my-plugin/file-name.php', 'dashes');
        $this->createFile('wp-content/themes/theme_name/template.php', 'underscore');
        $this->createFile('wp-content/uploads/keep-this.jpg', 'keep');

        // Exclude files with special characters in path
        $excludes = [
            'wp-content/uploads/my file with spaces.jpg',
            'wp-content/plugins/my-plugin/file-name.php',
            'wp-content/themes/theme_name/template.php',
        ];

        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, $excludes);

        // Positive: similar files should still be included
        $this->assertFileExists($this->destDir . '/wp-content/uploads/keep-this.jpg');
        $this->assertSame('keep', file_get_contents($this->destDir . '/wp-content/uploads/keep-this.jpg'));

        // Negative: files with special characters should be excluded
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/uploads/my file with spaces.jpg');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/plugins/my-plugin/file-name.php');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/themes/theme_name/template.php');
    }

    public function test_excludes_hundreds_of_git_tracked_files(): void
    {
        // Simulate a realistic scenario with many git-tracked files
        $gitTrackedFiles = [];

        // Create WordPress core files
        for ($i = 1; $i <= 50; $i++) {
            $path = "wp-includes/file{$i}.php";
            $this->createFile($path, "core{$i}");
            $gitTrackedFiles[] = $path;
        }

        // Create theme files
        for ($i = 1; $i <= 30; $i++) {
            $path = "wp-content/themes/mytheme/file{$i}.php";
            $this->createFile($path, "theme{$i}");
            $gitTrackedFiles[] = $path;
        }

        // Create plugin files
        for ($i = 1; $i <= 20; $i++) {
            $path = "wp-content/plugins/myplugin/file{$i}.php";
            $this->createFile($path, "plugin{$i}");
            $gitTrackedFiles[] = $path;
        }

        // Create uploads (should NOT be excluded)
        $this->createFile('wp-content/uploads/image1.jpg', 'image1');
        $this->createFile('wp-content/uploads/image2.jpg', 'image2');
        $this->createFile('wp-content/uploads/2024/photo.jpg', 'photo');

        $rsync = new RsyncService($this->output, false, false);
        $rsync->syncFiles($this->sourceDir, $this->destDir, $gitTrackedFiles);

        // Positive: uploads should be included
        $this->assertFileExists($this->destDir . '/wp-content/uploads/image1.jpg');
        $this->assertSame('image1', file_get_contents($this->destDir . '/wp-content/uploads/image1.jpg'));
        $this->assertFileExists($this->destDir . '/wp-content/uploads/image2.jpg');
        $this->assertFileExists($this->destDir . '/wp-content/uploads/2024/photo.jpg');

        // Negative: sample of git-tracked files should be excluded
        $this->assertFileDoesNotExist($this->destDir . '/wp-includes/file1.php');
        $this->assertFileDoesNotExist($this->destDir . '/wp-includes/file25.php');
        $this->assertFileDoesNotExist($this->destDir . '/wp-includes/file50.php');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/themes/mytheme/file1.php');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/themes/mytheme/file30.php');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/plugins/myplugin/file1.php');
        $this->assertFileDoesNotExist($this->destDir . '/wp-content/plugins/myplugin/file20.php');
    }

    private function createFile(string $relativePath, string $content): void
    {
        $fullPath = $this->sourceDir . '/' . $relativePath;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($fullPath, $content);
    }

    private function cleanupDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
