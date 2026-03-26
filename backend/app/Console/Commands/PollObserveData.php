<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class PollObserveData extends Command
{
    protected $signature = 'observe:poll {--workspace_id=}';
    protected $description = 'Legacy alias for native monitoring checks';

    public function handle(): int
    {
        $workspaceId = $this->option('workspace_id');
        $params = [];
        if ($workspaceId !== null && $workspaceId !== '') {
            $params['--workspace_id'] = $workspaceId;
        }

        $this->warn('observe:poll is deprecated. Running native monitoring checks (observe:run-checks)...');
        $code = Artisan::call('observe:run-checks', $params);
        $this->output->write(Artisan::output());

        return $code;
    }
}
