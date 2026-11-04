<?php

namespace Lumina\DesktopConnect\Http\Middleware;

use App\Models\Layer;
use Closure;
use Illuminate\Http\Request;
use Lumina\DesktopConnect\Models\Grant;
use Lumina\DesktopConnect\Services\DesktopAccess;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce Sanctum dc:layer:{publicId}:{scope} and live DesktopAccess grants (DG-1.6).
 */
class EnsureDesktopLayerAbility
{
    public function handle(Request $request, Closure $next, string $scope = Grant::SCOPE_READ): Response
    {
        if (! in_array($scope, Grant::SCOPES, true)) {
            abort(500, 'Invalid desktop layer scope.');
        }

        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        /** @var Layer|null $layer */
        $layer = $request->route('layer');
        if (! $layer instanceof Layer || blank($layer->public_id)) {
            abort(404);
        }

        $publicId = (string) $layer->public_id;
        $required = "dc:layer:{$publicId}:{$scope}";
        $editAbility = "dc:layer:{$publicId}:edit";

        $tokenAllows = $user->tokenCan('*')
            || $user->tokenCan($required)
            || ($scope === Grant::SCOPE_READ && $user->tokenCan($editAbility));

        if (! $tokenAllows) {
            abort(403, 'Desktop layer ability missing.');
        }

        // Live grant check so revoke/expiry takes effect on the next request.
        if (! DesktopAccess::can($user, $layer, $scope)) {
            abort(403, 'Desktop grant denied.');
        }

        return $next($request);
    }
}
