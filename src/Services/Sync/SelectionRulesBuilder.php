<?php

declare(strict_types=1);

namespace Movepress\Services\Sync;

class SelectionRulesBuilder
{
    /**
     * @param array<array{path: string, type: string}> $selectedPaths
     * @return array{restrict: bool, includes: array<int, string>}
     */
    public function build(array $selectedPaths, bool $selectAll): array
    {
        if ($selectAll || empty($selectedPaths)) {
            return [
                'restrict' => false,
                'includes' => [],
            ];
        }

        $includes = [];

        foreach ($selectedPaths as $item) {
            $path = trim($item['path'], '/');
            if ($path === '') {
                continue;
            }

            if ($item['type'] === 'file') {
                $this->addFileIncludes($includes, $path);
                continue;
            }

            $this->addDirectoryIncludes($includes, $path);
        }

        return [
            'restrict' => true,
            'includes' => array_values(array_unique($includes)),
        ];
    }

    private function addDirectoryIncludes(array &$includes, string $path): void
    {
        $parts = explode('/', $path);
        $current = '';

        foreach ($parts as $part) {
            $current = $current === '' ? $part : $current . '/' . $part;
            $includes[] = rtrim($current, '/') . '/';
        }

        $includes[] = rtrim($current, '/') . '/***';
    }

    private function addFileIncludes(array &$includes, string $path): void
    {
        $parts = explode('/', $path);
        if (count($parts) > 1) {
            array_pop($parts);
            $current = '';
            foreach ($parts as $part) {
                $current = $current === '' ? $part : $current . '/' . $part;
                $includes[] = rtrim($current, '/') . '/';
            }
        }

        $includes[] = $path;
    }
}
