<?php

declare(strict_types=1);

namespace Movepress\Tests\Services\Sync;

use Movepress\Services\Sync\LocalStagingService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class LocalStagingServiceTest extends TestCase
{
    private string $sourceDir;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
        $this->sourceDir = sys_get_temp_dir() . '/movepress_stage_test_' . uniqid();

        $uploadsDir = $this->sourceDir . '/wp-content/uploads';
        if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0777, true)) {
            $this->fail('Failed to create fixture directory: ' . $uploadsDir);
        }

        file_put_contents($uploadsDir . '/sample.jpg', 'example');
    }

    protected function tearDown(): void
    {
        $this->cleanupDir($this->sourceDir);
    }

    public function test_stage_creates_staging_directory_with_files(): void
    {
        $service = new LocalStagingService($this->output, false);

        $staged = $service->stage($this->sourceDir, [], false);

        $this->assertFileExists($staged . '/wp-content/uploads/sample.jpg');

        $service->cleanup($staged);
        $this->assertDirectoryDoesNotExist($staged);
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
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
