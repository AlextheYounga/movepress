<?php

declare(strict_types=1);

namespace Movepress\Tests\Services;

use Movepress\Services\DatabaseService;
use Movepress\Services\SshService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use RuntimeException;
use ReflectionClass;

class DatabaseServiceTest extends TestCase
{
    private BufferedOutput $output;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
        $this->tempDir = sys_get_temp_dir() . '/movepress_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testConstructorInitializesProperties(): void
    {
        $service = new DatabaseService($this->output, true);

        $reflection = new ReflectionClass($service);
        $outputProp = $reflection->getProperty('output');
        $outputProp->setAccessible(true);
        $verboseProp = $reflection->getProperty('verbose');
        $verboseProp->setAccessible(true);

        $this->assertSame($this->output, $outputProp->getValue($service));
        $this->assertTrue($verboseProp->getValue($service));
    }

    public function testIsMysqldumpAvailable(): void
    {
        // This test just verifies the method runs without error
        // Actual result depends on system configuration
        $result = DatabaseService::isMysqldumpAvailable();
        $this->assertIsBool($result);
    }

    public function testIsMysqlAvailable(): void
    {
        $result = DatabaseService::isMysqlAvailable();
        $this->assertIsBool($result);
    }
}
