<?php

namespace App\Http\Controllers;

use App\Models\DashboardBoard;
use App\Models\Form;
use App\Models\Layer;
use App\Models\Map;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CatalogController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('organization');
    }

    /**
     * Search layers, maps, forms, and dashboards in the current organization.
     */
    public function index(Request $request)
    {
        $orgId = Auth::user()->organization_id;
        $q = trim((string) $request->query('q', ''));

        $layers = Layer::query()
            ->where('organization_id', $orgId)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'ILIKE', "%{$q}%")
                        ->orWhere('description', 'ILIKE', "%{$q}%")
                        ->orWhere('table_name', 'ILIKE', "%{$q}%");
                });
            })
            ->latest()
            ->limit(50)
            ->get();

        $maps = Map::query()
            ->where('organization_id', $orgId)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'ILIKE', "%{$q}%")
                        ->orWhere('description', 'ILIKE', "%{$q}%");
                });
            })
            ->latest()
            ->limit(50)
            ->get();

        $forms = Form::query()
            ->where('organization_id', $orgId)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'ILIKE', "%{$q}%")
                        ->orWhere('description', 'ILIKE', "%{$q}%");
                });
            })
            ->latest()
            ->limit(50)
            ->get();

        $dashboards = DashboardBoard::query()
            ->where('organization_id', $orgId)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'ILIKE', "%{$q}%")
                        ->orWhere('description', 'ILIKE', "%{$q}%");
                });
            })
            ->latest()
            ->limit(50)
            ->get();

        $access = app(\App\Services\ContentAccessService::class);
        $user = Auth::user();
        $layers = $access->filterVisible($user, 'layer', $layers);
        $maps = $access->filterVisible($user, 'map', $maps);
        $dashboards = $access->filterVisible($user, 'dashboard', $dashboards);

        return Inertia::render('Catalog/Index', compact('q', 'layers', 'maps', 'forms', 'dashboards'));
    }
}
