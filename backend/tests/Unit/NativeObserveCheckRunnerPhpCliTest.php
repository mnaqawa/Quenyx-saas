<?php

namespace Tests\Unit;

use App\Services\NativeObserveCheckRunner;
use ReflectionMethod;
use Tests\TestCase;

class NativeObserveCheckRunnerPhpCliTest extends TestCase
{
    public function test_resolve_php_cli_skips_fpm_binary_from_config(): void
    {
        config(['observe.php_cli_binary' => PHP_BINARY]);

        $runner = new NativeObserveCheckRunner();
        $method = new ReflectionMethod(NativeObserveCheckRunner::class, 'resolvePhpCliBinary');
        $method->setAccessible(true);
        $binary = $method->invoke($runner);

        $this->assertNotEmpty($binary);
        $this->assertStringNotContainsString('fpm', strtolower($binary));
    }
}
