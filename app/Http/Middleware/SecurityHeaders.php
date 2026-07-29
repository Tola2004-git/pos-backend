<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // This API only ever serves JSON, never framed HTML - these are
        // cheap defense-in-depth regardless: nosniff stops a browser from
        // guessing its way past an unexpected Content-Type, and the
        // referrer policy keeps the full request URL (which can include
        // search terms, order IDs, etc. in query strings) from leaking to
        // whatever a browser navigates to next.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
