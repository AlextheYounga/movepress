<?php

declare(strict_types=1);

namespace Movepress\Tests\Services;

use Movepress\Services\SqlSearchReplaceService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SqlSearchReplaceServiceTest extends TestCase
{
    private SqlSearchReplaceService $service;

    protected function setUp(): void
    {
        $this->service = new SqlSearchReplaceService();
    }

    public function testProcessLineWithEmptyReplacementsReturnsOriginal(): void
    {
        $line = 'plain text line';
        self::assertSame($line, $this->service->processLine($line, []));
    }

    public function testProcessLineRejectsInvalidReplacements(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->processLine('anything', [['from' => 'only-from']]);
    }

    public function testReplaceInFileProcessesEntireContents(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'sql-src-');
        $output = tempnam(sys_get_temp_dir(), 'sql-out-');

        self::assertNotFalse($input);
        self::assertNotFalse($output);

        $fixture = <<<'SQL'
        s:21:\"http://automattic.com\";
        http://example.com

        SQL;

        file_put_contents($input, $fixture);

        $this->service->replaceInFile($input, $output, [
            ['from' => 'http://automattic.com', 'to' => 'https://automattic.com'],
            ['from' => 'http://example.com', 'to' => 'https://example.com'],
        ]);

        $result = file_get_contents($output);

        $expected = <<<'SQL'
        s:22:\"https://automattic.com\";
        https://example.com

        SQL;

        self::assertSame($expected, $result);

        @unlink($input);
        @unlink($output);
    }

    public function testFixturesMatchGoBinaryOutput(): void
    {
        $input = $this->extractSqlFixture(__DIR__ . '/../Fixtures/wpbreakstufflocalhost.sql.zip');
        $expected = $this->extractSqlFixture(__DIR__ . '/../Fixtures/wpbreakstufflol.sql.zip');

        $output = tempnam(sys_get_temp_dir(), 'sql-fixture-');
        self::assertNotFalse($output);

        try {
            $this->service->replaceInFile($input, $output, [
                ['from' => 'wp.breakstuff.localhost', 'to' => 'wp.breakstuff.lol'],
            ]);

            self::assertFileEquals($expected, $output);
        } finally {
            @unlink($input);
            @unlink($expected);
            @unlink($output);
        }
    }

    private function extractSqlFixture(string $zipPath): string
    {
        if (!is_file($zipPath)) {
            throw new RuntimeException(sprintf('Fixture "%s" does not exist.', $zipPath));
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException(sprintf('Unable to open fixture "%s".', $zipPath));
        }

        $contents = $zip->getFromIndex(0);
        $zip->close();

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read contents of fixture "%s".', $zipPath));
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'sql-fixture-');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to create temporary fixture file.');
        }

        file_put_contents($tempPath, $contents);

        return $tempPath;
    }
}
