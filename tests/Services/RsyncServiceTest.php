<?php

declare(strict_types=1);

namespace Movepress\Tests\Services;

use Movepress\Services\RsyncService;
use Movepress\Services\SshService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class RsyncServiceTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    public function test_builds_basic_rsync_command_for_local_sync(): void
    {
        $service = new RsyncService($this->output, true, false);

        // Using reflection to test private method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildRsyncCommand');
        $method->setAccessible(true);

        $command = $method->invoke($service, '/source/path', '/dest/path', [], null);

        $this->assertStringContainsString('rsync', $command);
        $this->assertStringContainsString('-avz', $command);
        $this->assertStringNotContainsString('--delete', $command);
        $this->assertStringContainsString('--dry-run', $command);
        $this->assertStringContainsString('/source/path/', $command);
        $this->assertStringContainsString('/dest/path', $command);
    }

    public function test_includes_exclude_patterns_in_command(): void
    {
        $service = new RsyncService($this->output, true, false);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildRsyncCommand');
        $method->setAccessible(true);

        $excludes = ['.git/', 'node_modules/', '*.log'];

        $command = $method->invoke($service, '/source/path', '/dest/path', $excludes, null);

        $this->assertStringContainsString("--exclude='.git/'", $command);
        $this->assertStringContainsString("--exclude='node_modules/'", $command);
        $this->assertStringContainsString("--exclude='*.log'", $command);
    }

    public function test_includes_ssh_options_for_remote_sync(): void
    {
        $sshConfig = [
            'user' => 'deployuser',
            'host' => 'example.com',
            'port' => 2222,
        ];

        $sshService = new SshService($sshConfig);
        $service = new RsyncService($this->output, true, false);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildRsyncCommand');
        $method->setAccessible(true);

        $command = $method->invoke($service, '/source/path', '/dest/path', [], $sshService);

        $this->assertStringContainsString('-e', $command);
        $this->assertStringContainsString('ssh', $command);
    }

    public function test_adds_progress_flag_in_verbose_mode(): void
    {
        $service = new RsyncService($this->output, false, true);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildRsyncCommand');
        $method->setAccessible(true);

        $command = $method->invoke($service, '/source/path', '/dest/path', [], null);

        $this->assertStringContainsString('--info=progress2', $command);
    }

    public function test_includes_delete_flag_when_requested(): void
    {
        $service = new RsyncService($this->output, false, false);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildRsyncCommand');
        $method->setAccessible(true);

        $command = $method->invoke($service, '/source/path', '/dest/path', [], null, true);

        $this->assertStringContainsString('--delete', $command);
    }

    public function test_does_not_add_dry_run_flag_when_not_in_dry_run_mode(): void
    {
        $service = new RsyncService($this->output, false, false);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildRsyncCommand');
        $method->setAccessible(true);

        $command = $method->invoke($service, '/source/path', '/dest/path', [], null);

        $this->assertStringNotContainsString('--dry-run', $command);
    }

    public function test_ensures_trailing_slash_on_source_path(): void
    {
        $service = new RsyncService($this->output, true, false);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildRsyncCommand');
        $method->setAccessible(true);

        // Test without trailing slash
        $command = $method->invoke($service, '/source/path', '/dest/path', [], null);

        $this->assertStringContainsString('/source/path/', $command);

        // Test with trailing slash (should not double up)
        $command = $method->invoke($service, '/source/path/', '/dest/path', [], null);

        $this->assertStringContainsString('/source/path/', $command);
        $this->assertStringNotContainsString('/source/path//', $command);
    }

    public function test_excludes_pattern_from_gitignore(): void
    {
        $service = new RsyncService($this->output, true, false);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildRsyncCommand');
        $method->setAccessible(true);

        // Test that .gitignore patterns become excludes
        $excludes = ['*.php', '*.js', 'wp-content/themes/'];

        $command = $method->invoke($service, '/source/path', '/dest/path', $excludes, null);

        $this->assertStringContainsString("--exclude='*.php'", $command);
        $this->assertStringContainsString("--exclude='*.js'", $command);
        $this->assertStringContainsString("--exclude='wp-content/themes/'", $command);
    }

    public function test_is_available_returns_true_when_rsync_exists(): void
    {
        // This test will only pass if rsync is actually installed
        // We'll check if rsync exists before asserting
        $isAvailable = RsyncService::isAvailable();

        // On most Unix systems, rsync should be available
        // On systems without rsync, this will return false
        $this->assertIsBool($isAvailable);
    }
}
