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

    public function testBuildMysqldumpCommandWithCompression(): void
    {
        $service = new DatabaseService($this->output);
        $dbConfig = [
            'name' => 'testdb',
            'user' => 'testuser',
            'password' => 'testpass',
            'host' => 'localhost'
        ];

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildMysqldumpCommand');
        $method->setAccessible(true);

        $command = $method->invoke($service, $dbConfig, '/tmp/output.sql.gz', true);

        $this->assertStringContainsString('mysqldump', $command);
        $this->assertStringContainsString('--user=\'testuser\'', $command);
        $this->assertStringContainsString('--password=\'testpass\'', $command);
        $this->assertStringContainsString('--host=\'localhost\'', $command);
        $this->assertStringContainsString('\'testdb\'', $command);
        $this->assertStringContainsString('gzip', $command);
        $this->assertStringContainsString('--single-transaction', $command);
        $this->assertStringContainsString('--quick', $command);
        $this->assertStringContainsString('--lock-tables=false', $command);
    }

    public function testBuildMysqldumpCommandWithoutCompression(): void
    {
        $service = new DatabaseService($this->output);
        $dbConfig = [
            'name' => 'testdb',
            'user' => 'testuser',
            'password' => 'testpass',
            'host' => 'localhost'
        ];

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildMysqldumpCommand');
        $method->setAccessible(true);

        $command = $method->invoke($service, $dbConfig, '/tmp/output.sql', false);

        $this->assertStringContainsString('mysqldump', $command);
        $this->assertStringNotContainsString('gzip', $command);
        $this->assertStringContainsString('> \'/tmp/output.sql\'', $command);
    }

    public function testBuildMysqldumpCommandWithoutPassword(): void
    {
        $service = new DatabaseService($this->output);
        $dbConfig = [
            'name' => 'testdb',
            'user' => 'testuser',
            'host' => 'localhost'
        ];

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildMysqldumpCommand');
        $method->setAccessible(true);

        $command = $method->invoke($service, $dbConfig, '/tmp/output.sql', false);

        $this->assertStringNotContainsString('--password', $command);
    }

    public function testBuildMysqlImportCommandWithCompression(): void
    {
        $service = new DatabaseService($this->output);
        $dbConfig = [
            'name' => 'testdb',
            'user' => 'testuser',
            'password' => 'testpass',
            'host' => 'localhost'
        ];

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildMysqlImportCommand');
        $method->setAccessible(true);

        $command = $method->invoke($service, $dbConfig, '/tmp/input.sql.gz');

        $this->assertStringContainsString('gunzip', $command);
        $this->assertStringContainsString('mysql', $command);
        $this->assertStringContainsString('--user=\'testuser\'', $command);
        $this->assertStringContainsString('--password=\'testpass\'', $command);
        $this->assertStringContainsString('\'testdb\'', $command);
    }

    public function testBuildMysqlImportCommandWithoutCompression(): void
    {
        $service = new DatabaseService($this->output);
        $dbConfig = [
            'name' => 'testdb',
            'user' => 'testuser',
            'password' => 'testpass',
            'host' => 'localhost'
        ];

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildMysqlImportCommand');
        $method->setAccessible(true);

        $command = $method->invoke($service, $dbConfig, '/tmp/input.sql');

        $this->assertStringNotContainsString('gunzip', $command);
        $this->assertStringContainsString('mysql', $command);
        $this->assertStringContainsString('< \'/tmp/input.sql\'', $command);
    }

    public function testBuildSshCommand(): void
    {
        $service = new DatabaseService($this->output);
        
        $sshService = new SshService([
            'host' => 'example.com',
            'user' => 'deploy',
            'port' => 2222
        ]);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildSshCommand');
        $method->setAccessible(true);

        $command = $method->invoke($service, $sshService, 'ls -la');

        $this->assertStringContainsString('ssh', $command);
        $this->assertStringContainsString('-p', $command);
        $this->assertStringContainsString('2222', $command);
        $this->assertStringContainsString('deploy@example.com', $command);
        $this->assertStringContainsString('\'ls -la\'', $command);
    }

    public function testBuildScpCommandFromRemote(): void
    {
        $service = new DatabaseService($this->output);
        
        $sshService = new SshService([
            'host' => 'example.com',
            'user' => 'deploy',
            'port' => 2222
        ]);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildScpCommand');
        $method->setAccessible(true);

        $command = $method->invoke($service, $sshService, '/remote/file.sql', '/local/file.sql', true);

        $this->assertStringContainsString('scp', $command);
        $this->assertStringContainsString('-P', $command); // SCP uses -P not -p
        $this->assertStringContainsString('2222', $command);
        $this->assertStringContainsString('deploy@example.com:\'/remote/file.sql\'', $command);
        $this->assertStringContainsString('\'/local/file.sql\'', $command);
    }

    public function testBuildScpCommandToRemote(): void
    {
        $service = new DatabaseService($this->output);
        
        $sshService = new SshService([
            'host' => 'example.com',
            'user' => 'deploy',
            'port' => 2222
        ]);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildScpCommand');
        $method->setAccessible(true);

        $command = $method->invoke($service, $sshService, '/local/file.sql', '/remote/file.sql', false);

        $this->assertStringContainsString('scp', $command);
        $this->assertStringContainsString('\'/local/file.sql\'', $command);
        $this->assertStringContainsString('deploy@example.com:\'/remote/file.sql\'', $command);
    }

    public function testBuildScpCommandWithSshKey(): void
    {
        // Create a temp key file
        $keyFile = $this->tempDir . '/test_key';
        touch($keyFile);

        $service = new DatabaseService($this->output);
        
        $sshService = new SshService([
            'host' => 'example.com',
            'user' => 'deploy',
            'key' => $keyFile
        ]);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildScpCommand');
        $method->setAccessible(true);

        $command = $method->invoke($service, $sshService, '/local/file.sql', '/remote/file.sql', false);

        $this->assertStringContainsString('-i', $command);
        $this->assertStringContainsString($keyFile, $command);
    }

    public function testValidateDatabaseConfigThrowsExceptionForMissingName(): void
    {
        $service = new DatabaseService($this->output);
        $dbConfig = [
            'user' => 'testuser',
            'host' => 'localhost'
        ];

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('validateDatabaseConfig');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database configuration missing required field: name');
        
        $method->invoke($service, $dbConfig);
    }

    public function testValidateDatabaseConfigThrowsExceptionForMissingUser(): void
    {
        $service = new DatabaseService($this->output);
        $dbConfig = [
            'name' => 'testdb',
            'host' => 'localhost'
        ];

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('validateDatabaseConfig');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database configuration missing required field: user');
        
        $method->invoke($service, $dbConfig);
    }

    public function testValidateDatabaseConfigThrowsExceptionForMissingHost(): void
    {
        $service = new DatabaseService($this->output);
        $dbConfig = [
            'name' => 'testdb',
            'user' => 'testuser'
        ];

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('validateDatabaseConfig');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database configuration missing required field: host');
        
        $method->invoke($service, $dbConfig);
    }

    public function testValidateDatabaseConfigPassesWithAllRequiredFields(): void
    {
        $service = new DatabaseService($this->output);
        $dbConfig = [
            'name' => 'testdb',
            'user' => 'testuser',
            'host' => 'localhost'
        ];

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('validateDatabaseConfig');
        $method->setAccessible(true);

        // Should not throw exception
        $method->invoke($service, $dbConfig);
        $this->assertTrue(true);
    }

    public function testFindWpCliBinaryReturnsVendorPath(): void
    {
        $service = new DatabaseService($this->output);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('findWpCliBinary');
        $method->setAccessible(true);

        $binary = $method->invoke($service);

        // Should return either vendor path or 'wp'
        $this->assertTrue(
            str_contains($binary, 'vendor/bin/wp') || $binary === 'wp'
        );
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

    public function testIsWpCliAvailable(): void
    {
        $result = DatabaseService::isWpCliAvailable();
        $this->assertIsBool($result);
    }
}
