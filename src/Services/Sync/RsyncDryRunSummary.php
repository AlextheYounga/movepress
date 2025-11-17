<?php

declare(strict_types=1);

namespace Movepress\Services\Sync;

final class RsyncDryRunSummary
{
    public function __construct(private readonly int $files, private readonly int $bytes) {}

    public function getFiles(): int
    {
        return $this->files;
    }

    public function getBytes(): int
    {
        return $this->bytes;
    }
}
