<?php

namespace App\Http\Middleware;

use Closure;

class SecurityHeaders
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if ($request->isSecure() && config('security.hsts.enabled', true)) {
            $maxAge = max(0, (int) config('security.hsts.max_age', 31536000));
            $hsts = 'max-age='.$maxAge;

            if (config('security.hsts.include_subdomains', false)) {
                $hsts .= '; includeSubDomains';
            }

            if (config('security.hsts.preload', false)) {
                $hsts .= '; preload';
            }

            $response->headers->set('Strict-Transport-Security', $hsts);
        }

        return $response;
    }
}
