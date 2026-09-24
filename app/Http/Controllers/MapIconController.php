<?php

namespace App\Http\Controllers;

use App\Services\MapIconCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MapIconController extends Controller
{
    public function show(Request $request, string $name): Response
    {
        $color = (string) $request->query('color', '#3388ff');
        if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = '#3388ff';
        }

        $svg = MapIconCatalog::svg($name, $color);
        abort_if($svg === null, 404);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
