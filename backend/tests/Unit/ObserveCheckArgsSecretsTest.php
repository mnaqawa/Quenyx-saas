<?php

namespace Tests\Unit;

use App\Models\ObserveServiceDefinition;
use App\Services\ObserveCheckArgsSecrets;
use Tests\TestCase;

class ObserveCheckArgsSecretsTest extends TestCase
{
    public function test_merge_preserves_password_when_incoming_empty(): void
    {
        $def = new ObserveServiceDefinition();
        $def->args_schema = [
            ['key' => 'user', 'type' => 'string'],
            ['key' => 'password', 'type' => 'password'],
        ];

        $service = new ObserveCheckArgsSecrets();
        $merged = $service->mergePreservedSecrets(
            ['user' => 'app'],
            ['user' => 'app', 'password' => 'secret'],
            $def
        );

        $this->assertSame('secret', $merged['password']);
        $this->assertSame('app', $merged['user']);
    }

    public function test_redact_removes_password_from_response(): void
    {
        $def = new ObserveServiceDefinition();
        $def->args_schema = [
            ['key' => 'password', 'type' => 'password'],
        ];

        $service = new ObserveCheckArgsSecrets();
        $redacted = $service->redactForResponse(['password' => 'secret', 'host' => '127.0.0.1'], $def);

        $this->assertArrayNotHasKey('password', $redacted);
        $this->assertSame('127.0.0.1', $redacted['host']);
        $this->assertSame(['password'], $service->configuredSecretKeys(['password' => 'secret'], $def));
    }

    public function test_merge_does_not_preserve_explicit_empty_password(): void
    {
        $def = new ObserveServiceDefinition();
        $def->args_schema = [
            ['key' => 'password', 'type' => 'password'],
        ];

        $service = new ObserveCheckArgsSecrets();
        $merged = $service->mergePreservedSecrets(
            ['password' => ''],
            ['password' => 'secret'],
            $def
        );

        $this->assertSame('', $merged['password']);
    }
}
