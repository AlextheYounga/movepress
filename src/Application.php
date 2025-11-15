<?php

declare(strict_types=1);

namespace Movepress;

use Symfony\Component\Console\Application as ConsoleApplication;

class Application extends ConsoleApplication
{
    private const VERSION = '0.1.0';
    private const NAME = 'Movepress';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->registerCommands();
    }

    private function registerCommands(): void
    {
        // Commands will be registered here
        // $this->add(new Commands\PushCommand());
        // $this->add(new Commands\PullCommand());
        // $this->add(new Commands\InitCommand());
    }
}
