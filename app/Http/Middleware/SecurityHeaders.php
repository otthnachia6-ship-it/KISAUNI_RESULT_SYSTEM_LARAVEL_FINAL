<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Basic browser-hardening headers on every response.
 *  - X-Frame-Options: other websites cannot show this system inside a frame (clickjacking).
 *  - X-Content-Type-Options: the browser must not guess a different file type.
 *  - Referrer-Policy: other sites only learn the address of this site, not the full page URL.
 * (HSTS and a Content-Security-Policy are deliberately not set here; they need server-level testing first.)
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
