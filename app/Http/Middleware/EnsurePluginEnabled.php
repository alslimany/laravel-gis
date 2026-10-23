<?php

namespace App\Http\Middleware;

use App\Plugins\PluginManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `plugin:{key}` (ADR-0001 D3, §5): 404 when the plugin is not installed or
 * not enabled for the user's own organization. No super-admin bypass; runs
 * after `organization`. abort(404) renders JSON under api/* (bootstrap/app).
 */
class EnsurePluginEnabled
{
    public function __construct(protected PluginManager $plugins) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        $organizationId = $request->user()?->organization_id;

        if (! $this->plugins->isEnabledFor($key, $organizationId)) {
            abort(404);
        }

        return $next($request);
    }
}
