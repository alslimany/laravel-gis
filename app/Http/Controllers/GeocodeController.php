<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GeocodeController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('organization');
    }

    /**
     * Proxy geocode search to Nominatim (or configured provider).
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:500',
        ]);

        $baseUrl = rtrim(config('geocode.url', 'https://nominatim.openstreetmap.org'), '/');
        $userAgent = config('geocode.user_agent', 'GIS-App/1.0');

        try {
            $response = Http::withHeaders([
                'User-Agent' => $userAgent,
                'Accept' => 'application/json',
            ])
                ->timeout(config('geocode.timeout', 10))
                ->get("{$baseUrl}/search", [
                    'q' => $validated['q'],
                    'format' => config('geocode.format', 'json'),
                    'limit' => config('geocode.limit', 10),
                    'addressdetails' => 1,
                ]);

            if (! $response->successful()) {
                return response()->json([
                    'error' => 'Geocoding provider returned an error.',
                    'status' => $response->status(),
                ], 502);
            }

            return response()->json([
                'success' => true,
                'query' => $validated['q'],
                'results' => $response->json() ?? [],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Geocoding request failed.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
