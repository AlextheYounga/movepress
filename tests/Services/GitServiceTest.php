<?php

declare(strict_types=1);

namespace Tests\Services;

use Movepress\Services\GitService;
use Movepress\Services\SshService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class GitServiceTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    public function testIsAvailableReturnsTrueWhenGitIsInstalled(): void
    {
        $this->assertTrue(GitService::isAvailable());
    }

    public function testIsGitRepoReturnsTrueForGitDirectory(): void
    {
        $service = new GitService($this->output);
        $gitRoot = __DIR__ . '/../..';

        $this->assertTrue($service->isGitRepo($gitRoot));
    }

    public function testIsGitRepoReturnsFalseForNonGitDirectory(): void
    {
        $service = new GitService($this->output);
        $nonGitDir = sys_get_temp_dir();

        $this->assertFalse($service->isGitRepo($nonGitDir));
    }

    public function testBuildRemoteUrlWithSshService(): void
    {
        $service = new GitService($this->output);

        $sshService = new SshService([
            'host' => 'example.com',
            'user' => 'deploy',
            'port' => 22,
        ]);

        $url = $service->buildRemoteUrl('/var/repos/mysite.git', $sshService);

        $this->assertEquals('deploy@example.com:/var/repos/mysite.git', $url);
    }

    public function testBuildRemoteUrlWithCustomPort(): void
    {
        $service = new GitService($this->output);

        $sshService = new SshService([
            'host' => 'example.com',
            'user' => 'deploy',
            'port' => 2222,
        ]);

        $url = $service->buildRemoteUrl('/var/repos/mysite.git', $sshService);

        // Git URLs don't include port in the connection string for SSH
        // Port is handled via SSH config
        $this->assertEquals('deploy@example.com:/var/repos/mysite.git', $url);
    }
}
