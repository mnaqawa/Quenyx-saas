<?php

namespace App\Support;

class AgentGateway
{
    public static function url(): string
    {
        $gateway = config('agent.gateway', []);
        $protocol = $gateway['protocol'] ?? 'https';
        $host = $gateway['host'] ?? 'cloud.quenyx.com';
        $port = (int) ($gateway['port'] ?? 9444);

        return sprintf('%s://%s:%d', $protocol, $host, $port);
    }
}
