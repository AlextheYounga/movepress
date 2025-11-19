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

    /**
     * @dataProvider provideSerializedFixtures
     *
     * @param array<int, array{from:string,to:string}> $replacements
     */
    public function testProcessLineMatchesGoImplementation(
        string $inputFixture,
        string $expectedFixture,
        array $replacements,
    ): void {
        $input = file_get_contents($inputFixture);
        $expected = file_get_contents($expectedFixture);

        self::assertNotFalse($input);
        self::assertNotFalse($expected);

        self::assertSame($expected, $this->service->processLine($input, $replacements));
    }

    /**
     * @return array<string, array{string,string,array<int, array{from:string,to:string}>}>
     */
    public static function provideSerializedFixtures(): array
    {
        $base = __DIR__ . '/../Fixtures/serialized';
        $doubleEncodedFrom = <<<'TXT'
        http:\\/\\/example\\.com
        TXT;
        $doubleEncodedTo = <<<'TXT'
        http:\\/\\/example2\\.com
        TXT;
        $doubleEncodedSerializedFrom = <<<'TXT'
        \\s=\\shttp_get(\'http:\\/\\/example\\.com
        TXT;
        $doubleEncodedSerializedTo = <<<'TXT'
        \\s=\\shttp_get(\'http:\\/\\/example2\\.com
        TXT;
        $heavyEscapingFrom = <<<'TXT'
        \\c\\d\\e
        TXT;
        $heavyEscapingTo = <<<'TXT'
        \\x
        TXT;

        return [
            'http to https' => [
                $base . '/http-to-https.input.sql',
                $base . '/http-to-https.expected.sql',
                [['from' => 'http://automattic.com', 'to' => 'https://automattic.com']],
            ],
            'multiple occurrences on line' => [
                $base . '/multiple-occurrences.input.sql',
                $base . '/multiple-occurrences.expected.sql',
                [['from' => 'http://automattic.com', 'to' => 'https://automattic.com']],
            ],
            'skip already replaced value' => [
                $base . '/skip-updated.input.sql',
                $base . '/skip-updated.expected.sql',
                [['from' => 'http://automattic.com', 'to' => 'https://automattic.com']],
            ],
            'emoji from' => [
                $base . '/emoji-from.input.sql',
                $base . '/emoji-from.expected.sql',
                [['from' => 'http://🖖.com', 'to' => 'https://spock.com']],
            ],
            'emoji to' => [
                $base . '/emoji-to.input.sql',
                $base . '/emoji-to.expected.sql',
                [['from' => 'https://spock.com', 'to' => 'http://🖖.com']],
            ],
            'null characters' => [
                $base . '/null-bytes.input.sql',
                $base . '/null-bytes.expected.sql',
                [['from' => 'EnvironmentObject', 'to' => 'Yeehaw']],
            ],
            'different lengths' => [
                $base . '/different-lengths.input.sql',
                $base . '/different-lengths.expected.sql',
                [['from' => 'hello', 'to' => 'goodbye']],
            ],
            'longer replacements' => [
                $base . '/long-different-lengths.input.sql',
                $base . '/long-different-lengths.expected.sql',
                [['from' => 'bbbbbbbbbb', 'to' => 'ccccccccccccccc']],
            ],
            'serialized css' => [
                $base . '/serialized-css.input.sql',
                $base . '/serialized-css.expected.sql',
                [['from' => 'https://uss-enterprise.com', 'to' => 'https://ncc-1701-d.space']],
            ],
            'double encoded string' => [
                $base . '/double-encoded.input.sql',
                $base . '/double-encoded.expected.sql',
                [['from' => $doubleEncodedFrom, 'to' => $doubleEncodedTo]],
            ],
            'non serialized section with serialized data' => [
                $base . '/non-serialized-mixed.input.sql',
                $base . '/non-serialized-mixed.expected.sql',
                [['from' => 'example', 'to' => 'example2'], ['from' => $doubleEncodedFrom, 'to' => $doubleEncodedTo]],
            ],
            'heavy escaping' => [
                $base . '/heavy-escaping.input.sql',
                $base . '/heavy-escaping.expected.sql',
                [['from' => $heavyEscapingFrom, 'to' => $heavyEscapingTo]],
            ],
            'escaped delimiters' => [
                $base . '/escaped-delimiters.input.sql',
                $base . '/escaped-delimiters.expected.sql',
                [['from' => 'hello', 'to' => 'helloworld']],
            ],
            'mydumper delimiters' => [
                $base . '/mydumper-delimiters.input.sql',
                $base . '/mydumper-delimiters.expected.sql',
                [['from' => 'hello', 'to' => 'helloworld']],
            ],
            'overlapping replacements without serialization' => [
                $base . '/overlapping-non-serialized.input.sql',
                $base . '/overlapping-non-serialized.expected.sql',
                [['from' => 'http:', 'to' => 'https:'], ['from' => '//automattic.com', 'to' => '//automattic.org']],
            ],
        ];
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
