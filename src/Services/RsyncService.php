<?php

declare(strict_types=1);

namespace Movepress\Services;

use Movepress\Console\CommandFormatter;
use Movepress\Console\MovepressStyle;
use Movepress\Services\Sync\RsyncStats;
use Movepress\Services\Sync\RsyncStatsParser;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use xobotyi\rsync\Rsync;

class RsyncService
{
    private OutputInterface $output;
    private bool $dryRun;
    private bool $verbose;
    private ?RsyncStats $lastStats = null;
    private ?array $lastDryRunSummary = null;
    private RsyncStatsParser $parser;

    public function __construct(OutputInterface $output, bool $dryRun = false, bool $verbose = false)
    {
        $this->output = $output;
        $this->dryRun = $dryRun;
        $this->verbose = $verbose;
        MovepressStyle::registerCustomStyles($this->output);
        $this->parser = new RsyncStatsParser();
    }

    public function syncFiles(
        string $sourcePath,
        string $destPath,
        array $excludes = [],
        ?SshService $sshService = null,
        bool $delete = false,
    ): bool {
        $finalExcludes = array_values(array_unique(array_merge($excludes, ['.git', '.git/'])));
        return $this->sync($sourcePath, $destPath, $finalExcludes, $sshService, $delete);
    }

    /**
     * Sync files from source to destination
     *
     * @param string $sourcePath Source path (local or remote)
     * @param string $destPath Destination path (local or remote)
     * @param array $excludes Array of exclude patterns
     * @param SshService|null $sshService SSH service for remote connections
     */
    private function sync(
        string $sourcePath,
        string $destPath,
        array $excludes = [],
        ?SshService $sshService = null,
        bool $delete = false,
    ): bool {
        $this->lastStats = null;
        $this->lastDryRunSummary = null;

        // Build rsync command
        $command = $this->buildRsyncCommand($sourcePath, $destPath, $excludes, $sshService, $delete);

        if ($this->verbose || $this->dryRun) {
            $this->output->writeln(sprintf('<cmd>› %s</cmd>', CommandFormatter::forDisplay($command)));
        }

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600); // 1 hour timeout for large syncs

        $capturedOutput = '';
        $process->run(function ($type, $buffer) use (&$capturedOutput) {
            $capturedOutput .= $buffer;
            if ($this->verbose || $type === Process::OUT) {
                $this->output->write($buffer, false, OutputInterface::OUTPUT_RAW);
            }
        });

        if (!$process->isSuccessful()) {
            $this->output->writeln('<error>Rsync failed:</error>');
            $this->output->writeln($process->getErrorOutput());
            return false;
        }

        $fullOutput = $capturedOutput !== '' ? $capturedOutput : $process->getOutput() . $process->getErrorOutput();
        $this->lastStats = $this->parser->parse($fullOutput);
        $this->lastDryRunSummary = $this->dryRun ? $this->parser->parseDryRunSummary($fullOutput) : null;

        return true;
    }

    protected function buildRsyncCommand(
        string $source,
        string $dest,
        array $excludes,
        ?SshService $sshService,
        bool $delete = false,
    ): string {
        $rsync = new Rsync();
        $rsync
            ->setOption(Rsync::OPT_ARCHIVE)
            ->setOption(Rsync::OPT_VERBOSE)
            ->setOption(Rsync::OPT_COMPRESS)
            ->setOption(Rsync::OPT_STATS)
            ->setOption(Rsync::OPT_PROGRESS)
            ->setOption(Rsync::OPT_OMIT_DIR_TIMES);

        if ($delete) {
            $rsync->setOption(Rsync::OPT_DELETE);
        }

        if ($this->dryRun) {
            $rsync
                ->setOption(Rsync::OPT_DRY_RUN)
                ->setOption(Rsync::OPT_ITEMIZE_CHANGES)
                ->setOption(Rsync::OPT_OUT_FORMAT, 'INFO:%i:%l:%n%L');
        }

        if (!empty($excludes)) {
            $rsync->setOption(Rsync::OPT_EXCLUDE, $excludes);
        }

        if ($sshService) {
            $sshCommand = trim('ssh ' . implode(' ', $sshService->getSshOptions()));
            $rsync->setSSH(
                new class ($sshCommand) extends \xobotyi\rsync\SSH {
                    public function __construct(private readonly string $command) {}

                    public function __toString(): string
                    {
                        return $this->command;
                    }
                },
            );
        }

        // Ensure trailing slash on source for proper sync behavior
        $source = rtrim($source, '/') . '/';

        $rsync->setParameters([escapeshellarg($source), escapeshellarg($dest)]);

        return (string) $rsync;
    }

    public function getLastStats(): ?RsyncStats
    {
        return $this->lastStats;
    }

    public function getLastDryRunSummary(): ?array
    {
        return $this->lastDryRunSummary;
    }

    /**
     * Check if rsync is available
     */
    public static function isAvailable(): bool
    {
        $process = Process::fromShellCommandline('which rsync');
        $process->run();

        return $process->isSuccessful();
    }
}
