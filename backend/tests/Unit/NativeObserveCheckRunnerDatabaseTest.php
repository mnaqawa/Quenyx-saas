<?php

namespace Tests\Unit;

use App\Services\NativeObserveCheckRunner;
use PHPUnit\Framework\TestCase;

class NativeObserveCheckRunnerDatabaseTest extends TestCase
{
    public function test_mysql_returns_actionable_message_not_exit_255(): void
    {
        $runner = new NativeObserveCheckRunner();
        $result = $runner->run('mysql', '127.0.0.1', [
            'host' => '192.0.2.1',
            'port' => 3306,
            'user' => 'root',
            'password' => '',
        ]);

        $this->assertNotSame('unknown', $result['state']);
        $this->assertStringNotContainsString('exit code 255', strtolower($result['output']));
        $this->assertStringContainsString('MYSQL', $result['output']);
    }

    public function test_mysql_service_key_is_native(): void
    {
        $this->assertContains('mysql', NativeObserveCheckRunner::NATIVE_SERVICE_KEYS);
        $this->assertContains('pgsql', NativeObserveCheckRunner::NATIVE_SERVICE_KEYS);
        $this->assertContains('ssl_validity', NativeObserveCheckRunner::NATIVE_SERVICE_KEYS);
    }
}
