<?php

namespace App\Services;

use App\Models\ObserveServiceDefinition;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;

/**
 * Resolves ObserveServiceDefinition + overrides into final Nagios check_command strings.
 * UI never generates engine syntax; order comes from args_schema / service-type rules.
 */
class ObserveServiceCommandResolver
{
    /**
     * Context for resolution: workspace_id, project_id, user_id, has_custom_entitlement.
     */
    public function resolve(
        ObserveServiceDefinition $definition,
        array $overrides,
        array $context = []
    ): ResolveResult {
        $merged = $this->mergeWithDefaults($definition, $overrides);
        $key = $definition->service_key;

        if ($key === 'ping') {
            return $this->resolvePing($definition, $merged);
        }
        if ($key === 'tcp_port') {
            return $this->resolveTcpPort($definition, $merged);
        }
        if ($key === 'http') {
            return $this->resolveHttp($definition, $merged);
        }
        if ($key === 'custom') {
            return $this->resolveCustom($definition, $merged, $context);
        }

        return ResolveResult::fail("Unknown service_key: {$key}");
    }

    private function mergeWithDefaults(ObserveServiceDefinition $definition, array $overrides): array
    {
        $merged = [];
        foreach ($definition->getOrderedArgsSchema() as $arg) {
            $key = $arg['key'] ?? null;
            if ($key === null) {
                continue;
            }
            $merged[$key] = array_key_exists($key, $overrides)
                ? $overrides[$key]
                : ($arg['default'] ?? null);
        }
        return array_merge($merged, $overrides);
    }

    /**
     * ping => check_ping!warning!critical!packet_count
     * warning/critical format: rta,pl% (e.g. 100.0,20%)
     */
    private function resolvePing(ObserveServiceDefinition $definition, array $m): ResolveResult
    {
        $err = [];
        $warnRta = $m['warn_rta_ms'] ?? 100;
        $warnPl = $m['warn_pl_pct'] ?? 5;
        $critRta = $m['crit_rta_ms'] ?? 500;
        $critPl = $m['crit_pl_pct'] ?? 20;
        $packetCount = $m['packet_count'] ?? 5;

        $warnStr = $this->formatRtaPl($warnRta, $warnPl);
        $critStr = $this->formatRtaPl($critRta, $critPl);
        if ($warnStr === null) {
            $err[] = 'Invalid warning format: must be rta,pl% (e.g. 100.0,20%)';
        }
        if ($critStr === null) {
            $err[] = 'Invalid critical format: must be rta,pl% (e.g. 500.0,50%)';
        }
        $pc = $this->positiveInt($packetCount, 1);
        if ($pc === null) {
            $err[] = 'packet_count must be an integer > 0';
        }
        if ($err !== []) {
            return ResolveResult::fail(implode('; ', $err), $err);
        }

        $cmd = $definition->check_command
            . '!' . $warnStr
            . '!' . $critStr
            . '!' . (string) $pc;
        return ResolveResult::ok($cmd);
    }

    private function formatRtaPl($rta, $pl): ?string
    {
        $r = is_numeric($rta) ? (float) $rta : null;
        $p = is_numeric($pl) ? (float) $pl : null;
        if ($r === null || $p === null || $r < 0 || $p < 0 || $p > 100) {
            return null;
        }
        return sprintf('%s,%s%%', (string) $r, (string) $p);
    }

    private function positiveInt($v, int $min = 0): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = is_numeric($v) ? (int) $v : null;
        return ($n !== null && $n >= $min) ? $n : null;
    }

    /**
     * tcp_port => check_tcp!port!warning_seconds!critical_seconds
     */
    private function resolveTcpPort(ObserveServiceDefinition $definition, array $m): ResolveResult
    {
        $err = [];
        $port = $this->portValue($m['port'] ?? null);
        if ($port === null) {
            $err[] = 'port must be 1..65535';
        }
        $warn = $this->positiveInt($m['warning_seconds'] ?? null, 1);
        $crit = $this->positiveInt($m['critical_seconds'] ?? null, 1);
        if ($warn !== null && $crit !== null && $warn >= $crit) {
            $err[] = 'warning_seconds must be < critical_seconds when both set';
        }
        if ($err !== []) {
            return ResolveResult::fail(implode('; ', $err), $err);
        }

        $parts = [$definition->check_command, (string) $port];
        if ($warn !== null) {
            $parts[] = (string) $warn;
        }
        if ($crit !== null) {
            $parts[] = (string) $crit;
        }
        return ResolveResult::ok(implode('!', $parts));
    }

    private function portValue($v): ?int
    {
        $n = $this->positiveInt($v, 1);
        return ($n !== null && $n <= 65535) ? $n : null;
    }

    /**
     * http => check_http!use_ssl!port!path!warning_seconds!critical_seconds!basic_auth
     * use_ssl: 1/0, path normalized to start with /, port default 443 if ssl else 80
     */
    private function resolveHttp(ObserveServiceDefinition $definition, array $m): ResolveResult
    {
        $err = [];
        $useSsl = $this->boolValue($m['use_ssl'] ?? false);
        $port = $m['port'] ?? null;
        if ($port === null || $port === '') {
            $port = $useSsl ? 443 : 80;
        } else {
            $port = $this->portValue($port);
            if ($port === null) {
                $err[] = 'port must be 1..65535';
            }
        }
        $path = $m['path'] ?? '/';
        $path = $this->normalizePath($path);
        $warn = $this->positiveInt($m['warning_seconds'] ?? null, 1);
        $crit = $this->positiveInt($m['critical_seconds'] ?? null, 1);
        if ($warn !== null && $crit !== null && $warn >= $crit) {
            $err[] = 'warning_seconds must be < critical_seconds when both set';
        }
        $basicAuth = $m['basic_auth'] ?? '';
        if ($basicAuth !== '' && $basicAuth !== null && !str_contains((string) $basicAuth, ':')) {
            $err[] = 'basic_auth must contain \':\' when set (e.g. user:pass)';
        }
        if ($err !== []) {
            return ResolveResult::fail(implode('; ', $err), $err);
        }

        $parts = [
            $definition->check_command,
            $useSsl ? '1' : '0',
            (string) ($port ?? ($useSsl ? 443 : 80)),
            $path,
            $warn !== null ? (string) $warn : '',
            $crit !== null ? (string) $crit : '',
            $basicAuth !== null && $basicAuth !== '' ? (string) $basicAuth : '',
        ];
        return ResolveResult::ok(implode('!', $parts));
    }

    private function boolValue($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool) $v;
    }

    private function normalizePath($path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            return '/' . $path;
        }
        return $path;
    }

    /**
     * custom => {command}!{args...}
     * Deny unsafe chars in args; require entitlement + allowlisted command; audit on resolve.
     */
    private function resolveCustom(ObserveServiceDefinition $definition, array $m, array $context): ResolveResult
    {
        $command = $m['command'] ?? $m['check_command'] ?? '';
        $args = $m['args'] ?? $m['check_args'] ?? [];
        if (!is_array($args)) {
            $args = $args === null || $args === '' ? [] : [(string) $args];
        }

        $hasEntitlement = !empty($context['has_custom_entitlement']);
        $allowlist = config('observe.custom_command_allowlist', []);
        $allowlist = is_array($allowlist) ? $allowlist : [];
        $commandAllowed = $command !== '' && in_array($command, $allowlist, true);

        if (!$hasEntitlement) {
            return ResolveResult::fail('Custom service denied: entitlement required');
        }
        if (!$commandAllowed) {
            return ResolveResult::fail('Custom service denied: command not allowlisted');
        }

        $safeArgs = [];
        foreach ($args as $i => $a) {
            $s = $this->sanitizeCustomArg($a);
            if ($s === null) {
                return ResolveResult::fail('Custom args contain unsafe characters');
            }
            $safeArgs[] = $s;
        }

        $cmd = $command . (count($safeArgs) > 0 ? '!' . implode('!', $safeArgs) : '');

        $this->auditCustomResolve($context, $command, $safeArgs);
        return ResolveResult::ok($cmd);
    }

    private function sanitizeCustomArg($v): ?string
    {
        $s = (string) $v;
        if (preg_match('/[!;\\\\\n\r\'"$`]/', $s)) {
            return null;
        }
        return $s;
    }

    private function auditCustomResolve(array $context, string $command, array $args): void
    {
        $projectId = $context['project_id'] ?? null;
        $userId = $context['user_id'] ?? null;
        if ($projectId === null || !class_exists(AuditLog::class)) {
            Log::info('Observe custom resolve', ['command' => $command, 'context' => $context]);
            return;
        }
        try {
            AuditLog::create([
                'user_id' => $userId,
                'project_id' => $projectId,
                'action' => 'observe_custom_command_resolved',
                'metadata' => ['command' => $command, 'args_count' => count($args)],
                'timestamp' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Observe custom audit log failed', ['message' => $e->getMessage()]);
        }
    }
}

final class ResolveResult
{
    public bool $success;
    public string $check_command;
    /** @var string[] */
    public array $errors;

    private function __construct(bool $success, string $check_command, array $errors = [])
    {
        $this->success = $success;
        $this->check_command = $check_command;
        $this->errors = $errors;
    }

    public static function ok(string $check_command): self
    {
        return new self(true, $check_command, []);
    }

    public static function fail(string $message, array $errors = []): self
    {
        $e = $errors ?: [$message];
        return new self(false, '', $e);
    }
}
