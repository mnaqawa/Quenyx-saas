<?php

namespace App\Services;

use App\Models\ObserveServiceDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves canonical service_key from DB column, check_command, or service display name.
 */
class ObserveServiceKeyResolver
{
    /** @var array<string, string> check_command base => service_key */
    private const FALLBACK_BY_COMMAND = [
        'check_ping' => 'ping',
        'check_http' => 'http',
        'check_tcp' => 'tcp_port',
        'check_mysql' => 'mysql',
        'check_pgsql' => 'pgsql',
        'check_ssl_validity' => 'ssl_validity',
    ];

    public function resolve(string $serviceKey, string $checkCommand, string $serviceName = ''): string
    {
        $serviceKey = strtolower(trim($serviceKey));
        if ($serviceKey !== '') {
            return $serviceKey;
        }

        $baseCommand = $this->baseCheckCommand($checkCommand);
        if ($baseCommand !== '') {
            if (isset(self::FALLBACK_BY_COMMAND[$baseCommand])) {
                return self::FALLBACK_BY_COMMAND[$baseCommand];
            }
            if (Schema::hasTable('observe_service_definitions')) {
                $def = ObserveServiceDefinition::query()
                    ->whereRaw('LOWER(TRIM(check_command)) = ?', [$baseCommand])
                    ->first();
                if ($def && trim((string) $def->service_key) !== '') {
                    return strtolower(trim((string) $def->service_key));
                }
            }
        }

        return $this->inferFromServiceName($serviceName) ?? '';
    }

    private function baseCheckCommand(string $checkCommand): string
    {
        $raw = trim($checkCommand);
        if ($raw === '') {
            return '';
        }

        return strtolower(preg_replace('/!.*/', '', $raw));
    }

    private function inferFromServiceName(string $name): ?string
    {
        $n = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        if ($n === '') {
            return null;
        }
        if (str_contains($n, 'http') && ! str_contains($n, 'tcp') && ! preg_match('/port\s*\d+/', $n)) {
            return 'http';
        }
        if (str_contains($n, 'tcp') || preg_match('/port\s*\d+/', $n)) {
            return 'tcp_port';
        }
        if (str_contains($n, 'ping') || str_contains($n, 'live')) {
            return 'ping';
        }
        if (str_contains($n, 'mysql') || str_contains($n, 'mariadb') || preg_match('/\bdb\b/', $n)) {
            return 'mysql';
        }
        if (str_contains($n, 'postgres') || str_contains($n, 'pgsql')) {
            return 'pgsql';
        }
        if (str_contains($n, 'ssl') || str_contains($n, 'certificate') || str_contains($n, 'cert')) {
            return 'ssl_validity';
        }

        return null;
    }
}
