<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        // Org-aware (#66): roles are organization roles, never global. A user
        // without an organization holds no admin-style role on these routes.
        $organizationId = $request->user()->organization_id;

        if ($organizationId === null || ! $request->user()->hasRoleInOrg($roles, (int) $organizationId)) {
            abort(403, 'Unauthorized action.');
        }

        return $next($request);
    }
}
