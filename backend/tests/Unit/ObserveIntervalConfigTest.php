<?php

namespace Tests\Unit;

use Tests\TestCase;

class ObserveIntervalConfigTest extends TestCase
{
    public function test_default_check_interval_is_five_minutes(): void
    {
        $this->assertSame(300, (int) config('observe.default_check_interval_seconds'));
    }

    public function test_minimum_check_interval_is_one_minute(): void
    {
        $this->assertSame(60, (int) config('observe.min_check_interval_seconds'));
    }
}
