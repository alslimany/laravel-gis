<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ExportController;
use App\Models\Layer;
use App\Services\ContentAccessService;
use Illuminate\Http\Request;

class ExportApiController extends Controller
{
    public function __construct(protected ContentAccessService $access)
    {
        $this->middleware('auth:sanctum');
    }

    public function geojson(Request $request, Layer $layer)
    {
        $this->authorizeView($request, $layer);

        return app(ExportController::class)->exportGeoJSON($request, $layer);
    }

    public function csv(Request $request, Layer $layer)
    {
        $this->authorizeView($request, $layer);

        return app(ExportController::class)->exportCSV($request, $layer);
    }

    public function excel(Request $request, Layer $layer)
    {
        $this->authorizeView($request, $layer);

        return app(ExportController::class)->exportExcel($request, $layer);
    }

    protected function authorizeView(Request $request, Layer $layer): void
    {
        $user = $request->user();
        if (! $this->access->canView($user, 'layer', $layer->id, $layer->organization_id)) {
            abort(403, 'You do not have access to this layer.');
        }
    }
}
