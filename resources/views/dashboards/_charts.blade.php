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
        if (!['bar', 'pie', 'serial', 'line'].includes(widget.type) || widget.error || widget.status === 'empty') return;
        const el = document.getElementById('chart-' + widget.index);
        if (!el || typeof Chart === 'undefined') return;
        const styles = getComputedStyle(document.documentElement);
        const fallbacks = ['oklch(0.55 0.12 210)', 'oklch(0.58 0.11 160)', 'oklch(0.55 0.10 280)', 'oklch(0.65 0.12 75)', 'oklch(0.58 0.14 25)'];
        const palette = fallbacks.map(function (color, index) {
            const value = styles.getPropertyValue('--chart-' + (index + 1)).trim();
            return value || color;
        });
        const copy = styles.getPropertyValue('--foreground').trim() || fallbacks[0];
        const muted = styles.getPropertyValue('--muted-foreground').trim() || fallbacks[0];
        const line = styles.getPropertyValue('--border').trim() || 'oklch(0.90 0.01 250)';
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
@endpush
