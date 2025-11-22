<?php

declare(strict_types=1);

namespace Movepress\Tests\Services;

use Movepress\Services\ValidationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class ValidationServiceTest extends TestCase
{
    public function test_warns_when_database_backup_skipped(): void
    {
        $style = $this->createStyle();
        $service = new ValidationService($style);

        $service->confirmDestructiveOperation(
            'production',
            [
                'db' => true,
                'no_backup' => true,
                'delete' => false,
            ],
            true,
        );

        $this->assertStringContainsString('REPLACE the database in: production', $style->fetchOutput());
    }

    public function test_warns_when_delete_enabled(): void
    {
        $style = $this->createStyle();
        $service = new ValidationService($style);

        $service->confirmDestructiveOperation(
            'production',
            [
                'db' => false,
                'no_backup' => false,
                'delete' => true,
            ],
            true,
        );

        $this->assertStringContainsString('DELETE files on the destination', $style->fetchOutput());
    }

    public function test_no_warning_when_no_destructive_flags(): void
    {
        $style = $this->createStyle();
        $service = new ValidationService($style);

        $service->confirmDestructiveOperation(
            'production',
            [
                'db' => false,
                'no_backup' => false,
                'delete' => false,
            ],
            true,
        );

        $this->assertSame('', $style->fetchOutput());
    }

    public function test_rejects_remote_to_remote_file_sync(): void
    {
        $style = $this->createStyle();
        $service = new ValidationService($style);

        $source = [
            'wordpress_path' => '/var/www/source',
            'url' => 'https://source.test',
            'database' => ['name' => 'src'],
            'ssh' => ['host' => 'source.test'],
        ];

        $dest = [
            'wordpress_path' => '/var/www/dest',
            'url' => 'https://dest.test',
            'database' => ['name' => 'dest'],
            'ssh' => ['host' => 'dest.test'],
        ];

        $result = $service->validatePrerequisites(
            $source,
            $dest,
            [
                'db' => false,
                'files' => true,
                'delete' => false,
                'no_backup' => false,
            ],
            true,
        );

        $this->assertFalse($result);
        $this->assertStringContainsString('Remote-to-remote file syncs are not supported', $style->fetchOutput());
    }

    private function createStyle(): TestSymfonyStyle
    {
        return new TestSymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }
}

final class TestSymfonyStyle extends SymfonyStyle
{
    public function __construct(ArrayInput $input, private readonly BufferedOutput $bufferedOutput)
    {
        parent::__construct($input, $this->bufferedOutput);
    }

    public function fetchOutput(): string
    {
        return $this->bufferedOutput->fetch();
    }
}
