<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallObservePlugins extends Command
{
    protected $signature = 'observe:install-plugins {--force : Overwrite existing plugin files}';
    protected $description = 'Copy example observe plugins (no hardcoded IPs) from docs to storage so checks use UI host and config';

    public function handle(): int
    {
        $sourceDir = base_path('docs/observe_plugins_example');
        if (!is_dir($sourceDir)) {
            $sourceDir = __DIR__ . '/../../docs/observe_plugins_example';
        }
        if (!is_dir($sourceDir)) {
            $this->error('Example plugins directory not found. Expected: docs/observe_plugins_example under the backend app.');
            return 1;
        }

        $pluginsDir = config('observe.plugins_dir', 'app/observe_plugins');
        $pluginsDir = str_starts_with($pluginsDir, '/') ? $pluginsDir : storage_path($pluginsDir);
        if (!is_dir($pluginsDir)) {
            if (!File::makeDirectory($pluginsDir, 0755, true)) {
                $this->error('Could not create plugins directory: ' . $pluginsDir);
                return 1;
            }
        }

        $force = $this->option('force');
        $files = File::files($sourceDir);
        $copied = 0;
        foreach ($files as $file) {
            $name = $file->getFilename();
            $dest = $pluginsDir . DIRECTORY_SEPARATOR . $name;
            if (file_exists($dest) && !$force) {
                $this->line("Skip (exists): {$name}");
                continue;
            }
            if (File::copy($file->getPathname(), $dest)) {
                $this->info("Installed: {$name}");
                $copied++;
            } else {
                $this->warn("Failed to copy: {$name}");
            }
        }

        $this->info("Plugins directory: {$pluginsDir}");
        $this->info("Copied {$copied} plugin(s). All plugins use OBSERVE_HOST_ADDRESS and OBSERVE_CHECK_ARGS from the UI (no hardcoded IP or thresholds).");
        return 0;
    }
}
