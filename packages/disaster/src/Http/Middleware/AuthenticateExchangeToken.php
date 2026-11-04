<?php

namespace Lumina\Disaster\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Lumina\Disaster\Models\ExchangeToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateExchangeToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $plain = $request->bearerToken() ?? '';
        $token = ExchangeToken::findActiveByPlain($plain);

        if ($token === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        foreach ($abilities as $ability) {
            if ($ability !== '' && ! $token->allows($ability)) {
                return response()->json(['message' => 'Forbidden for this exchange token.'], 403);
            }
        }

        $token->touchUsed();
        $request->attributes->set('exchange_token', $token);
        $request->attributes->set('exchange_organization_id', (int) $token->organization_id);

        return $next($request);
    }
}
