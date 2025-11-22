<?php

declare(strict_types=1);

namespace Movepress\Tests\Commands;

use Movepress\Commands\PushCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;

class GitTrackedFilesTest extends TestCase
{
    private string $testDir;
    private string $originalDir;
    private PushCommand $command;

    protected function setUp(): void
    {
        $this->originalDir = getcwd();
        $this->testDir = sys_get_temp_dir() . '/movepress-git-test-' . uniqid();
        mkdir($this->testDir);

        $application = new Application();
        $application->add(new PushCommand());
        $this->command = $application->find('push');
    }

    protected function tearDown(): void
    {
        chdir($this->originalDir);
        $this->removeDirectory($this->testDir);
    }

    public function test_returns_empty_array_when_path_does_not_exist(): void
    {
        $result = $this->command->getGitTrackedFiles('/nonexistent/path/does/not/exist');

        $this->assertSame([], $result);
    }

    public function test_returns_empty_array_when_not_a_git_repository(): void
    {
        $nonGitPath = $this->testDir . '/not-git';
        mkdir($nonGitPath);

        $result = $this->command->getGitTrackedFiles($nonGitPath);

        $this->assertSame([], $result);
    }

    public function test_returns_tracked_files_from_repository_root(): void
    {
        $this->initializeGitRepo($this->testDir);
        $this->createAndCommitFile('index.php');
        $this->createAndCommitFile('wp-config.php');

        $result = $this->command->getGitTrackedFiles($this->testDir);

        $this->assertCount(2, $result);
        $this->assertContains('index.php', $result);
        $this->assertContains('wp-config.php', $result);
    }

    public function test_returns_tracked_files_relative_to_subdirectory(): void
    {
        $this->initializeGitRepo($this->testDir);

        $wpContentDir = $this->testDir . '/wp-content';
        mkdir($wpContentDir);

        $this->createAndCommitFile('index.php');
        $this->createAndCommitFile('wp-content/index.php');
        $this->createAndCommitFile('wp-content/uploads/image.jpg');

        $result = $this->command->getGitTrackedFiles($wpContentDir);

        $this->assertCount(2, $result);
        $this->assertContains('index.php', $result);
        $this->assertContains('uploads/image.jpg', $result);
        $this->assertNotContains('../index.php', $result);
    }

    public function test_excludes_untracked_files(): void
    {
        $this->initializeGitRepo($this->testDir);
        $this->createAndCommitFile('tracked.php');
        
        file_put_contents($this->testDir . '/untracked.php', "<?php\n");

        $result = $this->command->getGitTrackedFiles($this->testDir);

        $this->assertContains('tracked.php', $result);
        $this->assertNotContains('untracked.php', $result);
    }

    private function initializeGitRepo(string $path): void
    {
        chdir($path);
        exec('git init 2>&1');
        exec('git config user.email "test@example.com" 2>&1');
        exec('git config user.name "Test User" 2>&1');
    }

    private function createAndCommitFile(string $relativePath): void
    {
        $fullPath = $this->testDir . '/' . $relativePath;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($fullPath, "<?php\n// Test file: {$relativePath}\n");
        $this->gitAdd($relativePath);
        $this->gitCommit("Add {$relativePath}");
    }

    private function gitAdd(string $file): void
    {
        exec(sprintf('git add %s 2>&1', escapeshellarg($file)));
    }

    private function gitCommit(string $message): void
    {
        exec(sprintf('git commit -m %s 2>&1', escapeshellarg($message)));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
