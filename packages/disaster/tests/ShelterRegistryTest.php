<?php

namespace Lumina\Disaster\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Lumina\Disaster\Models\Beneficiary;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\MissingPerson;
use Lumina\Disaster\Models\PublicToken;
use Lumina\Disaster\Models\RegistryAccessLog;
use Lumina\Disaster\Models\ShelterCheckin;
use Lumina\Disaster\Services\ShelterOccupancyService;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\Enums\RegistryAccessAction;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class ShelterRegistryTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected CriticalSite $shelter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();

        $this->shelter = CriticalSite::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'site_id' => 'SH-REG-1',
            'type' => CriticalSiteType::Shelter->value,
            'name' => 'Central Shelter',
            'capacity' => 10,
            'current_occupancy' => 0,
            'geom' => GeometryWriter::pointFromCoordinates(32.9, 13.2),
        ]);
    }

    public function test_occupancy_stays_consistent_with_checkin_ledger(): void
    {
        $service = app(ShelterOccupancyService::class);

        $service->checkIn($this->shelter, 4, $this->disasterAdmin);
        $service->checkIn($this->shelter->fresh(), 3, $this->disasterAdmin);
        $service->checkOut($this->shelter->fresh(), 2, $this->disasterAdmin);

        $this->shelter->refresh();
        $this->assertSame(5, (int) $this->shelter->current_occupancy);

        $in = (int) ShelterCheckin::query()
            ->where('shelter_site_id', $this->shelter->id)
            ->where('direction', 'check_in')
            ->sum('headcount');
        $out = (int) ShelterCheckin::query()
            ->where('shelter_site_id', $this->shelter->id)
            ->where('direction', 'check_out')
            ->sum('headcount');

        $this->assertSame(5, $in - $out);
        $this->assertSame($in - $out, (int) $this->shelter->current_occupancy);
    }

    public function test_checkin_http_updates_occupancy_and_warns_near_capacity(): void
    {
        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/registry/checkins', [
                'shelter_site_id' => $this->shelter->id,
                'direction' => 'check_in',
                'headcount' => 9,
            ])
            ->assertCreated()
            ->assertJsonPath('data.shelter.current_occupancy', 9)
            ->assertJsonPath('data.capacity_warning', 'approaching');

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/registry/checkins', [
                'shelter_site_id' => $this->shelter->id,
                'direction' => 'check_in',
                'headcount' => 3,
            ])
            ->assertCreated()
            ->assertJsonPath('data.shelter.current_occupancy', 12)
            ->assertJsonPath('data.capacity_warning', 'exceeding');
    }

    public function test_public_endpoints_expose_aggregates_only(): void
    {
        app(ShelterOccupancyService::class)->checkIn($this->shelter, 5, $this->disasterAdmin);

        Beneficiary::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'household_code' => 'HH-SECRET',
            'household_size' => 3,
            'contact_ref' => 'tel-should-never-leak',
        ]);

        MissingPerson::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'case_code' => 'MP-1',
            'status' => 'missing',
            'given_name' => 'Amina',
            'family_name' => 'Hassan',
            'display_name_redacted' => 'A. H.',
        ]);

        $issued = PublicToken::issue($this->disasterOrg->id, 'public');
        $plain = $issued['plain'];

        $feed = $this->getJson('/dm/public/'.$plain.'/feed')->assertOk()->json();
        $this->assertSame(5, $feed['shelter_occupancy']['total_occupancy']);
        $this->assertArrayNotHasKey('beneficiaries', $feed);
        $this->assertArrayNotHasKey('missing_persons', $feed);
        $this->assertStringNotContainsString('Amina', json_encode($feed));
        $this->assertStringNotContainsString('tel-should-never-leak', json_encode($feed));
        $this->assertStringNotContainsString('HH-SECRET', json_encode($feed));

        $occ = $this->getJson('/dm/public/'.$plain.'/shelter-occupancy')->assertOk()->json();
        $this->assertSame(5, $occ['shelter_occupancy']['total_occupancy']);
        $this->assertSame('Central Shelter', $occ['shelter_occupancy']['shelters'][0]['name']);
        $this->assertArrayNotHasKey('given_name', $occ);
        $this->assertArrayNotHasKey('contact_ref', $occ);
    }

    public function test_personal_read_writes_access_log(): void
    {
        $beneficiary = Beneficiary::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'household_code' => 'HH-1',
            'household_size' => 2,
            'contact_ref' => 'opaque-token',
        ]);

        $this->actingAs($this->disasterAdmin)
            ->getJson('/x/disaster/registry/beneficiaries/'.$beneficiary->id)
            ->assertOk()
            ->assertJsonPath('data.household_code', 'HH-1')
            ->assertJsonMissingPath('data.contact_ref')
            ->assertJsonPath('data.has_contact_ref', true);

        $this->assertSame(
            1,
            RegistryAccessLog::query()
                ->where('subject_type', Beneficiary::class)
                ->where('subject_id', $beneficiary->id)
                ->where('action', RegistryAccessAction::Read)
                ->where('user_id', $this->disasterAdmin->id)
                ->count(),
        );
    }

    public function test_retention_command_prunes_expired_rows(): void
    {
        $keep = Beneficiary::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'household_code' => 'KEEP',
            'household_size' => 1,
            'retained_until' => now()->addDays(30)->toDateString(),
        ]);
        Beneficiary::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'household_code' => 'GONE',
            'household_size' => 1,
            'retained_until' => now()->subDay()->toDateString(),
        ]);
        MissingPerson::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'case_code' => 'MP-GONE',
            'status' => 'missing',
            'given_name' => 'Old',
            'family_name' => 'Case',
            'retained_until' => now()->subDays(2)->toDateString(),
        ]);

        $this->artisan('disaster:prune-registry')->assertSuccessful();

        $this->assertTrue(Beneficiary::query()->whereKey($keep->id)->exists());
        $this->assertSame(1, Beneficiary::query()->count());
        $this->assertSame(0, MissingPerson::query()->count());
    }

    public function test_unauthorized_member_denied_registry(): void
    {
        $member = User::factory()->create(['organization_id' => $this->disasterOrg->id]);

        $this->assertFalse(Gate::forUser($member)->allows('disaster.registry'));
        $this->assertTrue(Gate::forUser($this->disasterAdmin)->allows('disaster.registry'));

        $this->actingAs($member)
            ->getJson('/x/disaster/registry/beneficiaries')
            ->assertForbidden();

        $this->actingAs($member)
            ->postJson('/x/disaster/registry/checkins', [
                'shelter_site_id' => $this->shelter->id,
                'direction' => 'check_in',
                'headcount' => 1,
            ])
            ->assertForbidden();
    }

    public function test_matching_suggestions_score_similar_names(): void
    {
        MissingPerson::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'case_code' => 'MP-A',
            'status' => 'missing',
            'given_name' => 'Fatima',
            'family_name' => 'Ali',
            'age_years' => 30,
            'display_name_redacted' => 'F. A.',
        ]);
        MissingPerson::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'case_code' => 'MP-B',
            'status' => 'missing',
            'given_name' => 'Omar',
            'family_name' => 'Saleh',
            'age_years' => 12,
            'display_name_redacted' => 'O. S.',
        ]);

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/registry/missing/match', [
                'given_name' => 'Fatima',
                'family_name' => 'Ali',
                'age_years' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('data.0.case_code', 'MP-A');
    }
}
