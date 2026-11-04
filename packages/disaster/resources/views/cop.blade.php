@php
    use Lumina\Disaster\Support\Pdf\PdfRenderer;
    use Lumina\Disaster\Support\Pdf\ReportFormat as F;
    $rtl = PdfRenderer::isRtl();
    $none = F::none();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('pdf.cop.title') }} — {{ $organization->name ?? '' }}</title>
    @include('disaster::pdf._style')
</head>
<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    @if (!empty($autoPrint))
        <script>window.onload = function () { window.print(); };</script>
    @endif

    <h1>{{ __('pdf.cop.title') }}</h1>
    <div class="meta">
        {{ __('pdf.organization') }}: {{ F::text($organization->name ?? null) }}<br>
        {{ __('pdf.generated_at') }}: {{ F::date($snapshot['generated_at'] ?? now()) }}<br>
        {{ __('pdf.cop.avl_included') }}: {{ !empty($snapshot['avl_included']) ? __('common.yes') : __('common.no') }}
    </div>

    <h2>{{ __('pdf.cop.lifelines') }}</h2>
    @if (F::isEmpty($snapshot['lifelines'] ?? []))
        <p class="none">{{ $none }}</p>
    @else
        <table>
            <thead><tr><th>{{ __('pdf.cop.lifeline') }}</th><th>{{ __('pdf.status') }}</th><th>{{ __('pdf.cop.components') }}</th></tr></thead>
            <tbody>
                @foreach ($snapshot['lifelines'] as $row)
                    <tr>
                        <td>{{ $row['label'] ?? F::enum($row['lifeline'] ?? null) }}</td>
                        <td>{{ F::enum($row['status'] ?? null) }}</td>
                        <td>{{ (int) ($row['component_count'] ?? 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ __('pdf.cop.incidents') }} ({{ count($snapshot['incidents'] ?? []) }})</h2>
    @if (F::isEmpty($snapshot['incidents'] ?? []))
        <p class="none">{{ $none }}</p>
    @else
        <table>
            <thead><tr><th>{{ __('pdf.id') }}</th><th>{{ __('pdf.title') }}</th><th>{{ __('pdf.type') }}</th><th>{{ __('pdf.priority') }}</th><th>{{ __('pdf.opened_at') }}</th></tr></thead>
            <tbody>
                @foreach ($snapshot['incidents'] as $incident)
                    <tr>
                        <td class="ltr">{{ $incident['external_id'] ?? $incident['id'] }}</td>
                        <td>{{ F::text($incident['title'] ?? null) }}</td>
                        <td>{{ F::enum($incident['incident_type'] ?? null, 'disaster.incident_type') }}</td>
                        <td>{{ F::enum($incident['priority'] ?? null, 'disaster.priority') }}</td>
                        <td>{{ F::date($incident['opened_at'] ?? null) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ __('pdf.cop.closures') }} ({{ count($snapshot['closures'] ?? []) }})</h2>
    @if (F::isEmpty($snapshot['closures'] ?? []))
        <p class="none">{{ $none }}</p>
    @else
        <table>
            <thead><tr><th>{{ __('pdf.id') }}</th><th>{{ __('pdf.type') }}</th><th>{{ __('pdf.severity') }}</th><th>{{ __('pdf.valid_until') }}</th></tr></thead>
            <tbody>
                @foreach ($snapshot['closures'] as $closure)
                    <tr>
                        <td class="ltr">{{ $closure['external_id'] ?? $closure['id'] }}</td>
                        <td>{{ F::enum($closure['closure_type'] ?? null) }}</td>
                        <td>{{ F::enum($closure['severity'] ?? null) }}</td>
                        <td>{{ F::date($closure['valid_to'] ?? null) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ __('pdf.cop.evacuation_orders') }} ({{ count($snapshot['evacuation_orders'] ?? []) }})</h2>
    @if (F::isEmpty($snapshot['evacuation_orders'] ?? []))
        <p class="none">{{ $none }}</p>
    @else
        <table>
            <thead><tr><th>{{ __('pdf.title') }}</th><th>{{ __('pdf.status') }}</th><th>{{ __('pdf.issued_at') }}</th></tr></thead>
            <tbody>
                @foreach ($snapshot['evacuation_orders'] as $order)
                    <tr>
                        <td>{{ F::text($order['title'] ?? null) }}</td>
                        <td>{{ F::enum($order['status'] ?? null, 'disaster.evac_status') }}</td>
                        <td>{{ F::date($order['issued_at'] ?? null) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ __('pdf.cop.activations') }} ({{ count($snapshot['activations'] ?? []) }})</h2>
    @if (F::isEmpty($snapshot['activations'] ?? []))
        <p class="none">{{ $none }}</p>
    @else
        <table>
            <thead><tr><th>{{ __('pdf.cop.hazard') }}</th><th>{{ __('pdf.severity') }}</th><th>{{ __('pdf.status') }}</th></tr></thead>
            <tbody>
                @foreach ($snapshot['activations'] as $activation)
                    <tr>
                        <td>{{ F::enum($activation['hazard_type'] ?? null) }}</td>
                        <td>{{ F::enum($activation['severity'] ?? null) }}</td>
                        <td>{{ F::enum($activation['status'] ?? null) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (!empty($snapshot['avl_included']))
        <h2>{{ __('pdf.cop.avl') }}</h2>
        @php($units = $snapshot['avl']['units'] ?? [])
        @if (F::isEmpty($units))
            <p class="none">{{ $none }}</p>
        @else
            <p>{{ __('pdf.cop.units') }}: {{ count($units) }}</p>
            <table>
                <thead><tr><th>{{ __('pdf.cop.by_class') }}</th><th>{{ __('pdf.count') }}</th></tr></thead>
                <tbody>
                    @forelse (($snapshot['avl']['by_class'] ?? []) as $class => $count)
                        <tr><td>{{ F::enum($class, 'disaster.resource_class') }}</td><td>{{ is_array($count) ? count($count) : $count }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="none">{{ $none }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            <table>
                <thead><tr><th>{{ __('pdf.cop.by_status') }}</th><th>{{ __('pdf.count') }}</th></tr></thead>
                <tbody>
                    @forelse (($snapshot['avl']['by_status'] ?? []) as $status => $count)
                        <tr><td>{{ F::enum($status) }}</td><td>{{ is_array($count) ? count($count) : $count }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="none">{{ $none }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif
    @endif
</body>
</html>
