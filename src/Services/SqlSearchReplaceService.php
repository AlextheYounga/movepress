<?php

declare(strict_types=1);

namespace Movepress\Services;

use RuntimeException;

/**
 * Port of Automattic's go-search-replace serialized string fixer.
 * Works directly on SQL dump text without requiring a live database.
 */
class SqlSearchReplaceService
{
    /**
     * Run replacements against a SQL file and write the result to another file.
     *
     * @param array<int, array{from:string,to:string}> $replacements
     */
    public function replaceInFile(string $inputPath, string $outputPath, array $replacements): void
    {
        $normalized = $this->normalizeReplacements($replacements);
        if ($normalized === []) {
            if ($inputPath === $outputPath) {
                return;
            }
            if (!copy($inputPath, $outputPath)) {
                throw new RuntimeException(sprintf('Unable to copy "%s" to "%s".', $inputPath, $outputPath));
            }

            return;
        }

        $input = @fopen($inputPath, 'rb');
        if ($input === false) {
            throw new RuntimeException(sprintf('Unable to open "%s" for reading.', $inputPath));
        }

        $output = @fopen($outputPath, 'wb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException(sprintf('Unable to open "%s" for writing.', $outputPath));
        }

        try {
            while (($line = fgets($input)) !== false) {
                fwrite($output, $this->processLine($line, $normalized));
            }

            $remainder = stream_get_contents($input);
            if ($remainder !== false && $remainder !== '') {
                fwrite($output, $this->processLine($remainder, $normalized));
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /**
     * Replace strings inside a line or chunk of SQL text.
     *
     * @param array<int, array{from:string,to:string}> $replacements
     */
    public function processLine(string $line, array $replacements): string
    {
        if ($line === '') {
            return '';
        }

        $normalized = $this->normalizeReplacements($replacements);
        if ($normalized === []) {
            return $line;
        }

        return $this->fixLine($line, $normalized);
    }

    /**
     * @param array<int, array{from:string,to:string}> $replacements
     * @return array<int, array{from:string,to:string}>
     */
    private function normalizeReplacements(array $replacements): array
    {
        $normalized = [];
        foreach ($replacements as $replacement) {
            if (!is_array($replacement) || !isset($replacement['from'], $replacement['to'])) {
                throw new RuntimeException('Replacements must be arrays with "from" and "to" keys.');
            }

            $from = (string) $replacement['from'];
            $to = (string) $replacement['to'];

            if ($from === '') {
                continue;
            }

            $normalized[] = [
                'from' => $from,
                'to' => $to,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, array{from:string,to:string}> $replacements
     */
    private function fixLine(string $line, array $replacements): string
    {
        $linePart = $line;
        $rebuilt = '';

        while ($linePart !== '') {
            try {
                $result = $this->fixLineWithSerializedData($linePart, $replacements);
            } catch (RuntimeException $exception) {
                $rebuilt .= $linePart;
                break;
            }

            $rebuilt .= $result->pre . $result->serializedPortion;
            $linePart = $result->post;

            if ($linePart === '') {
                break;
            }
        }

        return $rebuilt;
    }

    /**
     * @param array<int, array{from:string,to:string}> $replacements
     */
    private function fixLineWithSerializedData(string $linePart, array $replacements): SerializedReplaceResult
    {
        $prefix = $this->findSerializedPrefix($linePart);

        if ($prefix === null) {
            return new SerializedReplaceResult($this->replaceByPart($linePart, $replacements), '', '');
        }

        $pre = substr($linePart, 0, $prefix['start']);
        $pre = $this->replaceByPart($pre, $replacements);

        $originalByteSize = (int) $prefix['raw_length'];
        $contentStartIndex = $prefix['content_start'];

        $currentContentIndex = $contentStartIndex;
        $contentByteCount = 0;
        $contentEndIndex = 0;
        $nextSliceIndex = null;
        $nextSliceFound = false;
        $maxIndex = strlen($linePart) - 1;

        while ($currentContentIndex < strlen($linePart)) {
            if ($currentContentIndex + 2 > $maxIndex) {
                throw new RuntimeException('faulty serialized data: out-of-bound index access detected');
            }

            $char = $linePart[$currentContentIndex];
            $secondChar = $linePart[$currentContentIndex + 1];
            $thirdChar = $linePart[$currentContentIndex + 2];

            if ($char === '\\' && $contentByteCount < $originalByteSize) {
                $unescaped = $this->getUnescapedBytesIfEscaped(substr($linePart, $currentContentIndex, 2));
                $contentByteCount += strlen($unescaped);
                $currentContentIndex += 2;
                continue;
            }

            if ($char === '\\' && $secondChar === '"' && $thirdChar === ';' && $contentByteCount >= $originalByteSize) {
                $nextSliceIndex = $currentContentIndex + 3;
                $contentEndIndex = $currentContentIndex - 1;
                $nextSliceFound = true;
                break;
            }

            if ($contentByteCount > $originalByteSize) {
                throw new RuntimeException(
                    'faulty serialized data: calculated byte count does not match given data size',
                );
            }

            $contentByteCount++;
            $currentContentIndex++;
        }

        if (!$nextSliceFound || $nextSliceIndex === null) {
            throw new RuntimeException('faulty serialized data: end of serialized data not found');
        }

        $content = substr($linePart, $contentStartIndex, $contentEndIndex - $contentStartIndex + 1);
        $content = $this->replaceByPart($content, $replacements);
        $contentLength = strlen($this->unescapeContent($content));

        $escapedQuote = "\\\"";
        $rebuiltSerializedString = 's:' . $contentLength . ':' . $escapedQuote . $content . $escapedQuote . ';';

        return new SerializedReplaceResult($pre, $rebuiltSerializedString, substr($linePart, $nextSliceIndex));
    }

    /**
     * @param array<int, array{from:string,to:string}> $replacements
     */
    private function replaceByPart(string $part, array $replacements): string
    {
        foreach ($replacements as $replacement) {
            $part = str_replace($replacement['from'], $replacement['to'], $part);
        }

        return $part;
    }

    /**
     * Locate a serialized string prefix within a chunk of SQL.
     *
     * @return array{start:int, raw_length:string, content_start:int}|null
     */
    private function findSerializedPrefix(string $linePart): ?array
    {
        $length = strlen($linePart);

        for ($index = 0; $index < $length - 4; $index++) {
            if ($linePart[$index] !== 's' || $linePart[$index + 1] !== ':') {
                continue;
            }

            $digitStart = $index + 2;
            if ($digitStart >= $length || !ctype_digit($linePart[$digitStart])) {
                continue;
            }

            $digitEnd = $digitStart;
            while ($digitEnd < $length && ctype_digit($linePart[$digitEnd])) {
                $digitEnd++;
            }

            if ($digitEnd >= $length || $linePart[$digitEnd] !== ':') {
                continue;
            }

            if ($digitEnd + 2 >= $length) {
                break;
            }

            if ($linePart[$digitEnd + 1] !== '\\' || $linePart[$digitEnd + 2] !== '"') {
                continue;
            }

            $rawLength = substr($linePart, $digitStart, $digitEnd - $digitStart);

            return [
                'start' => $index,
                'raw_length' => $rawLength,
                'content_start' => $digitEnd + 3,
            ];
        }

        return null;
    }

    private function getUnescapedBytesIfEscaped(string $pair): string
    {
        if ($pair === '' || $pair[0] !== '\\') {
            return $pair;
        }

        $map = [
            '\\' => '\\',
            '\'' => '\'',
            '"' => '"',
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'b' => "\x08",
            'f' => "\f",
            '0' => '0',
        ];

        $second = $pair[1] ?? '';

        if ($second !== '' && isset($map[$second])) {
            return $map[$second];
        }

        return $pair;
    }

    private function unescapeContent(string $escaped): string
    {
        $unescaped = '';
        $length = strlen($escaped);
        $index = 0;

        while ($index < $length) {
            if ($escaped[$index] === '\\' && $index + 1 < $length) {
                $pair = substr($escaped, $index, 2);
                $converted = $this->getUnescapedBytesIfEscaped($pair);
                if (strlen($converted) === 1) {
                    $unescaped .= $converted;
                    $index += 2;
                    continue;
                }
            }

            $unescaped .= $escaped[$index];
            $index++;
        }

        return $unescaped;
    }
}

class SerializedReplaceResult
{
    public function __construct(public string $pre, public string $serializedPortion, public string $post) {}
}
