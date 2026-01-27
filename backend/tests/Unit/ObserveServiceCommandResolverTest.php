<?php

namespace Tests\Unit;

use App\Models\ObserveServiceDefinition;
use App\Services\ObserveServiceCommandResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObserveServiceCommandResolverTest extends TestCase
{
    use RefreshDatabase;

    private ObserveServiceCommandResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ObserveServiceCommandResolver();
    }

    private function pingDefinition(): ObserveServiceDefinition
    {
        $d = new ObserveServiceDefinition();
        $d->service_key = 'ping';
        $d->check_command = 'check_ping';
        $d->args_schema = [
            ['position' => 0, 'key' => 'warn_rta_ms', 'default' => 100, 'required' => false],
            ['position' => 1, 'key' => 'warn_pl_pct', 'default' => 5, 'required' => false],
            ['position' => 2, 'key' => 'crit_rta_ms', 'default' => 500, 'required' => false],
            ['position' => 3, 'key' => 'crit_pl_pct', 'default' => 20, 'required' => false],
        ];
        return $d;
    }

    private function tcpPortDefinition(): ObserveServiceDefinition
    {
        $d = new ObserveServiceDefinition();
        $d->service_key = 'tcp_port';
        $d->check_command = 'check_tcp';
        $d->args_schema = [
            ['position' => 0, 'key' => 'port', 'default' => 80, 'required' => true],
        ];
        return $d;
    }

    private function httpDefinition(): ObserveServiceDefinition
    {
        $d = new ObserveServiceDefinition();
        $d->service_key = 'http';
        $d->check_command = 'check_http';
        $d->args_schema = [
            ['position' => 0, 'key' => 'path', 'default' => '/', 'required' => false],
            ['position' => 1, 'key' => 'port', 'default' => 80, 'required' => false],
        ];
        return $d;
    }

    private function customDefinition(): ObserveServiceDefinition
    {
        $d = new ObserveServiceDefinition();
        $d->service_key = 'custom';
        $d->check_command = '';
        $d->args_schema = [];
        return $d;
    }

    // --- Ping ---

    public function test_ping_uses_defaults_when_overrides_empty(): void
    {
        $def = $this->pingDefinition();
        $result = $this->resolver->resolve($def, []);
        $this->assertTrue($result->success);
        $this->assertStringStartsWith('check_ping!', $result->check_command);
        $this->assertStringContainsString('100,5%', $result->check_command);
        $this->assertStringContainsString('500,20%', $result->check_command);
        $this->assertStringEndsWith('!5', $result->check_command);
    }

    public function test_ping_override_warn_crit_and_missing_packet_count_uses_default(): void
    {
        $def = $this->pingDefinition();
        $result = $this->resolver->resolve($def, [
            'warn_rta_ms' => 50,
            'warn_pl_pct' => 10,
            'crit_rta_ms' => 200,
            'crit_pl_pct' => 25,
        ]);
        $this->assertTrue($result->success);
        $this->assertStringContainsString('50,10%', $result->check_command);
        $this->assertStringContainsString('200,25%', $result->check_command);
        $this->assertStringEndsWith('!5', $result->check_command);
    }

    public function test_ping_invalid_warn_format_fails(): void
    {
        $def = $this->pingDefinition();
        $result = $this->resolver->resolve($def, ['warn_rta_ms' => 'x', 'warn_pl_pct' => 5]);
        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errors);
    }

    public function test_ping_invalid_packet_count_fails(): void
    {
        $def = $this->pingDefinition();
        $result = $this->resolver->resolve($def, ['packet_count' => 0]);
        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errors);
    }

    // --- HTTP ---

    public function test_http_ssl_false_default_port_80(): void
    {
        $def = $this->httpDefinition();
        $result = $this->resolver->resolve($def, ['use_ssl' => false]);
        $this->assertTrue($result->success);
        $parts = explode('!', $result->check_command);
        $this->assertCount(7, $parts);
        $this->assertSame('check_http', $parts[0]);
        $this->assertSame('0', $parts[1]);
        $this->assertSame('80', $parts[2]);
        $this->assertSame('/', $parts[3]);
    }

    public function test_http_ssl_true_default_port_443(): void
    {
        $def = $this->httpDefinition();
        $result = $this->resolver->resolve($def, ['use_ssl' => true]);
        $this->assertTrue($result->success);
        $parts = explode('!', $result->check_command);
        $this->assertSame('1', $parts[1]);
        $this->assertSame('443', $parts[2]);
    }

    public function test_http_path_normalized_to_start_with_slash(): void
    {
        $def = $this->httpDefinition();
        $result = $this->resolver->resolve($def, ['path' => 'api/health']);
        $this->assertTrue($result->success);
        $parts = explode('!', $result->check_command);
        $this->assertSame('/api/health', $parts[3]);
    }

    public function test_http_basic_auth_without_colon_fails(): void
    {
        $def = $this->httpDefinition();
        $result = $this->resolver->resolve($def, ['basic_auth' => 'nocolon']);
        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errors);
    }

    public function test_http_basic_auth_with_colon_succeeds(): void
    {
        $def = $this->httpDefinition();
        $result = $this->resolver->resolve($def, ['basic_auth' => 'user:pass']);
        $this->assertTrue($result->success);
        $parts = explode('!', $result->check_command);
        $this->assertSame('user:pass', $parts[6]);
    }

    public function test_http_warning_ge_critical_fails(): void
    {
        $def = $this->httpDefinition();
        $result = $this->resolver->resolve($def, [
            'warning_seconds' => 10,
            'critical_seconds' => 10,
        ]);
        $this->assertFalse($result->success);
    }

    // --- TCP ---

    public function test_tcp_port_invalid_port_rejected(): void
    {
        $def = $this->tcpPortDefinition();
        $result = $this->resolver->resolve($def, ['port' => 99999]);
        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errors);
    }

    public function test_tcp_port_zero_rejected(): void
    {
        $def = $this->tcpPortDefinition();
        $result = $this->resolver->resolve($def, ['port' => 0]);
        $this->assertFalse($result->success);
    }

    public function test_tcp_port_valid_succeeds(): void
    {
        $def = $this->tcpPortDefinition();
        $result = $this->resolver->resolve($def, ['port' => 443]);
        $this->assertTrue($result->success);
        $this->assertSame('check_tcp!443', $result->check_command);
    }

    public function test_tcp_port_warning_ge_critical_fails(): void
    {
        $def = $this->tcpPortDefinition();
        $result = $this->resolver->resolve($def, [
            'port' => 80,
            'warning_seconds' => 5,
            'critical_seconds' => 3,
        ]);
        $this->assertFalse($result->success);
    }

    // --- Custom ---

    public function test_custom_denied_without_entitlement(): void
    {
        config(['observe.custom_command_allowlist' => ['check_dns']]);
        $def = $this->customDefinition();
        $result = $this->resolver->resolve($def, [
            'command' => 'check_dns',
            'args' => ['8.8.8.8'],
        ], ['has_custom_entitlement' => false]);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('entitlement', implode(' ', $result->errors));
    }

    public function test_custom_denied_non_allowlisted_command(): void
    {
        config(['observe.custom_command_allowlist' => ['check_dns']]);
        $def = $this->customDefinition();
        $result = $this->resolver->resolve($def, [
            'command' => 'check_evil',
            'args' => [],
        ], ['has_custom_entitlement' => true]);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('allowlist', implode(' ', $result->errors));
    }

    public function test_custom_allowed_with_entitlement_and_allowlisted_command(): void
    {
        config(['observe.custom_command_allowlist' => ['check_dns']]);
        $def = $this->customDefinition();
        $result = $this->resolver->resolve($def, [
            'command' => 'check_dns',
            'args' => ['8.8.8.8'],
        ], ['has_custom_entitlement' => true]);
        $this->assertTrue($result->success);
        $this->assertSame('check_dns!8.8.8.8', $result->check_command);
    }

    public function test_custom_unsafe_chars_in_args_denied(): void
    {
        config(['observe.custom_command_allowlist' => ['check_dns']]);
        $def = $this->customDefinition();
        $result = $this->resolver->resolve($def, [
            'command' => 'check_dns',
            'args' => ['8.8.8.8; rm -rf /'],
        ], ['has_custom_entitlement' => true]);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('unsafe', implode(' ', $result->errors));
    }
}
