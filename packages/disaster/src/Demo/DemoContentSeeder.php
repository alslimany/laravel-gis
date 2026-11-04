<?php

namespace Lumina\Disaster\Demo;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Lumina\Disaster\Models\Activation;
use Lumina\Disaster\Models\AvlPing;
use Lumina\Disaster\Models\AvlUnit;
use Lumina\Disaster\Models\Beneficiary;
use Lumina\Disaster\Models\Closure;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\DamageFormSchema;
use Lumina\Disaster\Models\DamagePoint;
use Lumina\Disaster\Models\EscalationRule;
use Lumina\Disaster\Models\EvacCorridor;
use Lumina\Disaster\Models\EvacuationOrder;
use Lumina\Disaster\Models\EvacuationOrderEvent;
use Lumina\Disaster\Models\EventLog;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\IncidentPlan;
use Lumina\Disaster\Models\IncidentRole;
use Lumina\Disaster\Models\InfrastructureCondition;
use Lumina\Disaster\Models\InventoryItem;
use Lumina\Disaster\Models\LifelineComponent;
use Lumina\Disaster\Models\LifelineStatus;
use Lumina\Disaster\Models\MedicalFacility;
use Lumina\Disaster\Models\MessageTemplate;
use Lumina\Disaster\Models\MissingPerson;
use Lumina\Disaster\Models\OperationalPeriod;
use Lumina\Disaster\Models\PersonReport;
use Lumina\Disaster\Models\PublicToken;
use Lumina\Disaster\Models\ResourceRequest;
use Lumina\Disaster\Models\ResourceType;
use Lumina\Disaster\Models\ShelterCheckin;
use Lumina\Disaster\Models\SitRep;
use Lumina\Disaster\Models\StagingArea;
use Lumina\Disaster\Models\Task;
use Lumina\Disaster\Models\Volunteer;
use Lumina\Disaster\Models\Warehouse;
use Lumina\Disaster\Models\WarningAck;
use Lumina\Disaster\Models\WarningAuditLog;
use Lumina\Disaster\Models\WarningRecipient;
use Lumina\Disaster\Services\InventoryLedgerService;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\Enums\DamageLevel;
use Lumina\Disaster\Support\Enums\DamageReviewStatus;
use Lumina\Disaster\Support\Enums\EvacuationOrderStatus;
use Lumina\Disaster\Support\Enums\Habitability;
use Lumina\Disaster\Support\Enums\IncidentPlanStatus;
use Lumina\Disaster\Support\Enums\IncidentRoleType;
use Lumina\Disaster\Support\Enums\InfrastructureConditionGrade;
use Lumina\Disaster\Support\Enums\Lifeline;
use Lumina\Disaster\Support\Enums\LifelineComponentStatus;
use Lumina\Disaster\Support\Enums\MessageTemplateStatus;
use Lumina\Disaster\Support\Enums\MissingPersonStatus;
use Lumina\Disaster\Support\Enums\PersonReportType;
use Lumina\Disaster\Support\Enums\ResourceRequestPriority;
use Lumina\Disaster\Support\Enums\ResourceRequestStatus;
use Lumina\Disaster\Support\Enums\ResourceStatus;
use Lumina\Disaster\Support\Enums\ShelterCheckinDirection;
use Lumina\Disaster\Support\Enums\TaskPriority;
use Lumina\Disaster\Support\Enums\TaskStatus;
use Lumina\Disaster\Support\Enums\VolunteerStatus;
use Lumina\Disaster\Support\Enums\WarehouseStatus;
use Lumina\Disaster\Support\GeometryWriter;

/**
 * Idempotent Tripoli coastal flood demo pack for Disaster & EM (prefix DEMO-).
 *
 * @phpstan-type SeedSummary array<string, int|string|null>
 */
final class DemoContentSeeder
{
    private const PREFIX = 'DEMO-';

    /** Tripoli waterfront anchor (lon/lat used via GeometryWriter lat,lon). */
    private const LAT = 32.8872;

    private const LON = 13.1913;

    /**
     * @return SeedSummary
     */
    public function seed(Organization $organization, ?User $actor = null): array
    {
        $orgId = (int) $organization->id;
        $actor ??= User::query()
            ->where('organization_id', $orgId)
            ->orderBy('id')
            ->first();

        if ($actor === null) {
            throw new \RuntimeException("Organization {$orgId} has no users to attribute demo content to.");
        }

        $summary = [
            'organization_id' => $orgId,
            'actor_user_id' => $actor->id,
            'public_token' => null,
        ];

        // Prefer OrganizationDisasterSettings / Hazards UI for org flags.
        // organization_plugins.settings is encrypted:array stored as text (migration 2026_11_01_000000) — leave untouched here.

        $facilities = $this->seedMedicalFacilities($orgId);
        $summary['medical_facilities'] = $facilities;

        $sites = $this->seedCriticalSites($orgId);
        $summary['critical_sites'] = count($sites);

        $summary['staging_areas'] = $this->seedStagingAreas($orgId);
        $summary['evac_corridors'] = $this->seedCorridors($orgId, $sites);
        $summary['infrastructure_conditions'] = $this->seedInfrastructureConditions($orgId, $sites, $actor);
        $summary['escalation_rules'] = $this->seedEscalationRules($orgId);

        $activation = $this->seedActivation($orgId);
        $summary['activations'] = $activation ? 1 : 0;
        $summary['warning_recipients'] = $this->seedWarningRecipients($orgId, $activation, $actor);

        $summary['evacuation_orders'] = $this->seedEvacuationOrder($orgId, $activation, $sites, $actor);
        $incidents = $this->seedIncidents($orgId);
        $summary['incidents'] = count($incidents);

        $units = $this->seedAvl($orgId);
        $summary['avl_units'] = count($units);
        $summary['closures'] = $this->seedClosures($orgId);
        $summary['damage_points'] = $this->seedDamage($orgId, $actor, $incidents[0] ?? null);

        if ($incidents !== []) {
            $summary['ics'] = $this->seedIcs($orgId, $incidents[0], $actor, $units[0] ?? null);
        }

        if (Schema::hasTable('dm_resource_types')) {
            $summary['inventory'] = $this->seedInventory($orgId, $actor, $incidents[0] ?? null);
            $summary['volunteers'] = $this->seedVolunteers($orgId);
        }

        if (Schema::hasTable('dm_lifeline_components')) {
            $summary['lifelines'] = $this->seedLifelines($orgId, $actor, $sites);
        }

        if (Schema::hasTable('dm_message_templates')) {
            $summary['message_templates'] = $this->seedMessageTemplates($orgId, $actor);
        }

        if (Schema::hasTable('dm_beneficiaries')) {
            $summary['registry'] = $this->seedRegistry($orgId, $sites, $actor);
        }

        $token = $this->seedPublicToken($orgId);
        $summary['public_token'] = $token;

        return $summary;
    }

    private function seedMedicalFacilities(int $orgId): int
    {
        $rows = [
            ['facility_id' => self::PREFIX.'MF-TRIPOLI-CENTRAL', 'name' => 'Tripoli Central Hospital', 'bed_capacity' => 420, 'available_beds' => 86, 'trauma_level' => 1, 'lat' => 32.8920, 'lon' => 13.1805],
            ['facility_id' => self::PREFIX.'MF-FIELD-NORTH', 'name' => 'North Field Clinic', 'bed_capacity' => 60, 'available_beds' => 22, 'trauma_level' => 3, 'lat' => 32.9055, 'lon' => 13.2100],
            ['facility_id' => self::PREFIX.'MF-PEDS', 'name' => 'Pediatric Emergency Ward', 'bed_capacity' => 80, 'available_beds' => 31, 'trauma_level' => 2, 'lat' => 32.8750, 'lon' => 13.1950],
        ];

        $count = 0;
        foreach ($rows as $row) {
            MedicalFacility::query()->updateOrCreate(
                ['organization_id' => $orgId, 'facility_id' => $row['facility_id']],
                [
                    'name' => $row['name'],
                    'bed_capacity' => $row['bed_capacity'],
                    'available_beds' => $row['available_beds'],
                    'trauma_level' => $row['trauma_level'],
                    'generator_status' => 'ONLINE',
                    'resource_status' => ResourceStatus::Available->value,
                    'geom' => GeometryWriter::pointFromCoordinates($row['lat'], $row['lon']),
                ],
            );
            $count++;
        }

        return $count;
    }

    /**
     * @return array<string, CriticalSite>
     */
    private function seedCriticalSites(int $orgId): array
    {
        $defs = [
            'shelter-medina' => ['site_id' => self::PREFIX.'SHELTER-MEDINA', 'type' => CriticalSiteType::Shelter, 'name' => 'Medina Evacuation Shelter', 'capacity' => 800, 'lat' => 32.8955, 'lon' => 13.1750],
            'shelter-port' => ['site_id' => self::PREFIX.'SHELTER-PORT', 'type' => CriticalSiteType::Shelter, 'name' => 'Port Community Hall', 'capacity' => 350, 'lat' => 32.9010, 'lon' => 13.2055],
            'dam-wadi' => ['site_id' => self::PREFIX.'DAM-WADI', 'type' => CriticalSiteType::Dam, 'name' => 'Wadi Retention Dam', 'capacity' => null, 'lat' => 32.8400, 'lon' => 13.2500, 'height_m' => 28, 'capacity_mcm' => 12.5],
            'power' => ['site_id' => self::PREFIX.'POWER-WEST', 'type' => CriticalSiteType::PowerPlant, 'name' => 'West Grid Substation', 'capacity' => null, 'lat' => 32.8700, 'lon' => 13.1600],
            'bridge' => ['site_id' => self::PREFIX.'BRIDGE-CORNICHE', 'type' => CriticalSiteType::Bridge, 'name' => 'Corniche Bridge', 'capacity' => null, 'lat' => 32.8980, 'lon' => 13.1880],
            'water' => ['site_id' => self::PREFIX.'WATER-PLANT', 'type' => CriticalSiteType::WaterPlant, 'name' => 'Coastal Water Treatment', 'capacity' => null, 'lat' => 32.9100, 'lon' => 13.1700],
            'fuel' => ['site_id' => self::PREFIX.'FUEL-DEPOT', 'type' => CriticalSiteType::FuelStation, 'name' => 'Emergency Fuel Depot', 'capacity' => null, 'lat' => 32.8600, 'lon' => 13.2200],
            'airport' => ['site_id' => self::PREFIX.'AIRPORT-MITIGA', 'type' => CriticalSiteType::Airport, 'name' => 'Mitiga Staging Apron', 'capacity' => null, 'lat' => 32.8941, 'lon' => 13.2760],
            'telecom' => ['site_id' => self::PREFIX.'TELECOM-TOWER', 'type' => CriticalSiteType::TelecomTower, 'name' => 'Coastal Telecom Mast', 'capacity' => null, 'lat' => 32.8850, 'lon' => 13.2000],
            'depot' => ['site_id' => self::PREFIX.'DEPOT-LOGISTICS', 'type' => CriticalSiteType::SupplyDepot, 'name' => 'City Logistics Depot', 'capacity' => null, 'lat' => 32.8680, 'lon' => 13.1850],
        ];

        $sites = [];
        foreach ($defs as $key => $def) {
            $payload = [
                'type' => $def['type']->value,
                'name' => $def['name'],
                'capacity' => $def['capacity'],
                'remaining_capacity' => $def['capacity'],
                'current_occupancy' => $def['type'] === CriticalSiteType::Shelter ? 120 : 0,
                'notes' => 'Demo coastal flood scenario asset.',
                'geom' => GeometryWriter::pointFromCoordinates($def['lat'], $def['lon']),
            ];
            if (isset($def['height_m'])) {
                $payload['height_m'] = $def['height_m'];
                $payload['capacity_mcm'] = $def['capacity_mcm'];
                $payload['year_built'] = 1986;
            }

            $sites[$key] = CriticalSite::query()->updateOrCreate(
                ['organization_id' => $orgId, 'site_id' => $def['site_id']],
                $payload,
            );
        }

        return $sites;
    }

    private function seedStagingAreas(int $orgId): int
    {
        $poly = [
            'type' => 'Polygon',
            'coordinates' => [[
                [13.1850, 32.8820],
                [13.1920, 32.8820],
                [13.1920, 32.8880],
                [13.1850, 32.8880],
                [13.1850, 32.8820],
            ]],
        ];

        StagingArea::query()->updateOrCreate(
            ['organization_id' => $orgId, 'staging_id' => self::PREFIX.'STAGING-A'],
            [
                'name' => 'Corniche Staging Lot A',
                'max_vehicle_cap' => 40,
                'fuel_availability' => true,
                'manning_agency' => 'Civil Defense',
                'resource_status' => ResourceStatus::Available->value,
                'geom' => GeometryWriter::polygonFromGeoJson($poly),
            ],
        );

        StagingArea::query()->updateOrCreate(
            ['organization_id' => $orgId, 'staging_id' => self::PREFIX.'STAGING-B'],
            [
                'name' => 'University Parking Staging B',
                'max_vehicle_cap' => 25,
                'fuel_availability' => false,
                'manning_agency' => 'Red Crescent',
                'resource_status' => ResourceStatus::Available->value,
                'geom' => GeometryWriter::polygonFromGeoJson([
                    'type' => 'Polygon',
                    'coordinates' => [[
                        [13.2000, 32.8700],
                        [13.2060, 32.8700],
                        [13.2060, 32.8750],
                        [13.2000, 32.8750],
                        [13.2000, 32.8700],
                    ]],
                ]),
            ],
        );

        return 2;
    }

    /**
     * @param  array<string, CriticalSite>  $sites
     */
    private function seedCorridors(int $orgId, array $sites): int
    {
        $shelter = $sites['shelter-medina'] ?? null;

        EvacCorridor::query()->updateOrCreate(
            ['organization_id' => $orgId, 'name' => self::PREFIX.'Corridor North'],
            [
                'shelter_site_id' => $shelter?->id,
                'props' => ['lanes' => 2, 'direction' => 'inbound', 'notes' => 'Primary coastal egress'],
                'geom' => GeometryWriter::lineFromGeoJson([
                    'type' => 'LineString',
                    'coordinates' => [
                        [13.2100, 32.9000],
                        [13.1950, 32.8970],
                        [13.1800, 32.8950],
                    ],
                ]),
            ],
        );

        EvacCorridor::query()->updateOrCreate(
            ['organization_id' => $orgId, 'name' => self::PREFIX.'Corridor Inland'],
            [
                'shelter_site_id' => ($sites['shelter-port'] ?? null)?->id,
                'props' => ['lanes' => 3, 'direction' => 'inbound'],
                'geom' => GeometryWriter::lineFromGeoJson([
                    'type' => 'LineString',
                    'coordinates' => [
                        [13.1700, 32.8800],
                        [13.1850, 32.8850],
                        [13.2000, 32.9000],
                    ],
                ]),
            ],
        );

        return 2;
    }

    /**
     * @param  array<string, CriticalSite>  $sites
     */
    private function seedInfrastructureConditions(int $orgId, array $sites, User $actor): int
    {
        if (! Schema::hasTable('dm_infrastructure_conditions')) {
            return 0;
        }

        $count = 0;
        foreach (['dam-wadi', 'bridge', 'power'] as $key) {
            $site = $sites[$key] ?? null;
            if (! $site) {
                continue;
            }

            InfrastructureCondition::query()->updateOrCreate(
                [
                    'organization_id' => $orgId,
                    'site_id' => $site->id,
                    'last_inspection_at' => now()->subDays(3)->startOfDay(),
                ],
                [
                    'condition_grade' => $key === 'dam-wadi'
                        ? InfrastructureConditionGrade::Fair
                        : InfrastructureConditionGrade::Good,
                    'notes' => 'Demo inspection — '.$site->name,
                    'next_inspection_due' => now()->addDays(30),
                    'inspector' => $actor->name ?? $actor->email,
                    'maintenance_status' => 'scheduled',
                ],
            );
            $count++;
        }

        return $count;
    }

    private function seedEscalationRules(int $orgId): int
    {
        EscalationRule::query()->updateOrCreate(
            ['organization_id' => $orgId, 'sort_order' => 1],
            [
                'timeout_minutes' => 15,
                'next_recipient_role' => 'duty_officer',
                'channel' => 'sms',
            ],
        );
        EscalationRule::query()->updateOrCreate(
            ['organization_id' => $orgId, 'sort_order' => 2],
            [
                'timeout_minutes' => 45,
                'next_recipient_role' => 'incident_commander',
                'channel' => 'email',
            ],
        );

        return 2;
    }

    private function seedActivation(int $orgId): ?Activation
    {
        return Activation::query()->updateOrCreate(
            [
                'organization_id' => $orgId,
                'source' => 'demo',
                'source_alert_id' => self::PREFIX.'ALERT-FLOOD-001',
            ],
            [
                'hazard_type' => 'flood',
                'severity' => 'Severe',
                'urgency' => 'Immediate',
                'certainty' => 'Likely',
                'valid_from' => now()->subHours(2),
                'valid_to' => now()->addHours(18),
                'affected' => [
                    'areas' => ['Tripoli Corniche', 'Medina', 'Port district'],
                    'population_estimate' => 42000,
                ],
                'advisory_text' => 'Coastal flooding expected along Tripoli waterfront. Move to higher ground. Follow corridor guidance to Medina and Port shelters.',
                'is_exercise' => false,
                'status' => 'active',
            ],
        );
    }

    private function seedWarningRecipients(int $orgId, ?Activation $activation, User $actor): int
    {
        if (! $activation || ! Schema::hasTable('dm_warning_recipients')) {
            return 0;
        }

        $recipient = WarningRecipient::query()->updateOrCreate(
            [
                'activation_id' => $activation->id,
                'organization_id' => $orgId,
                'user_id' => $actor->id,
                'channel' => 'console',
            ],
            [
                'role' => 'duty_officer',
            ],
        );

        if (Schema::hasTable('dm_warning_acks') && class_exists(WarningAck::class)) {
            WarningAck::query()->updateOrCreate(
                ['recipient_id' => $recipient->id],
                [
                    'received_at' => now()->subHour(),
                    'acknowledged_at' => now()->subMinutes(40),
                    'response' => 'acknowledged',
                ],
            );
        }

        WarningAuditLog::query()->firstOrCreate(
            [
                'activation_id' => $activation->id,
                'organization_id' => $orgId,
                'event_type' => 'demo_seeded',
            ],
            [
                'payload' => ['note' => 'Demo activation seeded'],
                'created_at' => now(),
            ],
        );

        return 1;
    }

    /**
     * @param  array<string, CriticalSite>  $sites
     */
    private function seedEvacuationOrder(int $orgId, ?Activation $activation, array $sites, User $actor): int
    {
        $shelterIds = array_values(array_filter([
            ($sites['shelter-medina'] ?? null)?->id,
            ($sites['shelter-port'] ?? null)?->id,
        ]));

        $order = EvacuationOrder::query()->updateOrCreate(
            [
                'organization_id' => $orgId,
                'text_en' => self::PREFIX.' Evacuate Tripoli waterfront districts immediately.',
            ],
            [
                'activation_id' => $activation?->id,
                'status' => EvacuationOrderStatus::Issued->value,
                'authored_by' => $actor->id,
                'approved_by' => $actor->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'issued_at' => now()->subHour(),
                'expires_at' => now()->addDay(),
                'is_official' => true,
                'text_ar' => 'إخلاء فوري لمناطق الواجهة البحرية في طرابلس.',
                'estimated_population' => 18000,
                'evacuated_count' => 4200,
                'unaccounted_count' => 310,
                'shelter_site_ids' => $shelterIds,
                'corridor_ids' => EvacCorridor::query()
                    ->where('organization_id', $orgId)
                    ->where('name', 'like', self::PREFIX.'%')
                    ->pluck('id')
                    ->all(),
                'zone_geom' => GeometryWriter::polygonFromGeoJson([
                    'type' => 'Polygon',
                    'coordinates' => [[
                        [13.1650, 32.8750],
                        [13.2200, 32.8750],
                        [13.2200, 32.9100],
                        [13.1650, 32.9100],
                        [13.1650, 32.8750],
                    ]],
                ]),
            ],
        );

        EvacuationOrderEvent::query()->firstOrCreate(
            [
                'organization_id' => $orgId,
                'evacuation_order_id' => $order->id,
                'action' => 'demo_issued',
            ],
            [
                'from_status' => EvacuationOrderStatus::Approved->value,
                'to_status' => EvacuationOrderStatus::Issued->value,
                'user_id' => $actor->id,
                'meta' => ['source' => 'demo'],
                'created_at' => now()->subHour(),
            ],
        );

        return 1;
    }

    /**
     * @return list<Incident>
     */
    private function seedIncidents(int $orgId): array
    {
        $defs = [
            ['external_id' => self::PREFIX.'INC-FLOOD-CORNICHE', 'title' => 'Corniche inundation — Sector 1', 'priority' => 1, 'lat' => 32.8985, 'lon' => 13.1910, 'type' => 'flood'],
            ['external_id' => self::PREFIX.'INC-RESCUE-PORT', 'title' => 'Water rescue — Port basin', 'priority' => 1, 'lat' => 32.9020, 'lon' => 13.2080, 'type' => 'rescue'],
            ['external_id' => self::PREFIX.'INC-POWER-OUTAGE', 'title' => 'West grid partial outage', 'priority' => 2, 'lat' => 32.8705, 'lon' => 13.1605, 'type' => 'infrastructure'],
        ];

        $incidents = [];
        foreach ($defs as $def) {
            $incidents[] = Incident::query()->updateOrCreate(
                ['organization_id' => $orgId, 'external_id' => $def['external_id']],
                [
                    'source' => 'demo',
                    'incident_type' => $def['type'],
                    'priority' => $def['priority'],
                    'status' => 'open',
                    'opened_at' => now()->subHours(3),
                    'title' => $def['title'],
                    'notes' => 'Seeded demo incident for coastal flood exercise.',
                    'geom' => GeometryWriter::pointFromCoordinates($def['lat'], $def['lon']),
                ],
            );
        }

        return $incidents;
    }

    /**
     * @return list<AvlUnit>
     */
    private function seedAvl(int $orgId): array
    {
        $defs = [
            ['unit_id' => self::PREFIX.'ENG-01', 'class' => 'engine', 'lat' => 32.8900, 'lon' => 13.1900],
            ['unit_id' => self::PREFIX.'AMB-07', 'class' => 'ambulance', 'lat' => 32.8860, 'lon' => 13.1850],
            ['unit_id' => self::PREFIX.'BOAT-02', 'class' => 'boat', 'lat' => 32.9015, 'lon' => 13.2070],
            ['unit_id' => self::PREFIX.'CMD-1', 'class' => 'command', 'lat' => 32.8840, 'lon' => 13.1880],
        ];

        $units = [];
        foreach ($defs as $def) {
            $unit = AvlUnit::query()->updateOrCreate(
                ['organization_id' => $orgId, 'unit_id' => $def['unit_id']],
                [
                    'resource_class' => $def['class'],
                    'resource_status' => ResourceStatus::Available->value,
                    'last_ping_at' => now()->subMinutes(2),
                    'speed_kmh' => 12.5,
                    'heading_deg' => 90,
                    'extras' => ['demo' => true],
                    'geom' => GeometryWriter::pointFromCoordinates($def['lat'], $def['lon']),
                ],
            );
            $units[] = $unit;

            AvlPing::query()->create([
                'organization_id' => $orgId,
                'unit_id' => $def['unit_id'],
                'recorded_at' => now()->subMinutes(2),
                'extras' => ['source' => 'demo'],
                'geom' => GeometryWriter::pointFromCoordinates($def['lat'], $def['lon']),
            ]);
        }

        return $units;
    }

    private function seedClosures(int $orgId): int
    {
        Closure::query()->updateOrCreate(
            ['organization_id' => $orgId, 'external_id' => self::PREFIX.'CLOSURE-CORNICHE'],
            [
                'closure_type' => 'road',
                'severity' => 'Severe',
                'valid_from' => now()->subHours(2),
                'valid_to' => now()->addHours(12),
                'source' => 'demo',
                'geom' => GeometryWriter::lineFromGeoJson([
                    'type' => 'LineString',
                    'coordinates' => [
                        [13.1850, 32.8990],
                        [13.2000, 32.9000],
                    ],
                ]),
            ],
        );

        Closure::query()->updateOrCreate(
            ['organization_id' => $orgId, 'external_id' => self::PREFIX.'CLOSURE-BRIDGE'],
            [
                'closure_type' => 'bridge',
                'severity' => 'Moderate',
                'valid_from' => now()->subHour(),
                'valid_to' => now()->addHours(6),
                'source' => 'demo',
                'geom' => GeometryWriter::lineFromGeoJson([
                    'type' => 'LineString',
                    'coordinates' => [
                        [13.1875, 32.8975],
                        [13.1890, 32.8985],
                    ],
                ]),
            ],
        );

        return 2;
    }

    private function seedDamage(int $orgId, User $actor, ?Incident $incident): int
    {
        if (Schema::hasTable('dm_damage_form_schemas')) {
            DamageFormSchema::query()->updateOrCreate(
                ['organization_id' => $orgId, 'name' => self::PREFIX.'Residential Rapid'],
                [
                    'is_default' => true,
                    'schema' => [
                        'fields' => [
                            ['key' => 'structure_type', 'label' => 'Structure type', 'type' => 'select', 'options' => ['residential', 'commercial', 'critical']],
                            ['key' => 'water_depth_cm', 'label' => 'Water depth (cm)', 'type' => 'number'],
                            ['key' => 'access_blocked', 'label' => 'Access blocked', 'type' => 'boolean'],
                        ],
                    ],
                ],
            );
        }

        $points = [
            ['external_id' => self::PREFIX.'DMG-01', 'level' => DamageLevel::Severe, 'lat' => 32.8970, 'lon' => 13.1920, 'notes' => 'Ground floor flooded — Medina block A'],
            ['external_id' => self::PREFIX.'DMG-02', 'level' => DamageLevel::Moderate, 'lat' => 32.8995, 'lon' => 13.1980, 'notes' => 'Shop front damage — Corniche'],
            ['external_id' => self::PREFIX.'DMG-03', 'level' => DamageLevel::Destroyed, 'lat' => 32.9010, 'lon' => 13.2040, 'notes' => 'Collapsed retaining wall — Port'],
            ['external_id' => self::PREFIX.'DMG-04', 'level' => DamageLevel::Minor, 'lat' => 32.8850, 'lon' => 13.1860, 'notes' => 'Debris on roadway'],
        ];

        foreach ($points as $point) {
            $payload = [
                'damage_level' => $point['level']->value,
                'notes' => $point['notes'],
                'assessed_at' => now()->subHours(1),
                'assessed_by' => $actor->id,
                'props' => ['incident_external_id' => $incident?->external_id, 'demo' => true],
                'geom' => GeometryWriter::pointFromCoordinates($point['lat'], $point['lon']),
            ];

            if (Schema::hasColumn('dm_damage_points', 'review_status')) {
                $payload['review_status'] = DamageReviewStatus::Verified->value;
                $payload['form_schema_version'] = '1.0';
                $payload['habitability'] = $point['level'] === DamageLevel::Destroyed
                    ? Habitability::Uninhabitable->value
                    : Habitability::Limited->value;
            }

            DamagePoint::query()->updateOrCreate(
                ['organization_id' => $orgId, 'external_id' => $point['external_id']],
                $payload,
            );
        }

        return count($points);
    }

    /**
     * @param  list<AvlUnit>  $unused
     * @return array<string, int>
     */
    private function seedIcs(int $orgId, Incident $incident, User $actor, ?AvlUnit $unit): array
    {
        $period = OperationalPeriod::query()->updateOrCreate(
            [
                'organization_id' => $orgId,
                'incident_id' => $incident->id,
                'starts_at' => now()->subHours(2)->startOfHour(),
            ],
            [
                'ends_at' => now()->addHours(10),
                'objectives' => 'Protect life; clear Corniche; staff Medina shelter.',
            ],
        );

        $plan = IncidentPlan::query()->updateOrCreate(
            [
                'organization_id' => $orgId,
                'incident_id' => $incident->id,
                'version' => 1,
            ],
            [
                'operational_period_id' => $period->id,
                'objectives' => 'Evacuate waterfront; sustain shelter capacity; restore west grid feeders.',
                'strategies' => 'Use North corridor; boat teams at Port; staging at Lot A.',
                'safety_message' => 'Life jackets required for boat ops. Floodwater contamination PPE.',
                'status' => IncidentPlanStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => now()->subHour(),
            ],
        );

        IncidentRole::query()->updateOrCreate(
            [
                'organization_id' => $orgId,
                'incident_id' => $incident->id,
                'role' => IncidentRoleType::IncidentCommander->value,
            ],
            ['user_id' => $actor->id],
        );
        IncidentRole::query()->updateOrCreate(
            [
                'organization_id' => $orgId,
                'incident_id' => $incident->id,
                'role' => IncidentRoleType::Operations->value,
            ],
            ['user_id' => $actor->id],
        );

        Task::query()->updateOrCreate(
            [
                'organization_id' => $orgId,
                'incident_id' => $incident->id,
                'title' => self::PREFIX.' Clear Corniche westbound',
            ],
            [
                'status' => TaskStatus::Assigned,
                'priority' => TaskPriority::High,
                'assigned_avl_unit_id' => $unit?->id,
                'result' => 'Coordinate with traffic police.',
            ],
        );
        Task::query()->updateOrCreate(
            [
                'organization_id' => $orgId,
                'incident_id' => $incident->id,
                'title' => self::PREFIX.' Shelter intake surge',
            ],
            [
                'status' => TaskStatus::InProgress,
                'priority' => TaskPriority::High,
            ],
        );

        if (! EventLog::query()
            ->where('incident_id', $incident->id)
            ->where('event_type', 'demo_seeded')
            ->exists()) {
            EventLog::record(
                $incident,
                'demo_seeded',
                'Demo ICS package seeded',
                $actor->id,
                ['plan_id' => $plan->id, 'operational_period_id' => $period->id],
            );
        }

        SitRep::query()->updateOrCreate(
            [
                'organization_id' => $orgId,
                'incident_id' => $incident->id,
                'summary' => self::PREFIX.' Corniche flood SitRep #1 — shelters open, corridors active.',
            ],
            [
                'next_steps' => 'Maintain boat patrols; expand Medina capacity; CAP update at 18:00.',
                'prepared_by' => $actor->id,
                'prepared_at' => now()->subMinutes(30),
                'exported_json' => [
                    'EDXLSitRep' => [
                        'incidentID' => $incident->external_id,
                        'summary' => 'Active coastal flood response',
                    ],
                ],
            ],
        );

        return [
            'operational_periods' => 1,
            'plans' => 1,
            'roles' => 2,
            'tasks' => 2,
            'sitreps' => 1,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function seedInventory(int $orgId, User $actor, ?Incident $incident): array
    {
        $types = [
            ['name' => self::PREFIX.'Bottled water', 'category' => 'relief', 'capability' => 'hydration', 'unit' => 'case'],
            ['name' => self::PREFIX.'Blankets', 'category' => 'relief', 'capability' => 'shelter', 'unit' => 'ea'],
            ['name' => self::PREFIX.'Sandbags', 'category' => 'mitigation', 'capability' => 'flood_control', 'unit' => 'pallet'],
            ['name' => self::PREFIX.'First-aid kits', 'category' => 'medical', 'capability' => 'trauma', 'unit' => 'kit'],
        ];

        $typeModels = [];
        foreach ($types as $type) {
            $typeModels[] = ResourceType::query()->updateOrCreate(
                ['organization_id' => $orgId, 'name' => $type['name']],
                $type,
            );
        }

        $warehouse = Warehouse::query()->updateOrCreate(
            ['organization_id' => $orgId, 'name' => self::PREFIX.'Central Relief Warehouse'],
            [
                'status' => WarehouseStatus::Active,
                'manager_user_id' => $actor->id,
                'geom' => GeometryWriter::pointFromCoordinates(32.8685, 13.1855),
            ],
        );

        $ledger = app(InventoryLedgerService::class);
        foreach ($typeModels as $i => $type) {
            $existing = InventoryItem::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('resource_type_id', $type->id)
                ->value('quantity');
            if ((int) $existing === 0) {
                $ledger->stockIn($warehouse, $type->id, 100 + ($i * 40), $actor, 'demo seed');
            }
        }

        $requests = 0;
        if ($incident && isset($typeModels[0])) {
            ResourceRequest::query()->updateOrCreate(
                [
                    'organization_id' => $orgId,
                    'incident_id' => $incident->id,
                    'resource_type_id' => $typeModels[0]->id,
                    'notes' => self::PREFIX.' Water for Medina shelter',
                ],
                [
                    'requester_user_id' => $actor->id,
                    'quantity' => 40,
                    'priority' => ResourceRequestPriority::High->value,
                    'needed_by' => now()->addHours(4),
                    'status' => ResourceRequestStatus::Approved->value,
                ],
            );
            $requests = 1;
        }

        return [
            'resource_types' => count($typeModels),
            'warehouses' => 1,
            'requests' => $requests,
        ];
    }

    private function seedVolunteers(int $orgId): int
    {
        $rows = [
            ['name' => 'Amina Hassan', 'skills' => ['first_aid', 'arabic', 'english']],
            ['name' => 'Omar El-Sharif', 'skills' => ['logistics', 'driving']],
            ['name' => 'Sara Benali', 'skills' => ['shelter_ops', 'registration']],
        ];

        foreach ($rows as $row) {
            Volunteer::query()->updateOrCreate(
                [
                    'organization_id' => $orgId,
                    'name' => self::PREFIX.$row['name'],
                ],
                [
                    'status' => VolunteerStatus::Active,
                    'skills' => $row['skills'],
                    'availability' => 'available',
                    'organization_name' => 'Demo Civil Defense Corps',
                ],
            );
        }

        return count($rows);
    }

    /**
     * @param  array<string, CriticalSite>  $sites
     * @return array<string, int>
     */
    private function seedLifelines(int $orgId, User $actor, array $sites): array
    {
        $defs = [
            [Lifeline::SafetyAndSecurity, 'Civil Defense Sector 1', LifelineComponentStatus::AtRisk],
            [Lifeline::FoodHydrationShelter, 'Medina Shelter Cluster', LifelineComponentStatus::Impacted, 'shelter-medina'],
            [Lifeline::HealthAndMedical, 'Tripoli Central Hospital feed', LifelineComponentStatus::Stable],
            [Lifeline::Energy, 'West Grid Substation', LifelineComponentStatus::Impacted, 'power'],
            [Lifeline::Communications, 'Coastal Telecom Mast', LifelineComponentStatus::AtRisk, 'telecom'],
            [Lifeline::Transportation, 'Corniche Bridge', LifelineComponentStatus::Impacted, 'bridge'],
            [Lifeline::HazardousMaterials, 'Fuel Depot containment', LifelineComponentStatus::Stable, 'fuel'],
            [Lifeline::WaterSystems, 'Coastal Water Treatment', LifelineComponentStatus::AtRisk, 'water'],
        ];

        foreach ($defs as $def) {
            [$lifeline, $name, $status] = $def;
            $siteKey = $def[3] ?? null;
            $site = $siteKey ? ($sites[$siteKey] ?? null) : null;

            $component = LifelineComponent::query()->updateOrCreate(
                [
                    'organization_id' => $orgId,
                    'lifeline' => $lifeline->value,
                    'name' => self::PREFIX.$name,
                ],
                [
                    'linked_asset_type' => $site ? 'critical_site' : null,
                    'linked_asset_id' => $site?->id,
                ],
            );

            LifelineStatus::query()->updateOrCreate(
                [
                    'organization_id' => $orgId,
                    'component_id' => $component->id,
                    'reported_at' => now()->subMinutes(20),
                ],
                [
                    'status' => $status->value,
                    'reason' => 'Demo coastal flood roll-up',
                    'reported_by' => $actor->id,
                ],
            );
        }

        return ['components' => count($defs)];
    }

    private function seedMessageTemplates(int $orgId, User $actor): int
    {
        $templates = [
            ['hazard_type' => 'flood', 'language' => 'en', 'channel' => 'cap', 'headline' => 'Severe coastal flood warning — Tripoli', 'body' => 'Immediate evacuation advised for Tripoli waterfront districts. Proceed to Medina or Port shelters. Avoid Corniche roadway.'],
            ['hazard_type' => 'flood', 'language' => 'ar', 'channel' => 'cap', 'headline' => 'تحذير فيضان ساحلي شديد — طرابلس', 'body' => 'يُنصح بالإخلاء الفوري لمناطق الواجهة البحرية في طرابلس. التوجه إلى ملاجئ المدينة أو الميناء. تجنب طريق الكورنيش.'],
            ['hazard_type' => 'flood', 'language' => 'en', 'channel' => 'sms', 'headline' => null, 'body' => 'FLOOD ALERT Tripoli: Evacuate waterfront NOW. Shelters: Medina & Port. Avoid Corniche.'],
            ['hazard_type' => 'flood', 'language' => 'ar', 'channel' => 'sms', 'headline' => null, 'body' => 'تنبيه فيضان طرابلس: أخلِ الواجهة البحرية الآن. الملاجئ: المدينة والميناء.'],
        ];

        foreach ($templates as $template) {
            MessageTemplate::query()->updateOrCreate(
                [
                    'organization_id' => $orgId,
                    'hazard_type' => $template['hazard_type'],
                    'language' => $template['language'],
                    'channel' => $template['channel'],
                    'headline' => $template['headline'],
                ],
                [
                    'body' => $template['body'],
                    'status' => MessageTemplateStatus::Approved,
                    'approved_by' => $actor->id,
                    'approved_at' => now()->subDay(),
                ],
            );
        }

        return count($templates);
    }

    /**
     * @param  array<string, CriticalSite>  $sites
     * @return array<string, int>
     */
    private function seedRegistry(int $orgId, array $sites, User $actor): array
    {
        $shelter = $sites['shelter-medina'] ?? null;
        if (! $shelter) {
            return ['beneficiaries' => 0];
        }

        $beneficiaries = [];
        foreach ([
            ['HH-1001', 'Ali Mansour', 4],
            ['HH-1002', 'Fatima Saleh', 3],
            ['HH-1003', 'Yousef Karim', 6],
        ] as [$code, $name, $size]) {
            $beneficiaries[] = Beneficiary::query()->updateOrCreate(
                [
                    'organization_id' => $orgId,
                    'household_code' => self::PREFIX.$code,
                ],
                [
                    'household_size' => $size,
                    'shelter_site_id' => $shelter->id,
                    'needs' => ['water', 'blankets'],
                    'contact_ref' => 'ref:'.md5($code),
                    'props' => ['demo_label' => $name],
                    'retained_until' => now()->addYear()->toDateString(),
                ],
            );
        }

        foreach ($beneficiaries as $beneficiary) {
            ShelterCheckin::query()->firstOrCreate(
                [
                    'organization_id' => $orgId,
                    'shelter_site_id' => $shelter->id,
                    'beneficiary_id' => $beneficiary->id,
                    'direction' => ShelterCheckinDirection::CheckIn->value,
                    'checked_at' => now()->subHours(2),
                ],
                [
                    'checked_by' => $actor->id,
                    'headcount' => max(1, (int) $beneficiary->household_size),
                ],
            );
        }

        $missing = MissingPerson::query()->updateOrCreate(
            [
                'organization_id' => $orgId,
                'case_code' => self::PREFIX.'MP-001',
            ],
            [
                'display_name_redacted' => 'K. N.',
                'given_name' => 'Khaled',
                'family_name' => 'Nouri',
                'status' => MissingPersonStatus::Missing,
                'age_years' => 34,
                'last_seen_at' => now()->subHours(5),
                'notes' => 'Last seen near Port basin during surge.',
                'retained_until' => now()->addYear()->toDateString(),
                'geom' => GeometryWriter::pointFromCoordinates(32.9018, 13.2075),
            ],
        );

        PersonReport::query()->firstOrCreate(
            [
                'organization_id' => $orgId,
                'missing_person_id' => $missing->id,
                'report_type' => PersonReportType::Sighting->value,
            ],
            [
                'notes' => 'Possible sighting near Corniche Bridge',
                'reporter_ref' => 'demo-reporter-1',
                'geom' => GeometryWriter::pointFromCoordinates(32.8982, 13.1885),
                'created_at' => now()->subHour(),
            ],
        );

        return [
            'beneficiaries' => count($beneficiaries),
            'checkins' => count($beneficiaries),
            'missing_persons' => 1,
        ];
    }

    private function seedPublicToken(int $orgId): ?string
    {
        $existing = PublicToken::query()
            ->where('organization_id', $orgId)
            ->where('label', self::PREFIX.'Public advisory feed')
            ->whereNull('revoked_at')
            ->first();

        if ($existing) {
            return '(existing — re-issue with --fresh-token if needed)';
        }

        $issued = PublicToken::issue($orgId, self::PREFIX.'Public advisory feed', ['feed', 'cap']);

        return $issued['plain'];
    }
}
