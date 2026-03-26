<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class GatewayInternalSecretRequirementTest extends TestCase
{
    public function test_observe_poll_alias_executes_native_checks(): void
    {
        $exitCode = Artisan::call('observe:poll');
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('deprecated', strtolower(Artisan::output()));
    }
}

