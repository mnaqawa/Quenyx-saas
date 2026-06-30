<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies production security response headers (GA hardening).
 *
 * All values come from config/security.php and are env-driven. The middleware
 * never overwrites a header that a downstream handler has already set, so route
 * or controller-specific overrides (e.g. a custom CSP for an embed endpoint)
 * still win. HSTS is only emitted on secure (HTTPS) requests.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! config('security.headers_enabled', true)) {
            return $response;
        }

        $headers = $response->headers;

        $set = static function (string $name, ?string $value) use ($headers): void {
            if ($value === null || $value === '') {
                return;
            }
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        };

        $set('X-Frame-Options', config('security.x_frame_options'));
        $set('X-Content-Type-Options', config('security.x_content_type_options'));
        $set('Referrer-Policy', config('security.referrer_policy'));
        $set('Permissions-Policy', config('security.permissions_policy'));
        $set('Cross-Origin-Opener-Policy', config('security.cross_origin_opener_policy'));
        $set('Cross-Origin-Resource-Policy', config('security.cross_origin_resource_policy'));

        $csp = (string) config('security.csp', '');
        if ($csp !== '') {
            $cspHeader = config('security.csp_report_only')
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';
            $set($cspHeader, $csp);
        }

        if ($request->isSecure() && config('security.hsts.enabled', true)) {
            $maxAge = (int) config('security.hsts.max_age', 31536000);
            $value = 'max-age='.$maxAge;
            if (config('security.hsts.include_subdomains', true)) {
                $value .= '; includeSubDomains';
            }
            if (config('security.hsts.preload', false)) {
                $value .= '; preload';
            }
            $set('Strict-Transport-Security', $value);
        }

        // Reduce information disclosure where the runtime allows it.
        $headers->remove('X-Powered-By');
        $headers->remove('Server');

        return $response;
    }
}
