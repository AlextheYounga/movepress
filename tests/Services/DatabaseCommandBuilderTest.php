<?php

declare(strict_types=1);

namespace Movepress\Tests\Services;

use Movepress\Services\DatabaseCommandBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseCommandBuilderTest extends TestCase
{
    private DatabaseCommandBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new DatabaseCommandBuilder();
    }

    public function testBuildExportCommandWithCompression(): void
    {
        $dbConfig = [
            'name' => 'testdb',
            'user' => 'testuser',
            'password' => 'testpass',
            'host' => 'localhost'
        ];

        $command = $this->builder->buildExportCommand($dbConfig, '/tmp/output.sql.gz', true);

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

    public function testBuildExportCommandWithoutCompression(): void
    {
        $dbConfig = [
            'name' => 'testdb',
            'user' => 'testuser',
            'password' => 'testpass',
            'host' => 'localhost'
        ];

        $command = $this->builder->buildExportCommand($dbConfig, '/tmp/output.sql', false);

        $this->assertStringContainsString('mysqldump', $command);
        $this->assertStringNotContainsString('gzip', $command);
        $this->assertStringContainsString('> \'/tmp/output.sql\'', $command);
    }

    public function testBuildExportCommandWithoutPassword(): void
    {
        $dbConfig = [
            'name' => 'testdb',
            'user' => 'testuser',
            'host' => 'localhost'
        ];

        $command = $this->builder->buildExportCommand($dbConfig, '/tmp/output.sql', false);

        $this->assertStringNotContainsString('--password', $command);
    }

    public function testBuildImportCommandWithCompression(): void
    {
        $dbConfig = [
            'name' => 'testdb',
            'user' => 'testuser',
            'password' => 'testpass',
            'host' => 'localhost'
        ];

        $command = $this->builder->buildImportCommand($dbConfig, '/tmp/input.sql.gz');

        $this->assertStringContainsString('gunzip', $command);
        $this->assertStringContainsString('mysql', $command);
        $this->assertStringContainsString('--user=\'testuser\'', $command);
        $this->assertStringContainsString('--password=\'testpass\'', $command);
        $this->assertStringContainsString('\'testdb\'', $command);
    }

    public function testBuildImportCommandWithoutCompression(): void
    {
        $dbConfig = [
            'name' => 'testdb',
            'user' => 'testuser',
            'password' => 'testpass',
            'host' => 'localhost'
        ];

        $command = $this->builder->buildImportCommand($dbConfig, '/tmp/input.sql');

        $this->assertStringNotContainsString('gunzip', $command);
        $this->assertStringContainsString('mysql', $command);
        $this->assertStringContainsString('< \'/tmp/input.sql\'', $command);
    }

    public function testBuildSearchReplaceCommand(): void
    {
        $command = $this->builder->buildSearchReplaceCommand(
            '/usr/bin/wp',
            '/var/www/html',
            'http://old.test',
            'http://new.test'
        );

        $this->assertStringContainsString('search-replace', $command);
        $this->assertStringContainsString('\'http://old.test\'', $command);
        $this->assertStringContainsString('\'http://new.test\'', $command);
        $this->assertStringContainsString('--path=\'/var/www/html\'', $command);
        $this->assertStringContainsString('--skip-columns=guid', $command);
    }

    public function testValidateDatabaseConfigThrowsExceptionForMissingName(): void
    {
        $dbConfig = [
            'user' => 'testuser',
            'host' => 'localhost'
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database configuration missing required field: name');

        $this->builder->validateDatabaseConfig($dbConfig);
    }

    public function testValidateDatabaseConfigThrowsExceptionForMissingUser(): void
    {
        $dbConfig = [
            'name' => 'testdb',
            'host' => 'localhost'
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database configuration missing required field: user');

        $this->builder->validateDatabaseConfig($dbConfig);
    }

    public function testValidateDatabaseConfigThrowsExceptionForMissingHost(): void
    {
        $dbConfig = [
            'name' => 'testdb',
            'user' => 'testuser'
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database configuration missing required field: host');

        $this->builder->validateDatabaseConfig($dbConfig);
    }

    public function testValidateDatabaseConfigPassesWithAllRequiredFields(): void
    {
        $dbConfig = [
            'name' => 'testdb',
            'user' => 'testuser',
            'host' => 'localhost'
        ];

        $this->builder->validateDatabaseConfig($dbConfig);
        $this->assertTrue(true);
    }

    public function testSearchReplaceCommandStructure(): void
    {
        $command = $this->builder->buildSearchReplaceCommand(
            '/path/to/wp',
            '/var/www/wordpress',
            'http://old.test',
            'http://new.test'
        );

        // Verify command structure
        $this->assertStringContainsString('search-replace', $command);
        $this->assertStringContainsString('http://old.test', $command);
        $this->assertStringContainsString('http://new.test', $command);
        $this->assertStringContainsString('--path=', $command);
        $this->assertStringContainsString('/var/www/wordpress', $command);
        $this->assertStringContainsString('--skip-columns=guid', $command);
        $this->assertStringContainsString('--quiet', $command);
    }
}
