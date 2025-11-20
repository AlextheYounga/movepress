<?php

declare(strict_types=1);

namespace Movepress\Console;

use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Artisan-inspired console style for Movepress.
 * Replaces heavy blocks with lightweight, icon-led lines and dim command echoes.
 */
final class MovepressStyle extends SymfonyStyle
{
    private const COMMAND_STYLE = 'cmd';
    private const MUTED_STYLE = 'muted';

    public function __construct(InputInterface $input, OutputInterface $output)
    {
        self::registerCustomStyles($output);

        parent::__construct($input, $output);
    }

    public static function registerCustomStyles(OutputInterface $output): void
    {
        $formatter = $output->getFormatter();

        if (!$formatter->hasStyle(self::COMMAND_STYLE)) {
            $formatter->setStyle(self::COMMAND_STYLE, new OutputFormatterStyle('white', null, ['dim']));
        }

        if (!$formatter->hasStyle(self::MUTED_STYLE)) {
            $formatter->setStyle(self::MUTED_STYLE, new OutputFormatterStyle('white', null, ['dim']));
        }
    }

    public function title(string $message): void
    {
        $this->newLine();
        $this->writeln(sprintf('<fg=cyan;options=bold>%s</>', $message));
        $this->newLine();
    }

    public function section(string $message): void
    {
        $this->newLine();
        $this->writeln(sprintf('<fg=cyan>›</> %s', $message));
    }

    public function success(string|array $message): void
    {
        $this->writeBlock($message, '<fg=green>✔</>');
    }

    public function warning(string|array $message): void
    {
        $this->writeBlock($message, '<fg=yellow>!</>');
    }

    public function note(string|array $message): void
    {
        $this->writeBlock($message, '<fg=cyan>•</>');
    }

    public function error(string|array $message): void
    {
        $this->writeBlock($message, '<fg=red>✘</>');
    }

    private function writeBlock(string|array $messages, string $prefix): void
    {
        $lines = is_array($messages) ? $messages : [$messages];

        foreach ($lines as $line) {
            $this->writeln(sprintf(' %s %s', $prefix, $line));
        }
    }
}
