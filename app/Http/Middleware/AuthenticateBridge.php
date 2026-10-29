<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the CRM bridge with a shared secret.
 *
 * The endpoint changes a client balance, so it must never be reachable from
 * the browser session: only a request that carries the configured Bearer
 * token is let through, and an installation without a token answers 503
 * instead of exposing an unauthenticated money endpoint.
 */
class AuthenticateBridge
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('trading.bridge.token', '');

        if ($expected === '') {
            return response()->json([
                'message' => 'Интеграция с CRM не настроена: задайте TRADING_BRIDGE_TOKEN.',
            ], 503);
        }

        $provided = (string) $request->bearerToken();

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Неверный токен интеграции.',
            ], 401);
        }

        return $next($request);
    }
}
