<?php

declare(strict_types=1);

namespace Movepress\Services\Sync;

use Movepress\Console\MovepressStyle;
use Movepress\Services\SshService;
use Symfony\Component\Process\Process;

class InteractivePathSelector
{
    public function __construct(
        private readonly MovepressStyle $io,
        private readonly array $excludePatterns,
        private readonly bool $noInteraction,
        private readonly ?SshService $sshService = null,
    ) {}

    /**
     * @return array{selectAll: bool, selection: array<int, array{path: string, type: string}>}
     */
    public function select(string $rootPath): array
    {
        if ($this->noInteraction) {
            return ['selectAll' => true, 'selection' => []];
        }

        if (!function_exists('posix_isatty') || !@posix_isatty(STDIN)) {
            $this->io->warning('Interactive selection requires a TTY. Defaulting to all paths.');
            return ['selectAll' => true, 'selection' => []];
        }

        $currentPath = '';
        $selection = [];
        $cursor = 0;
        $selectAll = false;

        $originalStty = @shell_exec('stty -g');
        @shell_exec('stty -icanon -echo min 1 time 0');

        try {
            while (true) {
                $entries = $this->listEntries($rootPath, $currentPath);
                $entries[] = ['name' => '[Finish]', 'type' => 'action'];
                $max = count($entries);
                if ($cursor < 0) {
                    $cursor = $max - 1;
                } elseif ($cursor >= $max) {
                    $cursor = 0;
                }

                $this->renderScreen($currentPath, $selection, $entries, $cursor);
                $key = $this->readKey();

                if ($key === 'UP') {
                    $cursor--;
                    continue;
                }
                if ($key === 'DOWN') {
                    $cursor++;
                    continue;
                }
                if ($key === 'RIGHT') {
                    $cursor = $this->enterIfDirectory($entries, $cursor, $currentPath);
                    continue;
                }
                if ($key === 'LEFT') {
                    $currentPath = $this->parentPath($currentPath);
                    $cursor = 0;
                    continue;
                }
                if ($key === 'SPACE') {
                    $this->toggleSelection($entries, $cursor, $currentPath, $selection);
                    continue;
                }
                if ($key === 'A') {
                    $selectAll = true;
                    break;
                }
                if ($key === 'ENTER') {
                    $entry = $entries[$cursor] ?? null;
                    if ($entry === null) {
                        continue;
                    }
                    if ($entry['type'] === 'dir') {
                        $cursor = $this->enterIfDirectory($entries, $cursor, $currentPath);
                        continue;
                    }
                    if ($entry['type'] === 'action') {
                        break;
                    }
                    $this->toggleSelection($entries, $cursor, $currentPath, $selection);
                    continue;
                }
            }
        } finally {
            if ($originalStty !== null) {
                @shell_exec('stty ' . $originalStty);
            }
            $this->io->newLine(2);
        }

        if ($selectAll) {
            return ['selectAll' => true, 'selection' => []];
        }

        if (empty($selection)) {
            return ['selectAll' => true, 'selection' => []];
        }

        return [
            'selectAll' => false,
            'selection' => array_values($selection),
        ];
    }

    /**
     * @return array<int, array{name: string, type: string}>
     */
    private function listEntries(string $rootPath, string $currentRelative): array
    {
        return $this->sshService !== null
            ? $this->listRemoteEntries($rootPath, $currentRelative)
            : $this->listLocalEntries($rootPath, $currentRelative);
    }

    /**
     * @return array<int, array{name: string, type: string}>
     */
    private function listLocalEntries(string $rootPath, string $currentRelative): array
    {
        $path = rtrim($rootPath, '/');
        if ($currentRelative !== '') {
            $path .= '/' . $currentRelative;
        }

        if (!is_dir($path)) {
            throw new \RuntimeException("Path not found: {$path}");
        }

        $items = @scandir($path);
        if ($items === false) {
            throw new \RuntimeException("Unable to read directory: {$path}");
        }

        $entries = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . '/' . $item;
            $relative = $this->joinRelative($currentRelative, $item);
            $relativeWithSlash = $relative . (str_ends_with($relative, '/') ? '' : '/');

            if ($this->isExcluded($item, $item . '/', $relative, $relativeWithSlash)) {
                continue;
            }

            $entries[] = [
                'name' => $item,
                'type' => is_dir($full) ? 'dir' : 'file',
            ];
        }

        return $this->sortEntries($entries);
    }

    /**
     * @return array<int, array{name: string, type: string}>
     */
    private function listRemoteEntries(string $rootPath, string $currentRelative): array
    {
        if ($this->sshService === null) {
            return [];
        }

        $script = <<<'PHP'
        $base = $argv[1] ?? '';
        $relative = $argv[2] ?? '';
        $dir = rtrim($base, "/");
        if ($relative !== '') {
            $dir .= '/' . $relative;
        }
        if (!is_dir($dir)) {
            exit(1);
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . '/' . $item;
            $type = is_dir($full) ? 'd' : 'f';
            echo $type . '|' . $item . "\n";
        }
        PHP;

        $remoteCommand = sprintf(
            'php -r "eval(base64_decode(\'%s\'));" -- %s %s',
            base64_encode($script),
            escapeshellarg($rootPath),
            escapeshellarg($currentRelative),
        );

        $parts = array_merge(['ssh'], array_map('escapeshellarg', $this->sshService->getSshOptions()), [
            $this->sshService->buildConnectionString(),
            escapeshellarg($remoteCommand),
        ]);

        $commandLine = implode(' ', $parts);
        $process = Process::fromShellCommandline($commandLine);
        $process->setTimeout(20);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Failed to read remote directory for selection.');
        }

        $output = trim($process->getOutput());
        if ($output === '') {
            return [];
        }

        $entries = [];
        foreach (explode("\n", $output) as $line) {
            [$type, $name] = array_pad(explode('|', trim($line), 2), 2, '');
            if ($name === '') {
                continue;
            }

            $relative = $this->joinRelative($currentRelative, $name);
            $relativeWithSlash = $relative . (str_ends_with($relative, '/') ? '' : '/');

            if ($this->isExcluded($name, $name . '/', $relative, $relativeWithSlash)) {
                continue;
            }

            $entries[] = [
                'name' => $name,
                'type' => $type === 'd' ? 'dir' : 'file',
            ];
        }

        return $this->sortEntries($entries);
    }

    /**
     * @param array<int, array{name: string, type: string}> $entries
     * @return array<int, array{name: string, type: string}>
     */
    private function sortEntries(array $entries): array
    {
        usort($entries, function ($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }

            return strcmp($a['name'], $b['name']);
        });

        return $entries;
    }

    private function joinRelative(string $current, string $child): string
    {
        return ltrim($current === '' ? $child : $current . '/' . $child, '/');
    }

    private function parentPath(string $current): string
    {
        if ($current === '' || !str_contains($current, '/')) {
            return '';
        }

        $parts = explode('/', $current);
        array_pop($parts);
        return implode('/', $parts);
    }

    private function renderScreen(string $currentPath, array $selection, array $entries, int $cursor): void
    {
        $displayPath = $currentPath === '' ? '/' : '/' . $currentPath;
        $selectedCount = count($selection);
        $instructions =
            '[UP/DOWN] move  [RIGHT/ENTER] open dir  [SPACE] toggle  [LEFT] up  [A] all  [ENTER on [Finish]] confirm';

        echo "\033[2J\033[H";
        echo "Select paths to sync\n";
        echo "Path: {$displayPath}\n";
        echo "Selected: {$selectedCount}\n";
        echo $instructions . "\n\n";

        foreach ($entries as $index => $entry) {
            $relative = $entry['type'] === 'action' ? '' : $this->joinRelative($currentPath, $entry['name']);
            $key = $entry['type'] . ':' . $relative;
            $checked = $entry['type'] === 'action' ? ' ' : (isset($selection[$key]) ? 'x' : ' ');
            $pointer = $cursor === $index ? '>' : ' ';
            $label = $entry['name'] . ($entry['type'] === 'dir' ? '/' : '');
            echo sprintf("%s [%s] %s\n", $pointer, $checked, $label);
        }
    }

    private function readKey(): string
    {
        $char = fread(STDIN, 1);
        if ($char === "\033") {
            $next1 = fread(STDIN, 1);
            $next2 = fread(STDIN, 1);
            $seq = $char . $next1 . $next2;
            return match ($seq) {
                "\033[A" => 'UP',
                "\033[B" => 'DOWN',
                "\033[C" => 'RIGHT',
                "\033[D" => 'LEFT',
                default => '',
            };
        }

        return match ($char) {
            "\n", "\r" => 'ENTER',
            ' ' => 'SPACE',
            'a', 'A' => 'A',
            default => '',
        };
    }

    /**
     * @param array<int, array{name: string, type: string}> $entries
     */
    private function enterIfDirectory(array $entries, int $cursor, string &$currentPath): int
    {
        $entry = $entries[$cursor] ?? null;
        if ($entry === null || $entry['type'] !== 'dir') {
            return $cursor;
        }

        $currentPath = $this->joinRelative($currentPath, $entry['name']);
        return 0;
    }

    /**
     * @param array<int, array{name: string, type: string}> $entries
     * @param array<string, array{path: string, type: string}> $selection
     */
    private function toggleSelection(array $entries, int $cursor, string $currentPath, array &$selection): void
    {
        $entry = $entries[$cursor] ?? null;
        if ($entry === null || $entry['type'] === 'action') {
            return;
        }

        $relative = $this->joinRelative($currentPath, $entry['name']);
        $key = $entry['type'] . ':' . $relative;

        if (isset($selection[$key])) {
            unset($selection[$key]);
            return;
        }

        $selection[$key] = [
            'path' => $relative,
            'type' => $entry['type'],
        ];
    }

    private function isExcluded(
        string $name,
        string $nameWithSlash,
        string $relativePath,
        string $relativePathWithSlash,
    ): bool {
        foreach ($this->excludePatterns as $pattern) {
            if ($pattern === '' || $pattern === null) {
                continue;
            }

            if ($pattern === $name || $pattern === $nameWithSlash) {
                return true;
            }

            if ($pattern === $relativePath || $pattern === $relativePathWithSlash) {
                return true;
            }

            if (str_ends_with($pattern, '/') && ($pattern === $nameWithSlash || $pattern === $relativePathWithSlash)) {
                return true;
            }

            if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
                if (
                    fnmatch($pattern, $name) ||
                    fnmatch($pattern, $nameWithSlash) ||
                    fnmatch($pattern, $relativePath) ||
                    fnmatch($pattern, $relativePathWithSlash)
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}
