@php($rtl = \Lumina\Disaster\Support\Pdf\PdfRenderer::isRtl())
<style>
    body { font-family: {{ $pdfFont ?? 'plexarabic' }}, 'IBM Plex Sans Arabic', 'DejaVu Sans', sans-serif; font-size: 10.5pt; color: #111; }
    h1 { font-size: 17pt; margin: 0 0 2pt; }
    h2 { font-size: 12pt; margin: 14pt 0 5pt; border-bottom: 0.6pt solid #bbb; padding-bottom: 2pt; }
    .meta { color: #555; margin-bottom: 10pt; line-height: 1.6; }
    .block { margin: 0 0 6pt; line-height: 1.7; }
    .none { color: #777; }
    table { width: 100%; border-collapse: collapse; margin-top: 3pt; }
    th, td { border: 0.5pt solid #ccc; padding: 3pt 5pt; text-align: {{ $rtl ? 'right' : 'left' }}; vertical-align: top; }
    th { background: #f1f1f1; font-weight: bold; }
    .ltr { direction: ltr; }
    .pdf-num { font-family: dejavusans; }
    .swatch { width: 8pt; height: 8pt; }
    .green { background: #16a34a; } .amber { background: #d97706; } .red { background: #dc2626; } .gray { background: #9ca3af; }
</style>
