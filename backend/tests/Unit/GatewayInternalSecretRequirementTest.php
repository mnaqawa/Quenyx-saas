<?php

namespace Tests\Unit;

use App\Console\Commands\PollObserveData;
use App\Services\NagiosConfigPublisher;
use App\Services\ObserveServiceCommandResolver;
use Tests\TestCase;

class GatewayInternalSecretRequirementTest extends TestCase
{
    public function test_poll_observe_data_throws_when_internal_secret_missing(): void
    {
        config(['app.gateway_internal_secret' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GATEWAY_INTERNAL_SECRET is required.');

        new PollObserveData();
    }

    public function test_nagios_publisher_throws_when_internal_secret_missing(): void
    {
        config(['app.gateway_internal_secret' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GATEWAY_INTERNAL_SECRET is required.');

        new NagiosConfigPublisher(new ObserveServiceCommandResolver());
    }

    public function test_constructors_work_when_internal_secret_is_present(): void
    {
        config(['app.gateway_internal_secret' => 'test-secret']);

        $poll = new PollObserveData();
        $publisher = new NagiosConfigPublisher(new ObserveServiceCommandResolver());

        $this->assertInstanceOf(PollObserveData::class, $poll);
        $this->assertInstanceOf(NagiosConfigPublisher::class, $publisher);
    }
}

