<?php

declare(strict_types=1);

namespace Movepress\Services\Sync;

final class RsyncStats
{
    public function __construct(
        private readonly ?int $filesTotal,
        private readonly ?int $filesTransferred,
        private readonly ?int $bytesTotal,
        private readonly ?int $bytesTransferred,
    ) {}

    public function getFilesTotal(): ?int
    {
        return $this->filesTotal;
    }

    public function getFilesTransferred(): ?int
    {
        return $this->filesTransferred;
    }

    public function getBytesTotal(): ?int
    {
        return $this->bytesTotal;
    }

    public function getBytesTransferred(): ?int
    {
        return $this->bytesTransferred;
    }

    public function hasAnyValues(): bool
    {
        return $this->filesTotal !== null ||
            $this->filesTransferred !== null ||
            $this->bytesTotal !== null ||
            $this->bytesTransferred !== null;
    }
}
