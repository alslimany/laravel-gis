@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
@endpush

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
                    @else
                        <pre class="small mb-0">{{ json_encode($widget, JSON_PRETTY_PRINT) }}</pre>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            <div class="alert alert-info">No widgets configured.</div>
        </div>
    @endforelse
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const widgets = @json($widgetData);
    widgets.forEach(function (widget) {
        if (!['bar', 'pie'].includes(widget.type) || widget.error) return;
        const el = document.getElementById('chart-' + widget.index);
        if (!el || typeof Chart === 'undefined') return;
        new Chart(el, {
            type: widget.type === 'pie' ? 'pie' : 'bar',
            data: {
                labels: widget.labels || [],
                datasets: [{
                    label: widget.title,
                    data: widget.values || [],
                    backgroundColor: [
                        '#3388ff', '#e74c3c', '#2ecc71', '#f39c12', '#9b59b6',
                        '#1abc9c', '#34495e', '#e67e22', '#16a085', '#c0392b'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: widget.type === 'pie' } }
            }
        });
    });
});
</script>
@endpush
