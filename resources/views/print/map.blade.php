<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $printTitle ?? $map->name }} — {{ config('print.title_suffix', 'Map Print') }}</title>
    <style>
        @page { margin: 12mm; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #222; margin: 0; }
        .sheet { display: flex; flex-direction: column; min-height: 100vh; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid {{ $primaryColor ?? '#0f766e' }}; padding-bottom: 8px; margin-bottom: 12px; }
        .header h1 { font-size: 20px; margin: 0; color: {{ $primaryColor ?? '#0f766e' }}; }
        .brand { display: flex; align-items: center; gap: 10px; }
        .brand img { max-height: 36px; max-width: 120px; }
        .meta { font-size: 11px; color: #555; }
        .map-frame {
            flex: 1;
            border: 1px solid #999;
            min-height: 420px;
            background: #f3f5f7;
            position: relative;
            overflow: hidden;
        }
        .map-frame img.map-capture { width: 100%; height: auto; display: block; }
        .map-placeholder { color: #666; font-size: 14px; text-align: center; padding: 24px; }
        .north-arrow {
            position: absolute; top: 16px; right: 16px; width: 48px; text-align: center;
            background: rgba(255,255,255,0.9); border: 1px solid #333; padding: 6px 4px; font-size: 11px;
        }
        .north-arrow .arrow { font-size: 22px; line-height: 1; }
        .footer {
            display: flex; justify-content: space-between; gap: 16px;
            margin-top: 12px; border-top: 1px solid #999; padding-top: 10px; font-size: 11px;
        }
        .legend, .scale { border: 1px solid #ccc; padding: 8px 10px; min-width: 160px; }
        .legend h3, .scale h3 { margin: 0 0 6px; font-size: 12px; }
        .legend-item { display: flex; align-items: center; gap: 6px; margin: 3px 0; }
        .swatch { width: 14px; height: 10px; border: 1px solid #333; display: inline-block; }
        .scale-bar { height: 8px; width: 120px; background: repeating-linear-gradient(90deg,#111 0 20px,#fff 20px 40px); border: 1px solid #111; }
        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
@if(!empty($autoPrint))
<script>window.onload = function () { window.print(); };</script>
@endif
<div class="sheet">
    <div class="header">
        <div class="brand">
            @if(!empty($logoDataUri))
                <img src="{{ $logoDataUri }}" alt="{{ $organization->name ?? 'Organization' }}">
            @endif
            <div>
                <h1>{{ $printTitle ?? $map->name }}</h1>
                <div class="meta">
                    @if($organization)
                        {{ $organization->name }} ·
                    @endif
                    Printed {{ $printedAt->format('Y-m-d H:i') }}
                </div>
            </div>
        </div>
        @if(\Illuminate\Support\Facades\Route::has('maps.print.pdf') && empty($mapImage))
            <div class="meta no-print">
                <a href="{{ route('maps.print.pdf', $map) }}">Download PDF</a>
            </div>
        @endif
    </div>

    <div class="map-frame">
        <div class="north-arrow">
            <div class="arrow">▲</div>
            <div>N</div>
        </div>
        @if(!empty($mapImage))
            <img class="map-capture" src="{{ $mapImage }}" alt="Map">
        @else
            <div class="map-placeholder">
                <strong>Map canvas</strong><br>
                Open Print from the map builder to embed the current view.<br>
                Basemap: {{ $map->basemap ?? 'default' }} ·
                Layers: {{ is_array($map->layers) ? count($map->layers) : 0 }}
            </div>
        @endif
    </div>

    <div class="footer">
        <div class="legend">
            <h3>Legend</h3>
            @forelse(($legend ?? []) as $entry)
                <div class="legend-item">
                    <span class="swatch" style="background: {{ $entry['color'] ?? '#0f766e' }}"></span>
                    {{ $entry['label'] ?? 'Layer' }}
                </div>
            @empty
                <div class="legend-item"><span class="swatch" style="background:#0f766e"></span> No layers</div>
            @endforelse
        </div>
        <div class="scale">
            <h3>Scale</h3>
            <div class="scale-bar"></div>
            @if(!empty($scaleDenominator))
                <div style="margin-top:4px;">1 : {{ number_format($scaleDenominator) }}</div>
            @else
                <div style="margin-top:4px;">Scale unavailable</div>
            @endif
            @if(!empty($map->viewport['zoom']))
                <div>Zoom: {{ $map->viewport['zoom'] }}</div>
            @endif
        </div>
    </div>
</div>
</body>
</html>
