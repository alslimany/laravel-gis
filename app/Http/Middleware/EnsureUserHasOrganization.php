<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasOrganization
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        if (! $request->user()->organization_id) {
            // ADR-0001 §5: a super admin with no org is sent to org setup on
            // web/Inertia requests; JSON/API callers get a plain 403.
            if ($request->user()->isSuperAdmin() && ! $request->expectsJson()) {
                return redirect()->route('organization.setup');
            }

            abort(403, 'You must belong to an organization to access this resource.');
        }

        return $next($request);
    }
}
