<?php

namespace App\Services;

use App\Models\ObserveServiceDefinition;

/**
 * Redacts and preserves secret check_args fields (passwords, tokens).
 */
class ObserveCheckArgsSecrets
{
    /**
     * @param  array<string, mixed>  $checkArgs
     * @return list<string>
     */
    public function configuredSecretKeys(array $checkArgs, ?ObserveServiceDefinition $definition = null): array
    {
        $keys = [];
        foreach ($this->secretKeysFromDefinition($definition) as $key) {
            if ($this->hasSecretValue($checkArgs, $key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $checkArgs
     * @return array<string, mixed>
     */
    public function redactForResponse(array $checkArgs, ?ObserveServiceDefinition $definition = null): array
    {
        $redacted = $checkArgs;
        foreach ($this->secretKeysFromDefinition($definition) as $key) {
            if ($this->hasSecretValue($redacted, $key)) {
                unset($redacted[$key]);
            }
        }

        return $redacted;
    }

    /**
     * When the client omits or clears a secret field, keep the stored value.
     *
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    public function mergePreservedSecrets(
        array $incoming,
        ?array $existing,
        ?ObserveServiceDefinition $definition = null
    ): array {
        if ($existing === null || $existing === []) {
            return $incoming;
        }

        foreach ($this->secretKeysFromDefinition($definition) as $key) {
            if (! array_key_exists($key, $incoming) && $this->hasSecretValue($existing, $key)) {
                $incoming[$key] = $existing[$key];
            }
        }

        return $incoming;
    }

    public function isSecretKey(string $key, ?ObserveServiceDefinition $definition = null): bool
    {
        if (in_array($key, $this->secretKeysFromDefinition($definition), true)) {
            return true;
        }

        $lower = strtolower($key);

        return str_contains($lower, 'password')
            || str_contains($lower, 'secret')
            || $lower === 'basic_auth';
    }

    /**
     * @return list<string>
     */
    private function secretKeysFromDefinition(?ObserveServiceDefinition $definition): array
    {
        $keys = [];
        $schema = $definition?->args_schema ?? [];
        if (! is_array($schema)) {
            return $keys;
        }

        foreach ($schema as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $key = $entry['key'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            $type = strtolower((string) ($entry['type'] ?? ''));
            if ($type === 'password' || $this->isSecretKey($key)) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function hasSecretValue(array $args, string $key): bool
    {
        if (! array_key_exists($key, $args)) {
            return false;
        }
        $value = $args[$key];

        return $value !== null && $value !== '';
    }
}
