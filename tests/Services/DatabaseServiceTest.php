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

    public function testBootstrapWordpressEnvironmentSetsEnvAndServerGlobals(): void
    {
        $service = new DatabaseService($this->output, false);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('bootstrapWordpressEnvironment');
        $method->setAccessible(true);

        $dbConfig = [
            'host' => '127.0.0.1:3307',
            'user' => 'dev',
            'password' => 'secret',
            'name' => 'wp',
        ];

        $originalServer = $_SERVER;
        $originalEnv = $_ENV;
        $envKeys = [
            'WORDPRESS_DB_HOST',
            'WORDPRESS_DB_USER',
            'WORDPRESS_DB_PASSWORD',
            'WORDPRESS_DB_NAME',
            'DB_HOST',
            'DB_USER',
            'DB_PASSWORD',
            'DB_NAME',
            'MYSQL_HOST',
            'MYSQL_USER',
            'MYSQL_PASSWORD',
            'MYSQL_DATABASE',
        ];

        try {
            $method->invoke($service, $dbConfig, 'https://wp.breakstuff.localhost:8443/path');

            $this->assertSame('127.0.0.1:3307', getenv('WORDPRESS_DB_HOST'));
            $this->assertSame('dev', getenv('WORDPRESS_DB_USER'));
            $this->assertSame('wp.breakstuff.localhost:8443', $_SERVER['HTTP_HOST']);
            $this->assertSame('wp.breakstuff.localhost', $_SERVER['SERVER_NAME']);
            $this->assertSame('8443', $_SERVER['SERVER_PORT']);
            $this->assertSame('https', $_SERVER['REQUEST_SCHEME']);
            $this->assertSame('on', $_SERVER['HTTPS']);
        } finally {
            foreach ($envKeys as $key) {
                putenv($key . '=');
                unset($_ENV[$key]);
            }
            $_SERVER = $originalServer;
            $_ENV = $originalEnv;
        }
    }

    public function testBootstrapWordpressEnvironmentFallsBackToLocalhost(): void
    {
        $service = new DatabaseService($this->output, false);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('bootstrapWordpressEnvironment');
        $method->setAccessible(true);

        $dbConfig = [
            'host' => 'mysql:3306',
            'user' => 'root',
            'password' => 'pass',
            'name' => 'wordpress',
        ];

        $originalServer = $_SERVER;
        $originalEnv = $_ENV;
        $envKeys = [
            'WORDPRESS_DB_HOST',
            'WORDPRESS_DB_USER',
            'WORDPRESS_DB_PASSWORD',
            'WORDPRESS_DB_NAME',
            'DB_HOST',
            'DB_USER',
            'DB_PASSWORD',
            'DB_NAME',
            'MYSQL_HOST',
            'MYSQL_USER',
            'MYSQL_PASSWORD',
            'MYSQL_DATABASE',
        ];

        try {
            $method->invoke($service, $dbConfig, 'not-a-url');

            $this->assertSame('mysql:3306', getenv('WORDPRESS_DB_HOST'));
            $this->assertSame('localhost', $_SERVER['HTTP_HOST']);
            $this->assertSame('localhost', $_SERVER['SERVER_NAME']);
            $this->assertSame('80', $_SERVER['SERVER_PORT']);
            $this->assertSame('http', $_SERVER['REQUEST_SCHEME']);
            $this->assertSame('off', $_SERVER['HTTPS']);
        } finally {
            foreach ($envKeys as $key) {
                putenv($key . '=');
                unset($_ENV[$key]);
            }
            $_SERVER = $originalServer;
            $_ENV = $originalEnv;
        }
    }
}
