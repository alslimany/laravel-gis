<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $dashboard->name }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        /* Same light and dark tokens as resources/css/app.css */
        :root {
            color-scheme: light;
            --background: oklch(0.985 0.004 250);
            --foreground: oklch(0.22 0.025 255);
            --card: oklch(0.995 0.003 250);
            --card-foreground: oklch(0.22 0.025 255);
            --muted-foreground: oklch(0.48 0.02 255);
            --border: oklch(0.90 0.01 250);
            --destructive: oklch(0.55 0.2 25);
            --chart-1: oklch(0.55 0.12 210);
            --chart-2: oklch(0.58 0.11 160);
            --chart-3: oklch(0.55 0.10 280);
            --chart-4: oklch(0.65 0.12 75);
            --chart-5: oklch(0.58 0.14 25);
            --radius: 0.625rem;
        }
        @media (prefers-color-scheme: dark) {
            :root:not(.light) {
                color-scheme: dark;
                --background: oklch(0.17 0.02 255);
                --foreground: oklch(0.93 0.01 250);
                --card: oklch(0.20 0.02 255);
                --card-foreground: oklch(0.93 0.01 250);
                --muted-foreground: oklch(0.70 0.02 250);
                --border: oklch(0.30 0.02 255);
                --destructive: oklch(0.55 0.18 25);
                --chart-1: oklch(0.72 0.11 210);
                --chart-2: oklch(0.72 0.10 160);
                --chart-3: oklch(0.70 0.10 280);
                --chart-4: oklch(0.78 0.11 75);
                --chart-5: oklch(0.72 0.12 25);
            }
        }
        :root.dark {
            color-scheme: dark;
            --background: oklch(0.17 0.02 255);
            --foreground: oklch(0.93 0.01 250);
            --card: oklch(0.20 0.02 255);
            --card-foreground: oklch(0.93 0.01 250);
            --muted-foreground: oklch(0.70 0.02 250);
            --border: oklch(0.30 0.02 255);
            --destructive: oklch(0.55 0.18 25);
            --chart-1: oklch(0.72 0.11 210);
            --chart-2: oklch(0.72 0.10 160);
            --chart-3: oklch(0.70 0.10 280);
            --chart-4: oklch(0.78 0.11 75);
            --chart-5: oklch(0.72 0.12 25);
        }
        body {
            font-family: "Instrument Sans", ui-sans-serif, system-ui, sans-serif;
            background: var(--background);
            color: var(--foreground);
        }
        .card {
            background: var(--card);
            color: var(--card-foreground);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: none;
        }
        .card-header {
            background: var(--card);
            color: var(--card-foreground);
            border-bottom: 1px solid var(--border);
            font-weight: 600;
        }
        .text-muted { color: var(--muted-foreground) !important; }
        .text-danger { color: var(--destructive) !important; }
        .table {
            --bs-table-bg: transparent;
            --bs-table-color: var(--foreground);
            --bs-table-border-color: var(--border);
            color: var(--foreground);
        }
        .alert-info {
            background: var(--card);
            color: var(--foreground);
            border: 1px solid var(--border);
        }
    </style>
</head>
<body>
<div class="container py-4">
    <h1 class="h3">{{ $dashboard->name }}</h1>
    @if($dashboard->description)
        <p class="text-muted">{{ $dashboard->description }}</p>
    @endif

    <div class="row g-3">
        @forelse($widgetData as $widget)
            <div class="col-md-6 col-xl-4">
                <div class="card h-100">
                    <div class="card-header">{{ $widget['title'] }}</div>
                    <div class="card-body">
                        @if($widget['error'])
                            <div class="text-danger small">{{ $widget['error'] }}</div>
                        @elseif(($widget['status'] ?? null) === 'empty')
                            <div class="text-muted small">{{ $widget['message'] }}</div>
                        @elseif(in_array($widget['type'], ['kpi', 'indicator'], true))
                            <div class="display-6">{{ $widget['value'] ?? '—' }}</div>
                        @elseif(in_array($widget['type'], ['bar', 'pie', 'serial', 'line'], true))
                            <canvas id="chart-{{ $widget['index'] }}" height="180"></canvas>
                        @elseif($widget['type'] === 'table')
                            <div class="table-responsive" style="max-height: 260px;">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            @foreach($widget['labels'] as $col)
                                                <th>{{ $col }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($widget['rows'] as $row)
                                            <tr>
                                                @foreach($widget['labels'] as $col)
                                                    <td>{{ is_scalar($row[$col] ?? null) ? $row[$col] : json_encode($row[$col] ?? null) }}</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12"><div class="alert alert-info">No widgets configured.</div></div>
        @endforelse
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const widgets = @json($widgetData);
    const fallbacks = ['oklch(0.55 0.12 210)', 'oklch(0.58 0.11 160)', 'oklch(0.55 0.10 280)', 'oklch(0.65 0.12 75)', 'oklch(0.58 0.14 25)'];
    const styles = getComputedStyle(document.documentElement);
    const token = function (name, fallback) {
        const value = styles.getPropertyValue(name).trim();
        return value || fallback;
    };
    const palette = fallbacks.map(function (color, index) {
        return token('--chart-' + (index + 1), color);
    });
    const copy = token('--foreground', fallbacks[0]);
    const muted = token('--muted-foreground', fallbacks[0]);
    const line = token('--border', 'oklch(0.90 0.01 250)');

    widgets.forEach(function (widget) {
        if (!['bar', 'pie', 'serial', 'line'].includes(widget.type) || widget.error || widget.status === 'empty') return;
        const el = document.getElementById('chart-' + widget.index);
        if (!el) return;
        const style = widget.type === 'pie' ? 'pie' : (widget.type === 'line' || widget.chart_style === 'line' ? 'line' : 'bar');
        new Chart(el, {
            type: style,
            data: {
                labels: widget.labels || [],
                datasets: [{
                    label: widget.title,
                    data: widget.values || [],
                    backgroundColor: style === 'line' ? palette[0] : palette,
                    borderColor: palette[0],
                    borderWidth: style === 'line' ? 2 : 0
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: style === 'pie', labels: { color: copy } } },
                scales: style === 'pie' ? {} : {
                    x: { ticks: { color: muted }, grid: { color: line } },
                    y: { ticks: { color: muted }, grid: { color: line } }
                }
            }
        });
    });
});
</script>
</body>
</html>
