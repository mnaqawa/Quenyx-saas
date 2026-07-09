<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Proxy to PHPUnit — this project does not rely on Laravel's optional TestCommand registration.
 */
class RunTestsCommand extends Command
{
    protected $signature = 'test
                            {--filter= : Filter which tests to run}
                            {--testsuite= : Run a specific test suite (Unit|Feature)}';

    protected $description = 'Run the application test suite (PHPUnit)';

    public function handle(): int
    {
        $phpunit = base_path('vendor/bin/phpunit');

        if (! is_file($phpunit)) {
            $this->error('PHPUnit not found. Install dev dependencies first:');
            $this->line('  composer install');
            $this->line('Or run directly after install: vendor/bin/phpunit');

            return self::FAILURE;
        }

        $args = [PHP_BINARY, $phpunit, '--configuration', base_path('phpunit.xml')];

        if ($filter = $this->option('filter')) {
            $args[] = '--filter='.$filter;
        }

        if ($suite = $this->option('testsuite')) {
            $args[] = '--testsuite='.$suite;
        }

        $process = new Process($args, base_path());
        $process->setTimeout(null);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $process->getExitCode() ?? self::FAILURE;
    }
}
