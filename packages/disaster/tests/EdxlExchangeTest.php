<?php

namespace Lumina\Disaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Lumina\Disaster\Models\ExchangeLog;
use Lumina\Disaster\Models\ExchangeToken;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\MedicalFacility;
use Lumina\Disaster\Models\ResourceRequest;
use Lumina\Disaster\Models\ResourceType;
use Lumina\Disaster\Models\SitRep;
use Lumina\Disaster\Support\Enums\ResourceRequestPriority;
use Lumina\Disaster\Support\Enums\ResourceRequestStatus;
use Lumina\Disaster\Support\Exchange\DistributionElement;
use Lumina\Disaster\Support\Exchange\HaveExporter;
use Lumina\Disaster\Support\Exchange\HaveImporter;
use Lumina\Disaster\Support\Exchange\RmExporter;
use Lumina\Disaster\Support\Exchange\RmImporter;
use Lumina\Disaster\Support\Exchange\SitRepEdxlExporter;
use Lumina\Disaster\Support\Exchange\SitRepEdxlImporter;
use Lumina\Disaster\Support\Exchange\XmlSchemaValidator;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class EdxlExchangeTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected string $plainToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();

        $issued = ExchangeToken::issue($this->disasterOrg->id, 'partner-a', ['*']);
        $this->plainToken = $issued['plain'];
    }

    public function test_have_round_trip_preserves_supported_fields(): void
    {
        $facility = MedicalFacility::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'facility_id' => 'MF-1',
            'name' => 'Central Hospital',
            'bed_capacity' => 100,
            'available_beds' => 42,
            'trauma_level' => 3,
            'generator_status' => 'ONLINE',
            'resource_status' => '01_Available',
            'geom' => GeometryWriter::pointFromCoordinates(32.88, 13.19),
        ]);

        $xml = HaveExporter::toXml(collect([$facility]), $this->disasterOrg->id);
        $validation = XmlSchemaValidator::validate($xml);
        $this->assertTrue($validation['ok'], implode('; ', $validation['errors']));

        MedicalFacility::query()->whereKey($facility->id)->delete();

        $imported = HaveImporter::import($xml, $this->disasterOrg->id);
        $this->assertCount(1, $imported);

        $row = $imported[0];
        $this->assertSame('MF-1', $row->facility_id);
        $this->assertSame('Central Hospital', $row->name);
        $this->assertSame(100, (int) $row->bed_capacity);
        $this->assertSame(42, (int) $row->available_beds);
        $this->assertSame(3, (int) $row->trauma_level);
        $this->assertSame('ONLINE', $row->generator_status);
        $this->assertSame('01_Available', $row->resource_status);
    }

    public function test_rm_round_trip_preserves_supported_fields(): void
    {
        $incident = $this->openIncident('INC-EDXL-RM');
        $type = ResourceType::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'name' => 'Bottled water',
            'category' => 'relief',
            'capability' => 'hydration',
            'unit' => 'case',
        ]);

        $request = ResourceRequest::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'requester_user_id' => $this->disasterAdmin->id,
            'incident_id' => $incident->id,
            'resource_type_id' => $type->id,
            'quantity' => 50,
            'priority' => ResourceRequestPriority::High,
            'needed_by' => Carbon::parse('2026-10-28T18:00:00Z'),
            'status' => ResourceRequestStatus::Requested,
            'notes' => 'Sector 3 staging',
        ]);

        $xml = RmExporter::toXml($request);
        $validation = XmlSchemaValidator::validate($xml);
        $this->assertTrue($validation['ok'], implode('; ', $validation['errors']));
        $this->assertSame('RequestResource', RmExporter::messageContentType($request));

        $request->delete();

        $imported = RmImporter::import($xml, $this->disasterOrg->id, $this->disasterAdmin);
        $this->assertSame(50, (int) $imported->quantity);
        $this->assertSame(ResourceRequestPriority::High, $imported->priority);
        $this->assertSame(ResourceRequestStatus::Requested, $imported->status);
        $this->assertSame('Sector 3 staging', $imported->notes);
        $this->assertSame('Bottled water', $imported->resourceType->name);
        $this->assertSame('relief', $imported->resourceType->category);
        $this->assertSame('hydration', $imported->resourceType->capability);
        $this->assertSame('case', $imported->resourceType->unit);
        $this->assertSame('INC-EDXL-RM', $imported->incident->external_id);
        $this->assertNotNull($imported->needed_by);
    }

    public function test_sitrep_round_trip_preserves_supported_fields(): void
    {
        $incident = $this->openIncident('INC-EDXL-SR');
        $sitrep = SitRep::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'incident_id' => $incident->id,
            'summary' => 'Waters receding',
            'casualties' => ['injured' => 12, 'missing' => 1],
            'resources' => ['water_cases' => 80],
            'lifelines_status' => ['safety_security' => 'stable'],
            'next_steps' => 'Continue pump ops',
            'prepared_by' => $this->disasterAdmin->id,
            'prepared_at' => Carbon::parse('2026-10-28T12:00:00Z'),
        ]);

        $xml = SitRepEdxlExporter::toXml($sitrep);
        $validation = XmlSchemaValidator::validate($xml);
        $this->assertTrue($validation['ok'], implode('; ', $validation['errors']));

        $sitrep->delete();

        $imported = SitRepEdxlImporter::import($xml, $this->disasterOrg->id, $this->disasterAdmin);
        $this->assertSame('Waters receding', $imported->summary);
        $this->assertSame(['injured' => 12, 'missing' => 1], $imported->casualties);
        $this->assertSame(['water_cases' => 80], $imported->resources);
        $this->assertSame(['safety_security' => 'stable'], $imported->lifelines_status);
        $this->assertSame('Continue pump ops', $imported->next_steps);
        $this->assertSame('INC-EDXL-SR', $imported->incident->external_id);
    }

    public function test_distribution_element_wrap_and_unwrap(): void
    {
        $facility = MedicalFacility::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'facility_id' => 'MF-DE',
            'name' => 'DE Hospital',
            'bed_capacity' => 10,
            'available_beds' => 5,
            'generator_status' => 'ONLINE',
        ]);

        $inner = HaveExporter::toXml(collect([$facility]), $this->disasterOrg->id);
        $wrapped = DistributionElement::wrap($inner, 'org:'.$this->disasterOrg->id, [99], 'EDXL-HAVE');
        $validation = XmlSchemaValidator::validate($wrapped);
        $this->assertTrue($validation['ok'], implode('; ', $validation['errors']));

        $unwrapped = DistributionElement::unwrap($wrapped);
        $this->assertSame(['99'], $unwrapped['target_organization_ids']);
        $this->assertSame('EDXL-HAVE', $unwrapped['content_description']);

        $imported = HaveImporter::import($wrapped, $this->disasterOrg->id);
        $this->assertSame('MF-DE', $imported[0]->facility_id);
    }

    public function test_exchange_api_requires_bearer_token(): void
    {
        $this->get('/x/disaster/api/v1/exchange/have')
            ->assertUnauthorized();
    }

    public function test_exchange_api_have_export_and_import(): void
    {
        MedicalFacility::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'facility_id' => 'MF-API',
            'name' => 'API Hospital',
            'bed_capacity' => 20,
            'available_beds' => 8,
            'trauma_level' => 2,
            'generator_status' => 'CRITICAL',
        ]);

        $export = $this->withToken($this->plainToken)
            ->get('/x/disaster/api/v1/exchange/have')
            ->assertOk();

        $this->assertStringContainsString('HospitalStatus', $export->getContent());
        $this->assertSame(1, ExchangeLog::query()->where('direction', 'out')->where('message_type', 'HAVE')->count());

        MedicalFacility::query()->where('facility_id', 'MF-API')->delete();

        $this->call(
            'POST',
            '/x/disaster/api/v1/exchange/have',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/xml',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->plainToken,
            ],
            $export->getContent(),
        )
            ->assertOk()
            ->assertJsonPath('data.0.facility_id', 'MF-API')
            ->assertJsonPath('data.0.available_beds', 8)
            ->assertJsonPath('data.0.generator_status', 'CRITICAL');

        $this->assertSame(1, ExchangeLog::query()->where('direction', 'in')->where('message_type', 'HAVE')->count());
    }

    public function test_exchange_token_ability_gate(): void
    {
        $limited = ExchangeToken::issue($this->disasterOrg->id, 'have-only', ['have.export']);

        $this->withToken($limited['plain'])
            ->post('/x/disaster/api/v1/exchange/have', [], ['CONTENT_TYPE' => 'application/xml'])
            ->assertForbidden();
    }

    public function test_sample_fixtures_validate(): void
    {
        foreach (['have-sample.xml', 'rm-sample.xml', 'sitrep-sample.xml'] as $file) {
            $xml = file_get_contents(dirname(__DIR__).'/docs/fixtures/edxl/'.$file);
            $this->assertNotFalse($xml);
            $result = XmlSchemaValidator::validate($xml);
            $this->assertTrue($result['ok'], $file.': '.implode('; ', $result['errors']));
        }
    }

    protected function openIncident(string $externalId): Incident
    {
        return Incident::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'external_id' => $externalId,
            'source' => 'test',
            'title' => 'Exchange test incident',
            'status' => 'open',
            'priority' => 2,
            'opened_at' => now(),
            'geom' => GeometryWriter::pointFromCoordinates(32.88, 13.19),
        ]);
    }
}
