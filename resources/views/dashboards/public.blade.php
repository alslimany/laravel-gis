<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $dashboard->name }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.bunny.net/css?family=ibm-plex-sans:400,500,600" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        body { font-family: "IBM Plex Sans", ui-sans-serif, system-ui, sans-serif; background: #eef2f6; color: #0b1220; }
        .card { border: 1px solid #dce3ec; border-radius: 0.85rem; box-shadow: none; }
        .card-header { background: #fff; font-weight: 600; }
        .text-muted { color: #3f4c5e !important; }
    </style>
</head>
<body class="bg-light">
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
                        @elseif($widget['type'] === 'kpi')
                            <div class="display-6">{{ $widget['value'] ?? '—' }}</div>
                        @elseif(in_array($widget['type'], ['bar', 'pie'], true))
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
    widgets.forEach(function (widget) {
        if (!['bar', 'pie'].includes(widget.type) || widget.error) return;
        const el = document.getElementById('chart-' + widget.index);
        if (!el) return;
        new Chart(el, {
            type: widget.type === 'pie' ? 'pie' : 'bar',
            data: {
                labels: widget.labels || [],
                datasets: [{
                    label: widget.title,
                    data: widget.values || [],
                    backgroundColor: ['#3388ff','#e74c3c','#2ecc71','#f39c12','#9b59b6','#1abc9c']
                }]
            },
            options: { responsive: true, plugins: { legend: { display: widget.type === 'pie' } } }
        });
    });
});
</script>
</body>
</html>
