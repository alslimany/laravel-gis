<?php

namespace App\Http\Controllers;

use App\Models\Map;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PrintController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('organization');
    }

    /**
     * Show printable map layout (title, legend, scale, north arrow).
     */
    public function show(Map $map)
    {
        $this->authorizeMap($map);

        return Inertia::render('Print/Map', $this->printData($map));
    }

    /**
     * Download map print layout as PDF (Dompdf) or fall back to printable HTML.
     * Accepts optional captured map image, scale, and legend from the builder.
     */
    public function pdf(Request $request, Map $map)
    {
        $this->authorizeMap($map);

        $validated = $request->validate([
            'image' => 'nullable|string',
            'scaleDenominator' => 'nullable|integer|min:1',
            'legend' => 'nullable|array',
            'legend.*.label' => 'nullable|string|max:255',
            'legend.*.color' => 'nullable|string|max:32',
            'title' => 'nullable|string|max:255',
        ]);

        $data = $this->printData($map, $validated);

        if (config('print.use_dompdf', true) && class_exists(Pdf::class)) {
            try {
                $pdf = Pdf::loadView('print.map', $data)
                    ->setPaper(
                        config('print.paper', 'a4'),
                        config('print.orientation', 'landscape')
                    );

                $filename = str($map->name)->slug().'-print.pdf';

                return $pdf->download($filename);
            } catch (\Throwable $e) {
                // Fall through to printable HTML
            }
        }

        return view('print.map', array_merge($data, ['autoPrint' => true]));
    }

    /**
     * @param  array<string, mixed>  $capture
     * @return array<string, mixed>
     */
    protected function printData(Map $map, array $capture = []): array
    {
        $organization = Auth::user()->organization;
        $logoDataUri = null;

        if ($organization?->logo_path && Storage::disk('public')->exists($organization->logo_path)) {
            $bytes = Storage::disk('public')->get($organization->logo_path);
            $mime = Storage::disk('public')->mimeType($organization->logo_path) ?: 'image/png';
            $logoDataUri = 'data:'.$mime.';base64,'.base64_encode($bytes);
        }

        $mapImage = null;
        if (! empty($capture['image']) && str_starts_with($capture['image'], 'data:image/')) {
            $mapImage = $capture['image'];
        }

        $legend = $capture['legend'] ?? null;
        if (! is_array($legend) || $legend === []) {
            $legend = collect(is_array($map->layers) ? $map->layers : [])
                ->take(8)
                ->values()
                ->map(function ($layerCfg, $i) {
                    $colors = ['#3388ff', '#e74c3c', '#2ecc71', '#f39c12'];

                    return [
                        'label' => $layerCfg['name'] ?? ($layerCfg['title'] ?? 'Layer '.($i + 1)),
                        'color' => $layerCfg['color']
                            ?? ($layerCfg['style_config']['symbol']['fill_color'] ?? null)
                            ?? $colors[$i % 4],
                    ];
                })
                ->all();
        }

        return [
            'map' => $map,
            'organization' => $organization,
            'printedAt' => now(),
            'mapImage' => $mapImage,
            'legend' => $legend,
            'scaleDenominator' => $capture['scaleDenominator'] ?? null,
            'printTitle' => $capture['title'] ?? $map->name,
            'primaryColor' => $organization?->primary_color ?: '#0f766e',
            'logoDataUri' => $logoDataUri,
        ];
    }

    protected function authorizeMap(Map $map): void
    {
        if ($map->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        if (app(\App\Services\ContentAccessService::class)->canView(
            Auth::user(),
            'map',
            $map->id,
            $map->organization_id
        ) === false) {
            abort(403);
        }
    }
}
