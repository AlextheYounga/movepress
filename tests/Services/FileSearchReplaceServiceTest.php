<?php

declare(strict_types=1);

namespace Movepress\Tests\Services;

use Movepress\Services\FileSearchReplaceService;
use PHPUnit\Framework\TestCase;

class FileSearchReplaceServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/movepress-file-test-' . uniqid();
        if (!mkdir($this->tempDir) && !is_dir($this->tempDir)) {
            throw new \RuntimeException('Unable to create temp directory');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_replaces_text_in_allowed_files(): void
    {
        $file = $this->tempDir . '/example.php';
        file_put_contents($file, '<?php echo "https://old.test";');

        $service = new FileSearchReplaceService();
        $result = $service->replaceInPath($this->tempDir, 'https://old.test', 'https://new.test');

        $this->assertSame(1, $result['filesChecked']);
        $this->assertSame(1, $result['filesModified']);
        $this->assertStringContainsString('https://new.test', file_get_contents($file));
    }

    public function test_skips_files_with_binary_content(): void
    {
        $file = $this->tempDir . '/binary.php';
        file_put_contents($file, "\0\0https://old.test");

        $service = new FileSearchReplaceService();
        $result = $service->replaceInPath($this->tempDir, 'https://old.test', 'https://new.test');

        $this->assertSame(0, $result['filesChecked']);
        $this->assertSame(0, $result['filesModified']);
        $this->assertStringContainsString('https://old.test', file_get_contents($file));
    }

    public function test_skips_files_with_disallowed_extensions(): void
    {
        $file = $this->tempDir . '/image.jpg';
        file_put_contents($file, 'https://old.test');

        $service = new FileSearchReplaceService();
        $result = $service->replaceInPath($this->tempDir, 'https://old.test', 'https://new.test');

        $this->assertSame(0, $result['filesChecked']);
        $this->assertSame(0, $result['filesModified']);
        $this->assertStringContainsString('https://old.test', file_get_contents($file));
    }

    public function test_replaces_json_escaped_urls(): void
    {
        $file = $this->tempDir . '/data.json';
        file_put_contents($file, '{"url":"https:\/\/old.test"}');

        $service = new FileSearchReplaceService();
        $result = $service->replaceInPath($this->tempDir, 'https://old.test', 'https://new.test');

        $this->assertSame(1, $result['filesChecked']);
        $this->assertSame(1, $result['filesModified']);
        $this->assertStringContainsString('https:\\/\\/new.test', file_get_contents($file));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
