<?php

namespace App\Services;

use App\Models\ObserveServiceDefinition;

/**
 * Merges schema defaults and normalizes check_args before native checks run.
 */
class ObserveCheckArgsResolver
{
    /**
     * @param  array<string, mixed>  $checkArgs
     * @return array<string, mixed>
     */
    public function resolve(string $serviceKey, string $hostAddress, array $checkArgs, ?ObserveServiceDefinition $definition = null): array
    {
        $args = $checkArgs;
        $schema = $definition?->args_schema ?? [];

        if (is_array($schema)) {
            foreach ($schema as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $key = $entry['key'] ?? null;
                if (! is_string($key) || $key === '') {
                    continue;
                }
                $current = $args[$key] ?? null;
                if ($current === null || $current === '') {
                    if (array_key_exists('default', $entry) && $entry['default'] !== null && $entry['default'] !== '') {
                        $args[$key] = $entry['default'];
                    }
                }
            }
        }

        $key = strtolower(trim($serviceKey));

        return match ($key) {
            'http' => $this->normalizeHttp($args, $hostAddress),
            'tcp_port' => $this->normalizeTcp($args),
            'mysql' => $this->normalizeMysql($args, $hostAddress),
            'pgsql' => $this->normalizePgsql($args, $hostAddress),
            'ssl_validity' => $this->normalizeSsl($args, $hostAddress),
            default => $args,
        };
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function normalizeHttp(array $args, string $hostAddress): array
    {
        $path = isset($args['path']) ? trim((string) $args['path']) : '';
        if ($path !== '' && preg_match('#^https?://#i', $path)) {
            $args['url'] = $path;
            unset($args['path']);
        }

        if (empty($args['url']) && empty($args['path'])) {
            $args['path'] = '/';
        }

        if (! isset($args['expect']) || $args['expect'] === '' || $args['expect'] === null) {
            $args['expect'] = 200;
        }

        $useSsl = filter_var($args['use_ssl'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! isset($args['port']) || $args['port'] === '' || $args['port'] === null) {
            if (! empty($args['url']) && preg_match('#^https://#i', (string) $args['url'])) {
                $args['port'] = 443;
            } elseif ($useSsl) {
                $args['port'] = 443;
            } else {
                $args['port'] = 80;
            }
        }

        if (empty($args['hostname']) && empty($args['url']) && trim($hostAddress) !== '') {
            $args['hostname'] = trim($hostAddress);
        }

        return $args;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function normalizeTcp(array $args): array
    {
        if (! isset($args['port']) || $args['port'] === '' || $args['port'] === null) {
            $args['port'] = 80;
        }

        return $args;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function normalizeMysql(array $args, string $hostAddress): array
    {
        if (empty($args['host'])) {
            $args['host'] = trim($hostAddress) !== '' ? trim($hostAddress) : '127.0.0.1';
        }
        $host = strtolower(trim((string) $args['host']));
        if ($host === 'localhost') {
            $args['host'] = '127.0.0.1';
        }
        if (! isset($args['port']) || $args['port'] === '' || $args['port'] === null) {
            $args['port'] = 3306;
        }

        return $args;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function normalizePgsql(array $args, string $hostAddress): array
    {
        if (empty($args['host'])) {
            $args['host'] = trim($hostAddress) !== '' ? trim($hostAddress) : '127.0.0.1';
        }
        if (! isset($args['port']) || $args['port'] === '' || $args['port'] === null) {
            $args['port'] = 5432;
        }
        if (empty($args['user'])) {
            $args['user'] = 'postgres';
        }

        return $args;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function normalizeSsl(array $args, string $hostAddress): array
    {
        if (! isset($args['port']) || $args['port'] === '' || $args['port'] === null) {
            $args['port'] = 443;
        }
        if (! isset($args['warn_days']) || $args['warn_days'] === '' || $args['warn_days'] === null) {
            $args['warn_days'] = 30;
        }
        if (! isset($args['crit_days']) || $args['crit_days'] === '' || $args['crit_days'] === null) {
            $args['crit_days'] = 7;
        }
        if (empty($args['url']) && empty($args['urls']) && empty($args['hostname']) && trim($hostAddress) !== '') {
            $args['hostname'] = trim($hostAddress);
        }

        return $args;
    }
}
