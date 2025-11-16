<?php

declare(strict_types=1);

namespace Movepress\Tests\Services;

use Movepress\Services\SshService;
use PHPUnit\Framework\TestCase;

class SshServiceTest extends TestCase
{
    public function test_builds_connection_string_from_user_and_host(): void
    {
        $config = [
            'user' => 'deployuser',
            'host' => 'example.com',
        ];

        $service = new SshService($config);
        $connectionString = $service->buildConnectionString();

        $this->assertEquals('deployuser@example.com', $connectionString);
    }

    public function test_throws_exception_when_user_is_missing(): void
    {
        $config = [
            'host' => 'example.com',
        ];

        $service = new SshService($config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SSH user not configured');

        $service->buildConnectionString();
    }

    public function test_throws_exception_when_host_is_missing(): void
    {
        $config = [
            'user' => 'deployuser',
        ];

        $service = new SshService($config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SSH host not configured');

        $service->buildConnectionString();
    }

    public function test_get_ssh_options_returns_empty_for_default_port(): void
    {
        $config = [
            'user' => 'deployuser',
            'host' => 'example.com',
            'port' => 22,
        ];

        $service = new SshService($config);
        $options = $service->getSshOptions();

        // Should only have StrictHostKeyChecking option, no port
        $this->assertContains('-o', $options);
        $this->assertContains('StrictHostKeyChecking=no', $options);
        $this->assertNotContains('-p', $options);
    }

    public function test_get_ssh_options_includes_custom_port(): void
    {
        $config = [
            'user' => 'deployuser',
            'host' => 'example.com',
            'port' => 2222,
        ];

        $service = new SshService($config);
        $options = $service->getSshOptions();

        $this->assertContains('-p', $options);
        $this->assertContains('2222', $options);
    }

    public function test_get_ssh_options_includes_key_path_when_file_exists(): void
    {
        $tempKey = tempnam(sys_get_temp_dir(), 'ssh_key');

        $config = [
            'user' => 'deployuser',
            'host' => 'example.com',
            'key' => $tempKey,
        ];

        $service = new SshService($config);
        $options = $service->getSshOptions();

        $this->assertContains('-i', $options);
        $this->assertContains($tempKey, $options);

        unlink($tempKey);
    }

    public function test_throws_exception_when_key_file_does_not_exist(): void
    {
        $config = [
            'user' => 'deployuser',
            'host' => 'example.com',
            'key' => '/nonexistent/key/path',
        ];

        $service = new SshService($config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SSH key not found');

        $service->getSshOptions();
    }

    public function test_expands_tilde_in_key_path(): void
    {
        $homeDir = getenv('HOME') ?: getenv('USERPROFILE');
        $keyFile = tempnam($homeDir, 'ssh_key_test');

        $config = [
            'user' => 'deployuser',
            'host' => 'example.com',
            'key' => '~/' . basename($keyFile),
        ];

        $service = new SshService($config);
        $options = $service->getSshOptions();

        $this->assertContains('-i', $options);
        $this->assertContains($keyFile, $options);

        unlink($keyFile);
    }

    public function test_includes_strict_host_key_checking_disabled(): void
    {
        $config = [
            'user' => 'deployuser',
            'host' => 'example.com',
        ];

        $service = new SshService($config);
        $options = $service->getSshOptions();

        $this->assertContains('-o', $options);
        $this->assertContains('StrictHostKeyChecking=no', $options);
    }
}
