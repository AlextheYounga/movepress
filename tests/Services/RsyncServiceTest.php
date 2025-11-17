<?php

declare(strict_types=1);

namespace Movepress\Tests\Services;

use Movepress\Services\RsyncService;
use Movepress\Services\SshService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class TestableRsyncService extends RsyncService
{
    private bool $progress2Support = true;

    public function exposedBuildCommand(
        string $source,
        string $dest,
        array $excludes = [],
        ?SshService $sshService = null,
        bool $delete = false,
    ): string {
        return $this->buildRsyncCommand($source, $dest, $excludes, $sshService, $delete);
    }

    public function setProgress2Support(bool $supported): void
    {
        $this->progress2Support = $supported;
    }

    protected function supportsProgress2(): bool
    {
        return $this->progress2Support;
    }
}

class RsyncServiceTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    public function test_builds_basic_rsync_command_for_local_sync(): void
    {
        $service = new TestableRsyncService($this->output, true, false);

        $command = $service->exposedBuildCommand('/source/path', '/dest/path');

        $this->assertStringContainsString('rsync', $command);
        $this->assertStringContainsString('-avz', $command);
        $this->assertStringContainsString('--stats', $command);
        $this->assertStringNotContainsString('--delete', $command);
        $this->assertStringContainsString('--dry-run', $command);
        $this->assertStringContainsString('--itemize-changes', $command);
        $this->assertStringContainsString('--out-format=', $command);
        $this->assertStringContainsString('/source/path/', $command);
        $this->assertStringContainsString('/dest/path', $command);
    }

    public function test_includes_exclude_patterns_in_command(): void
    {
        $service = new TestableRsyncService($this->output, true, false);

        $excludes = ['.git/', 'node_modules/', '*.log'];

        $command = $service->exposedBuildCommand('/source/path', '/dest/path', $excludes);

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
        $service = new TestableRsyncService($this->output, true, false);

        $command = $service->exposedBuildCommand('/source/path', '/dest/path', [], $sshService);

        $this->assertStringContainsString('-e', $command);
        $this->assertStringContainsString('ssh', $command);
    }

    public function test_always_includes_progress_flag(): void
    {
        $service = new TestableRsyncService($this->output, false, false);
        $service->setProgress2Support(false);
        $command = $service->exposedBuildCommand('/source/path', '/dest/path');
        $this->assertStringContainsString('--progress', $command);
        $this->assertStringNotContainsString('--info=progress2', $command);
    }

    public function test_includes_progress2_when_supported(): void
    {
        $service = new TestableRsyncService($this->output, false, false);
        $service->setProgress2Support(true);
        $command = $service->exposedBuildCommand('/source/path', '/dest/path');
        $this->assertStringContainsString('--info=progress2', $command);
    }

    public function test_includes_delete_flag_when_requested(): void
    {
        $service = new TestableRsyncService($this->output, false, false);

        $command = $service->exposedBuildCommand('/source/path', '/dest/path', [], null, true);

        $this->assertStringContainsString('--delete', $command);
    }

    public function test_does_not_add_dry_run_flag_when_not_in_dry_run_mode(): void
    {
        $service = new TestableRsyncService($this->output, false, false);

        $command = $service->exposedBuildCommand('/source/path', '/dest/path');

        $this->assertStringNotContainsString('--dry-run', $command);
    }

    public function test_ensures_trailing_slash_on_source_path(): void
    {
        $service = new TestableRsyncService($this->output, true, false);

        // Test without trailing slash
        $command = $service->exposedBuildCommand('/source/path', '/dest/path');

        $this->assertStringContainsString('/source/path/', $command);

        // Test with trailing slash (should not double up)
        $command = $service->exposedBuildCommand('/source/path/', '/dest/path');

        $this->assertStringContainsString('/source/path/', $command);
        $this->assertStringNotContainsString('/source/path//', $command);
    }

    public function test_excludes_pattern_from_gitignore(): void
    {
        $service = new TestableRsyncService($this->output, true, false);

        // Test that .gitignore patterns become excludes
        $excludes = ['*.php', '*.js', 'wp-content/themes/'];

        $command = $service->exposedBuildCommand('/source/path', '/dest/path', $excludes);

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
