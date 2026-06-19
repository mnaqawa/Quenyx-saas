<?php

namespace Tests\Unit;

use App\Services\ObserveCheckArgsResolver;
use PHPUnit\Framework\TestCase;

class ObserveCheckArgsResolverTest extends TestCase
{
    private ObserveCheckArgsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ObserveCheckArgsResolver();
    }

    public function test_http_path_with_full_url_moves_to_url_key(): void
    {
        $args = $this->resolver->resolve('http', '127.0.0.1', [
            'path' => 'https://cloud.quenyx.com/',
        ]);

        $this->assertSame('https://cloud.quenyx.com/', $args['url']);
        $this->assertArrayNotHasKey('path', $args);
    }

    public function test_http_applies_default_port_and_expect(): void
    {
        $args = $this->resolver->resolve('http', '127.0.0.1', []);

        $this->assertSame('/', $args['path']);
        $this->assertSame(80, $args['port']);
        $this->assertSame(200, $args['expect']);
    }

    public function test_mysql_defaults_host_and_port(): void
    {
        $args = $this->resolver->resolve('mysql', '10.0.0.5', []);

        $this->assertSame('10.0.0.5', $args['host']);
        $this->assertSame(3306, $args['port']);
    }

    public function test_ssl_defaults_thresholds(): void
    {
        $args = $this->resolver->resolve('ssl_validity', 'example.com', []);

        $this->assertSame(443, $args['port']);
        $this->assertSame(30, $args['warn_days']);
        $this->assertSame(7, $args['crit_days']);
        $this->assertSame('example.com', $args['hostname']);
    }
}
