<?php

namespace Lumina\Disaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\SitRep;
use Lumina\Disaster\Services\SitRepExporter;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Support\Pdf\PdfRenderer;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

/** PDF-1: Arabic RTL COP / SitRep PDFs rendered by mPDF with an embedded Arabic font. */
class PdfReportsTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
        $this->disasterAdmin->forceFill(['preferred_locale' => 'ar'])->save();
    }

    private function sitrep(array $overrides = []): SitRep
    {
        $incident = Incident::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'external_id' => 'PDF-1',
            'title' => 'غمر طريق بالمياه',
            'status' => 'open',
            'incident_type' => 'power',
            'opened_at' => now(),
            'geom' => GeometryWriter::pointFromCoordinates(32.88, 13.19),
        ]);

        return SitRep::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'incident_id' => $incident->id,
            'summary' => 'تقرير الموقف ١',
            'casualties' => [],
            'resources' => null,
            'lifelines_status' => [],
            'next_steps' => '',
            'prepared_by' => $this->disasterAdmin->id,
            'prepared_at' => now(),
            ...$overrides,
        ]);
    }

    private function assertMpdfWithArabicFont(string $body): void
    {
        $this->assertStringStartsWith('%PDF-', $body);
        // The Producer string is UTF-16 (BOM + NUL bytes) in mPDF output.
        $this->assertMatchesRegularExpression('/\/Producer\s*\(.{0,8}mPDF/is', str_replace("\0", '', $body), 'rendered by mPDF, not dompdf');
        $this->assertStringContainsString('IBMPlexSansArabic', $body, 'Arabic font is embedded');
    }

    public function test_cop_export_is_an_mpdf_pdf_with_embedded_arabic_font(): void
    {
        $response = $this->actingAs($this->disasterAdmin)->get('/x/disaster/cop/export');

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertMpdfWithArabicFont((string) $response->getContent());
    }

    public function test_sitrep_export_is_an_mpdf_pdf(): void
    {
        $sitrep = $this->sitrep();

        $response = $this->actingAs($this->disasterAdmin)
            ->get("/x/disaster/incidents/{$sitrep->incident_id}/sitreps/{$sitrep->id}/export.pdf");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertMpdfWithArabicFont((string) $response->getContent());
    }

    public function test_empty_sitrep_sections_say_la_yujad_never_brackets(): void
    {
        app()->setLocale('ar');
        $sitrep = $this->sitrep();
        $html = PdfRenderer::html('disaster::sitrep', [
            'incident' => $sitrep->incident,
            'sitrep' => $sitrep,
            'payload' => SitRepExporter::forSitRep($sitrep),
        ]);

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertGreaterThanOrEqual(4, substr_count($html, 'لا يوجد'), 'casualties, resources, lifelines, next steps');
        $this->assertStringNotContainsString('[]', $html);
        $this->assertStringNotContainsString('{}', $html);
        $this->assertStringContainsString('تقرير الموقف', $html);
    }

    public function test_structured_sitrep_values_become_labelled_rows(): void
    {
        app()->setLocale('ar');
        $sitrep = $this->sitrep(['casualties' => ['injured' => 12, 'missing' => 1]]);
        $html = PdfRenderer::html('disaster::sitrep', [
            'incident' => $sitrep->incident,
            'sitrep' => $sitrep,
            'payload' => SitRepExporter::forSitRep($sitrep),
        ]);

        $this->assertStringNotContainsString('"injured"', $html);
        $this->assertStringContainsString('<td>12</td>', $html);
    }

    public function test_empty_cop_sections_say_la_yujad_and_incident_type_is_arabic(): void
    {
        app()->setLocale('ar');
        $html = PdfRenderer::html('disaster::cop', [
            'snapshot' => [
                'generated_at' => now()->toIso8601String(),
                'lifelines' => [], 'closures' => [], 'evacuation_orders' => [], 'activations' => [],
                'incidents' => [['id' => 1, 'external_id' => 'X-1', 'title' => 'انقطاع', 'incident_type' => 'power', 'priority' => 2, 'opened_at' => now()->toIso8601String()]],
            ],
            'organization' => $this->disasterOrg,
        ]);

        $this->assertGreaterThanOrEqual(4, substr_count($html, 'لا يوجد'));
        $this->assertStringNotContainsString('[]', $html);
        $this->assertStringContainsString('انقطاع كهرباء', $html);
        $this->assertStringNotContainsString('>Power<', $html);
    }

    public function test_digits_are_set_in_the_latin_face(): void
    {
        $this->assertSame(
            '<body><p>أولوية <span class="pdf-num">3</span> — <span class="pdf-num">13:31</span></p></body>',
            PdfRenderer::wrapDigits('<body><p>أولوية 3 — 13:31</p></body>'),
        );
    }

    public function test_every_seeded_incident_type_has_an_arabic_label(): void
    {
        $ar = json_decode((string) file_get_contents(lang_path('ar.json')), true);
        foreach (['flood', 'rescue', 'power', 'landslide', 'fire', 'medical', 'hazmat', 'traffic', 'other'] as $type) {
            $this->assertArrayHasKey("disaster.incident_type.{$type}", $ar);
        }
    }

    public function test_wrap_digits_skips_style_and_script_bodies(): void
    {
        $html = '<html><head><style>p{margin:12px}</style></head><body><style>.a{width:10px}</style>'
            .'<script>var x = 42;</script><p>عدد 12 و 3:45</p></body></html>';
        $out = PdfRenderer::wrapDigits($html);

        $this->assertStringContainsString('<style>.a{width:10px}</style>', $out);
        $this->assertStringContainsString('<script>var x = 42;</script>', $out);
        $this->assertStringContainsString('<span class="pdf-num">12</span>', $out);
        $this->assertStringContainsString('<span class="pdf-num">3:45</span>', $out);
        $this->assertSame(2, substr_count($out, 'pdf-num'));
    }

    public function test_mpdf_temp_dir_is_under_storage_app(): void
    {
        $config = (new \ReflectionMethod(PdfRenderer::class, 'config'))->invoke(null, true);

        $this->assertSame(storage_path('app/mpdf'), $config['tempDir']);
        $this->assertDirectoryExists(storage_path('app/mpdf'));
    }
}
