<?php
/**
 * Shared helpers for observe NRPE-style plugins (local vs remote host detection).
 */

if (! function_exists('observe_is_local_host')) {
    function observe_is_local_host(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }

        $locals = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        if (function_exists('gethostname')) {
            $hn = strtolower(trim((string) gethostname()));
            if ($hn !== '') {
                $locals[] = $hn;
                // CloudQuenyx / short hostname variants
                if (str_contains($hn, '.')) {
                    $locals[] = explode('.', $hn, 2)[0];
                }
            }
        }
        if (function_exists('php_uname')) {
            $n = strtolower(trim((string) php_uname('n')));
            if ($n !== '') {
                $locals[] = $n;
                if (str_contains($n, '.')) {
                    $locals[] = explode('.', $n, 2)[0];
                }
            }
        }
        if (function_exists('gethostbyname')) {
            $lb = gethostbyname('localhost');
            if ($lb !== '' && $lb !== 'localhost') {
                $locals[] = strtolower($lb);
            }
        }

        return in_array($host, array_values(array_unique($locals)), true);
    }
}
