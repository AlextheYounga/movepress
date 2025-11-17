<?php

declare(strict_types=1);

namespace Movepress\Services\Sync;

use Movepress\Services\RsyncService;
use Movepress\Services\SshService;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class FileSyncController
{
    private RsyncStatsFormatter $formatter;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly SymfonyStyle $io,
        private readonly bool $dryRun,
        private readonly bool $verbose,
    ) {
        $this->formatter = new RsyncStatsFormatter();
    }

    public function sync(
        string $sourcePath,
        string $destPath,
        array $excludes,
        ?SshService $remoteSsh,
        ?string $gitignorePath,
        bool $delete,
    ): bool {
        $rsync = new RsyncService($this->output, $this->dryRun, $this->verbose);

        $this->io->text('Syncing untracked files (uploads, caches, etc.)...');
        if ($delete) {
            $this->io->warning([
                'You have enabled --delete. Files missing from the source will be removed from the destination.',
                'Ensure you have backups before continuing.',
            ]);
        }

        $success = $rsync->syncUntrackedFiles($sourcePath, $destPath, $excludes, $remoteSsh, $gitignorePath, $delete);

        if ($success && ($stats = $rsync->getLastStats()) !== null) {
            $lines = $this->formatter->formatNoteLines($stats, $rsync->getLastDryRunSummary(), $this->dryRun);
            if (!empty($lines)) {
                $this->io->note($lines);
            }
        }

        return $success;
    }
}
