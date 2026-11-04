@php
    use Lumina\Disaster\Support\Pdf\PdfRenderer;
    use Lumina\Disaster\Support\Pdf\ReportFormat as F;
    $rtl = PdfRenderer::isRtl();
    $none = F::none();
    $edxl = $payload['EDXLSitRep'] ?? [];
    $report = $edxl['report'] ?? [];
    $sections = [
        'pdf.sitrep.casualties' => $report['casualties'] ?? $sitrep->casualties,
        'pdf.sitrep.resources' => $report['resources'] ?? $sitrep->resources,
        'pdf.sitrep.lifelines_status' => $report['lifelinesStatus'] ?? $sitrep->lifelines_status,
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('pdf.sitrep.title') }} — {{ $incident->title }}</title>
    @include('disaster::pdf._style')
</head>
<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    @if (!empty($autoPrint))
        <script>window.onload = function () { window.print(); };</script>
    @endif

    <h1>{{ __('pdf.sitrep.title') }}</h1>
    <div class="meta">
        {{ __('pdf.sitrep.incident') }}: {{ F::text($incident->title) }} (<span class="ltr">{{ $incident->external_id }}</span>)<br>
        {{ __('pdf.status') }}: {{ F::enum($incident->status) }}<br>
        {{ __('pdf.sitrep.prepared') }}: {{ F::date($sitrep->prepared_at) }}
        @if (!empty($edxl['preparedBy']) || $sitrep->preparer)
            — {{ $edxl['preparedBy'] ?? $sitrep->preparer?->name }}
        @endif
    </div>

    <h2>{{ __('pdf.sitrep.summary') }}</h2>
    <p class="block">{{ F::text($report['summary'] ?? $sitrep->summary) }}</p>

    @foreach ($sections as $titleKey => $value)
        <h2>{{ __($titleKey) }}</h2>
        @php($rows = F::rows($value))
        @if ($rows === [])
            <p class="block none">{{ $none }}</p>
        @else
            <table>
                <tbody>
                    @foreach ($rows as [$label, $text])
                        <tr><th style="width: 40%">{{ $label }}</th><td>{{ $text }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    <h2>{{ __('pdf.sitrep.next_steps') }}</h2>
    <p class="block">{{ F::text($report['nextSteps'] ?? $sitrep->next_steps) }}</p>
</body>
</html>
