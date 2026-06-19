<?php

namespace Tests\Unit;

use App\Services\ObserveServiceKeyResolver;
use Tests\TestCase;

class ObserveServiceKeyResolverTest extends TestCase
{
    private ObserveServiceKeyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ObserveServiceKeyResolver();
    }

    public function test_uses_explicit_service_key_when_set(): void
    {
        $this->assertSame('mysql', $this->resolver->resolve('mysql', 'check_disk', 'Disk'));
    }

    public function test_resolves_mysql_from_check_command_when_service_key_empty(): void
    {
        $this->assertSame('mysql', $this->resolver->resolve('', 'check_mysql', 'DB Check'));
    }

    public function test_resolves_mysql_from_service_name_db_check(): void
    {
        $this->assertSame('mysql', $this->resolver->resolve('', '', 'DB Check'));
    }

    public function test_resolves_ssl_from_check_command(): void
    {
        $this->assertSame('ssl_validity', $this->resolver->resolve('', 'check_ssl_validity', 'SSL'));
    }

    public function test_strips_nagios_style_bang_args_from_check_command(): void
    {
        $this->assertSame('http', $this->resolver->resolve('', 'check_http!1!443!/', 'Website'));
    }
}
