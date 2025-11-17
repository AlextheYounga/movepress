<?php

declare(strict_types=1);

namespace Tests\Commands;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Movepress\Commands\GitSetupCommand;

class GitSetupCommandTest extends TestCase
{
    private Application $application;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->application = new Application();
        $this->application->add(new GitSetupCommand());

        $command = $this->application->find('git-setup');
        $this->commandTester = new CommandTester($command);
    }

    public function testCommandRequiresEnvironmentArgument(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments');

        $this->commandTester->execute([]);
    }

    public function testCommandFailsWhenConfigFileNotFound(): void
    {
        // Execute in a temp directory without movefile.yml
        $tempDir = sys_get_temp_dir() . '/movepress_test_' . uniqid();
        mkdir($tempDir);
        chdir($tempDir);

        $exitCode = $this->commandTester->execute(
            [
                'environment' => 'production',
            ],
            ['interactive' => false],
        );

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Configuration file not found', $this->commandTester->getDisplay());

        // Cleanup
        chdir(__DIR__ . '/../..');
        rmdir($tempDir);
    }

    public function testCommandValidatesEnvironmentName(): void
    {
        // Create a minimal config file
        $tempDir = sys_get_temp_dir() . '/movepress_test_' . uniqid();
        mkdir($tempDir);
        chdir($tempDir);

        file_put_contents('movefile.yml', "local:\n  wordpress_path: /tmp\n  url: http://test.local\n");

        $exitCode = $this->commandTester->execute(
            [
                'environment' => 'nonexistent',
            ],
            ['interactive' => false],
        );

        $this->assertEquals(1, $exitCode);

        // Cleanup
        unlink('movefile.yml');
        chdir(__DIR__ . '/../..');
        rmdir($tempDir);
    }

    public function testCommandFailsForLocalEnvironmentWithoutSSH(): void
    {
        $tempDir = sys_get_temp_dir() . '/movepress_test_' . uniqid();
        mkdir($tempDir);
        chdir($tempDir);

        file_put_contents(
            'movefile.yml',
            <<<YAML
            local:
              wordpress_path: /tmp/wordpress
              url: http://test.local
              database:
                name: test_db
                user: root
                password: pass
            YAML
            ,
        );

        $exitCode = $this->commandTester->execute(
            [
                'environment' => 'local',
            ],
            ['interactive' => false],
        );

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('missing \'ssh\' configuration', $this->commandTester->getDisplay());

        // Cleanup
        unlink('movefile.yml');
        chdir(__DIR__ . '/../..');
        rmdir($tempDir);
    }
}
