<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ListUsers extends Command
{
    protected $signature = 'user:list {--limit=50 : Max rows}';

    protected $description = 'List users (id + email) — use to verify which database you are connected to.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $users = User::query()->orderBy('id')->limit($limit)->get(['id', 'email', 'name']);

        if ($users->isEmpty()) {
            $this->warn('No users in this database. Run: php artisan user:ensure-admin --force');
            return 0;
        }

        $this->table(['id', 'email', 'name'], $users->map(fn ($u) => [$u->id, $u->email, $u->name])->all());
        $total = User::query()->count();
        if ($total > $limit) {
            $this->line("(Showing {$limit} of {$total} users.)");
        }

        return 0;
    }
}
