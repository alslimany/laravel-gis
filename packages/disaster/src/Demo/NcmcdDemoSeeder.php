<?php

namespace Lumina\Disaster\Demo;

use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\IncidentPlan;
use Lumina\Disaster\Models\IncidentRole;
use Lumina\Disaster\Models\InventoryItem;
use Lumina\Disaster\Models\LifelineComponent;
use Lumina\Disaster\Models\LifelineStatus;
use Lumina\Disaster\Models\MedicalFacility;
use Lumina\Disaster\Models\MessageTemplate;
use Lumina\Disaster\Models\MissingPerson;
use Lumina\Disaster\Models\OperationalPeriod;
use Lumina\Disaster\Models\PersonReport;
use Lumina\Disaster\Models\ResourceRequest;
use Lumina\Disaster\Models\ResourceType;
use Lumina\Disaster\Models\ShelterCheckin;
use Lumina\Disaster\Models\SitRep;
use Lumina\Disaster\Models\StagingArea;
use Lumina\Disaster\Models\Task;
use Lumina\Disaster\Models\Volunteer;
use Lumina\Disaster\Models\Warehouse;
use Lumina\Disaster\Models\WarningAck;
use Lumina\Disaster\Models\WarningRecipient;
use Lumina\Disaster\Services\InventoryLedgerService;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\Enums\DamageReviewStatus;
use Lumina\Disaster\Support\Enums\EvacuationOrderStatus;
use Lumina\Disaster\Support\Enums\Habitability;
use Lumina\Disaster\Support\Enums\IncidentPlanStatus;
use Lumina\Disaster\Support\Enums\IncidentRoleType;
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
use Lumina\EarlyWarning\Models\AlertLog;
use Lumina\EarlyWarning\Models\AlertRule;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\Forecast;
use Lumina\EarlyWarning\Models\RiskAssessment;
use Lumina\EarlyWarning\Models\Subscription;

/**
 * Wadi-26 (NCMCD) Arabic flash-flood exercise, per packages/disaster/demo/ncmcd-scenario.md.
 *
 * Creates its own organization, 4 users, roles, plugin enablement and a Tripoli project,
 * then seeds Disaster & Early Warning records with the NCMCD-W26- prefix.
 *
 * Idempotent: every row is keyed on (organization_id, NCMCD-W26- code). No events,
 * notifications, mail or SMS are dispatched; the EW alert rule is stored disabled so
 * the live scheduler never evaluates it against real weather.
 *
 * Exercise clock: seed time = SEED_TIME_IS on the exercise day (spec §2, ~the 04:00
 * flood peak), so the EW risk peak (now → +2h) lines up with the incidents. Steps
 * after SEED_TIME_IS (registry, SitRep, damage, close-out) carry future timestamps.
 */
final class NcmcdDemoSeeder
{
    public const PREFIX = 'NCMCD-W26-';

    public const ORG_NAME = 'المركز الوطني لإدارة الطوارئ والأزمات والكوارث';

    public const ORG_DESCRIPTION = 'تمرين افتراضي — تمرين وادي-26. كل البيانات في هذه الجهة خيالية لغرض العرض فقط.';

    public const FORECAST_SOURCE = 'ncmcd-demo';

    /**
     * Explicit org marker, stored in organization_plugins.settings (encrypted array) for both
     * plugins as demo_key + exercise=true. The org is found by this marker, never by name.
     */
    public const DEMO_KEY = 'ncmcd-w26';

    public const ACTIVATION_SOURCE = 'ncmcd-demo';

    public const EXERCISE_LABEL = 'تمرين افتراضي';

    /** The ONE timeline offset: exercise clock time (HH:MM) that equals the moment of seeding. */
    public const SEED_TIME_IS = '04:00';

    /** Reused confirmed basins (spec §1). Never created by this seeder. */
    public const BASINS = ['TRP-MAJENIN', 'TRP-RABIE', 'TRP-HIRA'];

    /** AR-3 Arabic display names (QA list), written to the global ew_basins.name_ar only. */
    public const BASIN_NAMES_AR = ['TRP-MAJENIN' => 'وادي المجينين', 'TRP-RABIE' => 'وادي الربيع', 'TRP-HIRA' => 'وادي الهيرة'];

    /** AR-2: Arabic names for the 4 config default EW rules (DefaultAlertRules), demo org only. */
    public const DEFAULT_RULE_NAMES_AR = [
        'Heavy 6h rain (yellow)' => 'أمطار غزيرة خلال 6 ساعات (أصفر)',
        'Intense 6h rain (orange)' => 'أمطار شديدة خلال 6 ساعات (برتقالي)',
        'Extreme 6h rain (red)' => 'أمطار شديدة جدًا خلال 6 ساعات (أحمر)',
        'Elevated risk score' => 'ارتفاع مؤشر الخطر',
    ];

    /** Tripoli bbox (lon/lat) covering the three wadi outlets. */
    public const BBOX = [12.95, 32.75, 13.45, 32.95];

    /**
     * key => [email, Arabic name, core role, IncidentRole on INC-01 or null].
     * PM override of spec §5: director+duty = admin, commander = editor, field = viewer.
     */
    public const USERS = [
        'director' => ['admin@ncmcd-demo.test', 'مدير النظام (تمرين)', 'admin', null],
        'duty' => ['duty@ncmcd-demo.test', 'ضابط المناوبة (تمرين)', 'admin', 'operations'],
        'commander' => ['commander@ncmcd-demo.test', 'المسؤول عن البلاغ (تمرين)', 'editor', 'incident_commander'],
        'field' => ['field@ncmcd-demo.test', 'المسؤول الميداني (تمرين)', 'viewer', 'safety'],
    ];

    /** Spec §3.4 uses CAP-style severity; the Closures UI uses minor|moderate|major|critical. */
    public const CLOSURE_SEVERITY_MAP = ['Minor' => 'minor', 'Moderate' => 'moderate', 'Severe' => 'major', 'Extreme' => 'critical'];

    private CarbonInterface $exerciseMidnight;

    /** @var array<string, User> */
    private array $users = [];

    private bool $zoneClipped = false;

    /** @var array<string, string> plain passwords for users created (or reset) in this run */
    private array $credentials = [];

    public function __construct(private readonly PluginManager $plugins) {}

    /**
     * @return array{summary: array<string, mixed>, credentials: array<string, string>}
     */
    public function seed(bool $resetPasswords = false): array
    {
        [$h, $m] = array_map('intval', explode(':', self::SEED_TIME_IS));
        $this->exerciseMidnight = now()->startOfMinute()->subHours($h)->subMinutes($m);
        $this->credentials = [];

        foreach (['disaster', 'early-warning'] as $key) {
            if (! InstalledPlugin::query()->where('plugin_key', $key)->where('status', InstalledPlugin::STATUS_INSTALLED)->exists()) {
                throw new \RuntimeException("Plugin {$key} is not installed.");
            }
        }

        $existing = $this->findMarkedOrganization();
        if ($existing === null && Organization::query()->where('name', self::ORG_NAME)->exists()) {
            throw new \RuntimeException('An organization named "'.self::ORG_NAME.'" already exists without the '
                .self::DEMO_KEY.' demo marker. Refusing to seed into it; nothing was changed.');
        }

        // USER-1: users are matched by email (globally unique). Before ANY write, refuse if a demo
        // email belongs to someone outside the marked org, or exists while that org doesn't yet.
        $emails = array_column(self::USERS, 0);
        $conflicts = User::query()
            ->whereIn('email', $emails)
            ->when($existing !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('organization_id')
                ->orWhere('organization_id', '!=', $existing->id)))
            ->orderBy('email')
            ->pluck('email')
            ->all();
        if ($conflicts !== []) {
            throw new \RuntimeException('Demo email(s) already used by account(s) outside the '.self::DEMO_KEY
                .' demo organization: '.implode(', ', $conflicts).'. Refusing to take them over; nothing was changed.');
        }

        // SAFE-1: the whole seed is one transaction (the encrypted plugin settings are ordinary DB
        // writes, so they are inside it too). Outside it: the plugin cache flush (redone after
        // commit) and the credentials file, which the command writes only after this returns.
        // If anything throws, nothing is kept and no passwords are handed out.
        $credentials = [];
        try {
            $summary = DB::transaction(fn () => $this->seedAll($resetPasswords, $existing));
            $credentials = $this->credentials;
        } finally {
            $this->credentials = [];
            if (isset($this->users['director'])) {
                $this->plugins->forgetOrganization((int) $this->users['director']->organization_id);
            }
        }

        return ['summary' => $summary, 'credentials' => $credentials];
    }

    /** @return array<string, mixed> */
    private function seedAll(bool $resetPasswords, ?Organization $existing): array
    {
        $summary = [];
        $org = (function () use ($resetPasswords, $existing) {
            $director = $this->upsertUser('director', null, $resetPasswords);
            $org = $existing ?? Organization::query()->create(
                ['name' => self::ORG_NAME, 'description' => self::ORG_DESCRIPTION, 'user_id' => $director->id],
            );
            if ($director->organization_id !== $org->id) {
                $director->forceFill(['organization_id' => $org->id])->save();
            }
            foreach (array_keys(self::USERS) as $key) {
                $this->users[$key] = $key === 'director'
                    ? $director->refresh()
                    : $this->upsertUser($key, $org->id, $resetPasswords);
                $this->assignRole($this->users[$key], self::USERS[$key][2], $org->id);
            }

            return $org;
        })();
        $orgId = (int) $org->id;
        $summary['organization_id'] = $orgId;
        $summary['users'] = count($this->users);

        foreach (['disaster', 'early-warning'] as $key) {
            if (! $this->plugins->isEnabledFor($key, $org)) {
                $this->plugins->setEnabled($key, $org, true, $this->users['director']);
            }
            $this->markPluginRow($orgId, $key);
        }
        $this->plugins->forgetOrganization($orgId);

        $summary['project_id'] = $this->seedProject($org)->id;

        $basins = Basin::query()->whereIn('basin_code', self::BASINS)->get()->keyBy('basin_code');
        // AR-3: ew_basins is GLOBAL (no organization_id). Only name_ar is set; the shared English
        // `name` is never changed. This affects every org that shows these basins.
        foreach ($basins as $code => $basin) {
            if ($basin->name_ar !== self::BASIN_NAMES_AR[$code]) {
                $basin->forceFill(['name_ar' => self::BASIN_NAMES_AR[$code]])->save();
            }
        }
        $summary['basins_found'] = $basins->keys()->all();
        $summary['basins_missing'] = array_values(array_diff(self::BASINS, $basins->keys()->all()));

        $ew = $this->seedEarlyWarning($orgId, $basins->keys()->all());
        $summary += $ew['summary'];

        $sites = $this->seedCriticalSites($orgId);
        $summary['medical_facilities'] = $this->seedMedicalFacilities($orgId);
        $summary['critical_sites'] = count($sites);
        $summary['staging_areas'] = $this->seedStagingAreas($orgId);
        $corridor = $this->seedCorridor($orgId, $sites);
        $summary['escalation_rules'] = $this->seedEscalationRules($orgId);

        $activation = $this->seedActivation($orgId, $ew['alert_log_id']);
        $summary['activation_id'] = $activation->id;
        $this->seedWarningAck($orgId, $activation);

        $summary['evacuation_orders'] = $this->seedEvacuationOrder($orgId, $activation, $sites, $corridor);
        $summary['evacuation_zone_clipped'] = $this->zoneClipped;
        $summary['seed_time_is'] = self::SEED_TIME_IS;
        $incidents = $this->seedIncidents($orgId);
        $summary['incidents'] = count($incidents);
        $units = $this->seedAvl($orgId);
        $summary['avl_units'] = count($units);
        $summary['closures'] = $this->seedClosures($orgId);
        $summary['damage_points'] = $this->seedDamage($orgId, $incidents['INC-01']);
        $summary['ics'] = $this->seedIcs($orgId, $incidents['INC-01'], $units['RSC-01']);
        $summary['inventory'] = $this->seedInventory($orgId, $incidents['INC-01'], $sites['SH-01']);
        $summary['volunteers'] = $this->seedVolunteers($orgId);
        $summary['lifelines'] = $this->seedLifelines($orgId, $sites);
        $summary['message_templates'] = $this->seedMessageTemplates($orgId);
        $summary['registry'] = $this->seedRegistry($orgId, $sites);

        return $summary;
    }

    /** The demo org carrying our marker on its disaster plugin row, if any. */
    private function findMarkedOrganization(): ?Organization
    {
        $row = OrganizationPlugin::query()
            ->where('plugin_key', 'disaster')
            ->get()
            ->first(fn (OrganizationPlugin $r) => (($r->settings ?? [])['demo_key'] ?? null) === self::DEMO_KEY);

        return $row ? Organization::query()->find($row->organization_id) : null;
    }

    /** Merge the marker into the plugin row's settings, keeping any other keys. */
    private function markPluginRow(int $orgId, string $pluginKey): void
    {
        $row = OrganizationPlugin::query()->where('organization_id', $orgId)->where('plugin_key', $pluginKey)->firstOrFail();
        $settings = $row->settings ?? [];
        if (($settings['demo_key'] ?? null) === self::DEMO_KEY && ($settings['exercise'] ?? false) === true) {
            return;
        }
        $row->settings = [...$settings, 'demo_key' => self::DEMO_KEY, 'exercise' => true];
        $row->save();
    }

    /** Exercise clock: HH:MM on the exercise day (day -1 = Monday). */
    private function at(string $hm, int $day = 0): CarbonInterface
    {
        [$h, $m] = array_map('intval', explode(':', $hm));

        return $this->exerciseMidnight->copy()->addDays($day)->addHours($h)->addMinutes($m);
    }

    private function upsertUser(string $key, ?int $orgId, bool $resetPassword): User
    {
        [$email, $name] = self::USERS[$key];
        $user = User::query()->where('email', $email)->first();

        if (! $user || $resetPassword) {
            $plain = Str::password(20, symbols: false);
            $this->credentials[$email] = $plain;
            $user ??= new User(['email' => $email]);
            $user->password = Hash::make($plain);
        }

        $user->fill(['name' => $name, 'preferred_locale' => 'ar']);
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()]);
        if ($orgId !== null) {
            $user->forceFill(['organization_id' => $orgId]);
        }
        $user->save();

        return $user;
    }

    private function assignRole(User $user, string $roleName, int $orgId): void
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName], ['description' => ucfirst($roleName)]);

        // One core role per user in this org: drop other demo roles, keep the right one.
        DB::table('role_user')
            ->where('user_id', $user->id)
            ->where('organization_id', $orgId)
            ->where('role_id', '!=', $role->id)
            ->delete();

        $exists = DB::table('role_user')
            ->where(['user_id' => $user->id, 'role_id' => $role->id, 'organization_id' => $orgId])
            ->exists();
        if (! $exists) {
            $user->roles()->attach($role->id, ['organization_id' => $orgId]);
        }
    }

    private function seedProject(Organization $org): Project
    {
        $project = Project::query()->firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'تمرين وادي-26 — طرابلس'],
            [
                'description' => self::EXERCISE_LABEL.' — سيول مفاجئة في أودية المجينين والربيع والهيرة.',
                'user_id' => $this->users['director']->id,
                'is_public' => false,
            ],
        );

        [$w, $s, $e, $n] = self::BBOX;
        $wkt = "POLYGON(({$w} {$s}, {$e} {$s}, {$e} {$n}, {$w} {$n}, {$w} {$s}))";
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::update('UPDATE projects SET bounding_box = ST_GeomFromText(?, 4326) WHERE id = ?', [$wkt, $project->id]);
        } else {
            DB::table('projects')->where('id', $project->id)->update(['bounding_box' => $wkt]);
        }

        if (Schema::hasTable('project_user')) {
            $pivot = ['director' => 'owner', 'duty' => 'editor', 'commander' => 'editor', 'field' => 'viewer'];
            foreach ($pivot as $key => $role) {
                DB::table('project_user')->updateOrInsert(
                    ['project_id' => $project->id, 'user_id' => $this->users[$key]->id],
                    ['role' => $role, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        }

        return $project;
    }

    /**
     * Stored EW warning so the demo never depends on live weather.
     *
     * @param  list<string>  $codes
     * @return array{summary: array<string, int>, alert_log_id: ?int}
     */
    private function seedEarlyWarning(int $orgId, array $codes): array
    {
        foreach ($codes as $code) {
            Subscription::query()->firstOrCreate(['organization_id' => $orgId, 'basin_code' => $code]);
        }

        // Hour offsets from seed time (= SEED_TIME_IS, the 04:00 peak). Spec §2: lower Majenin
        // peaks ~04:00–06:00 and recedes by 09:00; Rabie and Hira overflow less.
        $profile = [
            'TRP-MAJENIN' => [[0, 2, 'red', 0.92, 22.0], [3, 4, 'orange', 0.71, 9.0], [5, 10, 'yellow', 0.45, 3.0], [11, 23, 'green', 0.15, 0.4]],
            'TRP-RABIE' => [[0, 1, 'orange', 0.66, 12.0], [2, 6, 'yellow', 0.41, 4.0], [7, 23, 'green', 0.12, 0.2]],
            'TRP-HIRA' => [[0, 3, 'yellow', 0.38, 6.0], [4, 23, 'green', 0.10, 0.1]],
        ];

        $start = now()->utc()->startOfHour();
        // Replace the previous run's rows. Risk: this marked demo org only (its risk is never
        // recomputed: ComputeRiskJob skips exercise orgs). Forecasts: ew_forecasts is global (no
        // organization_id column), so the delete is scoped by source = ncmcd-demo + our 3 basins.
        // Open-Meteo and any other source are never touched. Today only this seeder writes
        // ncmcd-demo; if a second demo org ever used it, add a props.organization_id filter here.
        RiskAssessment::query()->where('organization_id', $orgId)->whereIn('basin_code', $codes)->delete();
        Forecast::query()->where('source', self::FORECAST_SOURCE)->whereIn('basin_code', $codes)->delete();

        $risk = 0;
        $forecasts = 0;
        foreach ($codes as $code) {
            foreach ($profile[$code] ?? [] as [$from, $to, $level, $score, $mm]) {
                for ($h = $from; $h <= $to; $h++) {
                    $at = $start->copy()->addHours($h);
                    RiskAssessment::query()->create([
                        'organization_id' => $orgId,
                        'basin_code' => $code,
                        'forecast_at' => $at,
                        'valid_from' => $at,
                        'valid_until' => $at->copy()->addHour(),
                        'risk_level' => $level,
                        'score' => $score,
                        'inputs' => ['source' => self::FORECAST_SOURCE, 'exercise' => true, 'precip_mm_6h' => $mm * 6],
                    ]);
                    Forecast::query()->create([
                        'basin_code' => $code,
                        'source' => self::FORECAST_SOURCE,
                        'fetched_at' => now(),
                        'forecast_at' => $at,
                        'precipitation_mm' => $mm,
                        'precipitation_probability' => $level === 'green' ? 20 : 90,
                        'props' => ['organization_id' => $orgId, 'exercise' => true],
                    ]);
                    $risk++;
                    $forecasts++;
                }
            }
        }

        $logId = null;
        $rules = 0;
        if (in_array('TRP-MAJENIN', $codes, true)) {
            // Stored disabled: the live scheduler must never evaluate it against real weather.
            // AR-2: the default rules created on enable get Arabic names and are disabled (demo org only).
            foreach (self::DEFAULT_RULE_NAMES_AR as $en => $ar) {
                AlertRule::query()->where('organization_id', $orgId)->whereIn('name', [$en, $ar])
                    ->update(['name' => $ar, 'enabled' => false, 'updated_at' => now()]);
            }

            $rule = AlertRule::query()->updateOrCreate(
                ['organization_id' => $orgId, 'name' => 'حد التنبيه – أعالي وادي المجينين'],
                [
                    'basin_code' => 'TRP-MAJENIN',
                    'metric' => 'precipitation_mm',
                    'operator' => '>=',
                    'threshold' => 10,
                    'risk_level_min' => 'orange',
                    'channels' => ['console'],
                    'enabled' => false,
                ],
            );
            $rules = AlertRule::query()->where('organization_id', $orgId)->count();

            $log = AlertLog::query()->updateOrCreate(
                ['organization_id' => $orgId, 'alert_rule_id' => $rule->id, 'basin_code' => 'TRP-MAJENIN', 'channel' => 'console'],
                [
                    'status' => 'sent',
                    'sent_at' => $this->at('00:15'),
                    'payload' => [
                        'exercise' => true,
                        'reference' => self::PREFIX.'ALERT-001',
                        'headline' => 'تمرين: تجاوز حد التنبيه في أعالي وادي المجينين',
                        'text' => self::EXERCISE_LABEL.'. تجاوز معدل الهطول حد التنبيه في أعالي حوض وادي المجينين.',
                    ],
                ],
            );
            $logId = $log->id;
        }

        return [
            'summary' => ['risk_assessments' => $risk, 'forecasts' => $forecasts, 'alert_rules' => $rules, 'alert_logs' => $logId ? 1 : 0],
            'alert_log_id' => $logId,
        ];
    }

    private function point(float $lon, float $lat): mixed
    {
        return GeometryWriter::pointFromCoordinates($lat, $lon);
    }

    /** ~100 m square around a lon/lat point (spec §3.3). */
    private function square(float $lon, float $lat, float $d = 0.0005): mixed
    {
        return GeometryWriter::polygonFromGeoJson(['type' => 'Polygon', 'coordinates' => [[
            [$lon - $d, $lat - $d], [$lon + $d, $lat - $d], [$lon + $d, $lat + $d], [$lon - $d, $lat + $d], [$lon - $d, $lat - $d],
        ]]]);
    }

    private function seedMedicalFacilities(int $orgId): int
    {
        $rows = [
            ['MF-01', 'مستشفى عام (افتراضي) ١', 300, 54, 1, 'ONLINE', 13.2000, 32.8600],
            ['MF-02', 'مستشفى ميداني (افتراضي) ١', 40, 40, 3, 'ONLINE', 13.2100, 32.8400],
            ['MF-03', 'مركز إسعاف (افتراضي) – تاجوراء', 25, 12, 3, 'OFFLINE', 13.3600, 32.8650],
        ];
        foreach ($rows as [$id, $name, $beds, $avail, $trauma, $gen, $lon, $lat]) {
            MedicalFacility::query()->updateOrCreate(
                ['organization_id' => $orgId, 'facility_id' => self::PREFIX.$id],
                [
                    'name' => $name,
                    'bed_capacity' => $beds,
                    'available_beds' => $avail,
                    'trauma_level' => $trauma,
                    'generator_status' => $gen,
                    'resource_status' => ResourceStatus::Available->value,
                    'geom' => $this->point($lon, $lat),
                ],
            );
        }

        return count($rows);
    }

    /** @return array<string, CriticalSite> keyed by short code (SH-01 …) */
    private function seedCriticalSites(int $orgId): array
    {
        $defs = [
            ['SH-01', CriticalSiteType::Shelter, 'مركز إيواء (افتراضي) ١ – مدرسة', 600, 13.2150, 32.8300],
            ['SH-02', CriticalSiteType::Shelter, 'مركز إيواء (افتراضي) ٢ – قاعة رياضية', 350, 13.3580, 32.8600],
            ['SH-03', CriticalSiteType::Shelter, 'مركز إيواء (افتراضي) ٣', 200, 13.0420, 32.8200],
            ['BR-01', CriticalSiteType::Bridge, 'جسر على الوادي (افتراضي) ١', null, 13.1880, 32.8800],
            ['PW-01', CriticalSiteType::PowerPlant, 'محطة تحويل كهرباء (افتراضية)', null, 13.1950, 32.8700],
            ['WT-01', CriticalSiteType::WaterPlant, 'محطة ضخ مياه (افتراضية)', null, 13.3640, 32.8740],
            ['DP-01', CriticalSiteType::SupplyDepot, 'المخزن المركزي للإغاثة (افتراضي)', null, 13.2050, 32.8500],
        ];

        $sites = [];
        foreach ($defs as [$code, $type, $name, $cap, $lon, $lat]) {
            $sites[$code] = CriticalSite::query()->updateOrCreate(
                ['organization_id' => $orgId, 'site_id' => self::PREFIX.$code],
                [
                    'type' => $type->value,
                    'name' => $name,
                    'capacity' => $cap,
                    'remaining_capacity' => $cap,
                    'current_occupancy' => 0,
                    'notes' => self::EXERCISE_LABEL.' — منشأة خيالية.',
                    'geom' => $this->point($lon, $lat),
                ],
            );
        }

        return $sites;
    }

    private function seedStagingAreas(int $orgId): int
    {
        $rows = [
            ['STG-A', 'منطقة تجمع (أ) – ساحة مفتوحة', 40, true, 'فريق الدفاع المدني (افتراضي)', 13.1980, 32.8650],
            ['STG-B', 'منطقة تجمع (ب) – تاجوراء', 20, true, 'فريق الهلال الأحمر (افتراضي)', 13.3620, 32.8700],
            ['STG-C', 'منطقة تجمع (ج) – جنزور', 15, false, 'فريق متطوعين (افتراضي)', 13.0400, 32.8250],
        ];
        foreach ($rows as [$id, $name, $cap, $fuel, $agency, $lon, $lat]) {
            StagingArea::query()->updateOrCreate(
                ['organization_id' => $orgId, 'staging_id' => self::PREFIX.$id],
                [
                    'name' => $name,
                    'max_vehicle_cap' => $cap,
                    'fuel_availability' => $fuel,
                    'manning_agency' => $agency,
                    'resource_status' => ResourceStatus::Available->value,
                    'geom' => $this->square($lon, $lat),
                ],
            );
        }

        return count($rows);
    }

    /** @param array<string, CriticalSite> $sites */
    private function seedCorridor(int $orgId, array $sites): EvacCorridor
    {
        // Spec §3.12 names one corridor (north → south) to SH-01; line runs from the lower wadi to the shelter.
        return EvacCorridor::query()->updateOrCreate(
            ['organization_id' => $orgId, 'name' => 'ممر الإخلاء (افتراضي) – شمال ← جنوب'],
            [
                'shelter_site_id' => $sites['SH-01']->id,
                'props' => ['code' => self::PREFIX.'CORRIDOR-01', 'exercise' => true, 'direction' => 'north_to_south'],
                'geom' => GeometryWriter::lineFromGeoJson(['type' => 'LineString', 'coordinates' => [
                    [13.1880, 32.8850], [13.1980, 32.8650], [13.2050, 32.8500], [13.2150, 32.8300],
                ]]),
            ],
        );
    }

    private function seedEscalationRules(int $orgId): int
    {
        EscalationRule::query()->updateOrCreate(
            ['organization_id' => $orgId, 'sort_order' => 1],
            ['timeout_minutes' => 15, 'next_recipient_role' => 'duty_officer', 'channel' => 'sms'],
        );
        EscalationRule::query()->updateOrCreate(
            ['organization_id' => $orgId, 'sort_order' => 2],
            ['timeout_minutes' => 45, 'next_recipient_role' => 'incident_commander', 'channel' => 'email'],
        );

        return 2;
    }

    private function seedActivation(int $orgId, ?int $alertLogId): Activation
    {
        return Activation::query()->updateOrCreate(
            ['organization_id' => $orgId, 'source' => self::ACTIVATION_SOURCE, 'source_alert_id' => self::PREFIX.'ALERT-001'],
            [
                'hazard_type' => 'flood',
                'severity' => 'Severe',
                'urgency' => 'Immediate',
                'certainty' => 'Likely',
                'valid_from' => $this->at('00:15'),
                'valid_to' => $this->at('16:00'),
                'affected' => [
                    'label' => self::EXERCISE_LABEL,
                    'areas' => ['وادي المجينين – الظهرة', 'بن عاشور', 'تاجوراء', 'جنزور'],
                    'basins' => self::BASINS,
                    'population_estimate' => 9500,
                    'ew_alert_log_id' => $alertLogId,
                ],
                'advisory_text' => self::EXERCISE_LABEL.' — سيول متوقعة في وادي المجينين قبل الفجر. ابتعدوا عن مجرى الوادي والجسور، ولا تعبروا الطرق المغمورة بالسيارة، وتابعوا التحديثات من المركز.',
                'is_exercise' => true,
                'status' => 'active',
            ],
        );
    }

    private function seedWarningAck(int $orgId, Activation $activation): void
    {
        if (! Schema::hasTable('dm_warning_recipients')) {
            return;
        }
        $recipient = WarningRecipient::query()->updateOrCreate(
            ['activation_id' => $activation->id, 'organization_id' => $orgId, 'user_id' => $this->users['duty']->id, 'channel' => 'console'],
            ['role' => 'duty_officer'],
        );
        if (Schema::hasTable('dm_warning_acks')) {
            WarningAck::query()->updateOrCreate(
                ['recipient_id' => $recipient->id],
                ['received_at' => $this->at('00:16'), 'acknowledged_at' => $this->at('00:20'), 'response' => 'acknowledged'],
            );
        }
    }

    /** @param array<string, CriticalSite> $sites */
    private function seedEvacuationOrder(int $orgId, Activation $activation, array $sites, EvacCorridor $corridor): int
    {
        $duty = $this->users['duty'];
        $director = $this->users['director'];

        // Two-person rule: duty officer drafts, director approves and issues.
        $order = EvacuationOrder::query()->updateOrCreate(
            // SAFE-4: dm_evacuation_orders has no code column; the order is keyed on its activation,
            // which itself is keyed on the code NCMCD-W26-ALERT-001 (one order per exercise activation).
            ['organization_id' => $orgId, 'activation_id' => $activation->id],
            [
                'text_en' => 'Exercise: evacuate both banks of lower Wadi Al-Majenin.',
                'status' => EvacuationOrderStatus::Issued->value,
                'authored_by' => $duty->id,
                'approved_by' => $director->id,
                'created_by' => $duty->id,
                'updated_by' => $director->id,
                'issued_at' => $this->at('04:00'),
                'expires_at' => $this->at('16:00'),
                'is_official' => false,
                'text_ar' => 'تمرين افتراضي — أمر إخلاء: على سكان ضفتي وادي المجينين بين الظهرة وبن عاشور التوجّه فورًا إلى مركز الإيواء (الافتراضي) ١ عبر الممر المحدد. لا تعبروا الوادي ولا تقتربوا من الجسر.',
                'estimated_population' => 9500,
                'evacuated_count' => 6200,
                'unaccounted_count' => 140,
                'shelter_site_ids' => [$sites['SH-01']->id, $sites['SH-02']->id],
                'corridor_ids' => [$corridor->id],
                // Rectangle from spec §3.12; clipped to TRP-MAJENIN below on PostGIS.
                'zone_geom' => GeometryWriter::polygonFromGeoJson(['type' => 'Polygon', 'coordinates' => [[
                    [13.183, 32.850], [13.205, 32.850], [13.205, 32.890], [13.183, 32.890], [13.183, 32.850],
                ]]]),
            ],
        );

        $this->zoneClipped = $this->clipZoneToBasin((int) $order->id);

        $events = [
            ['ncmcd_drafted', null, EvacuationOrderStatus::Draft, $duty, '03:55'],
            ['ncmcd_approved', EvacuationOrderStatus::Draft, EvacuationOrderStatus::Approved, $director, '03:58'],
            ['ncmcd_issued', EvacuationOrderStatus::Approved, EvacuationOrderStatus::Issued, $director, '04:00'],
        ];
        foreach ($events as [$action, $from, $to, $user, $hm]) {
            EvacuationOrderEvent::query()->firstOrCreate(
                ['organization_id' => $orgId, 'evacuation_order_id' => $order->id, 'action' => $action],
                [
                    'from_status' => $from?->value,
                    'to_status' => $to->value,
                    'user_id' => $user->id,
                    'meta' => ['source' => self::ACTIVATION_SOURCE, 'exercise' => true],
                    'created_at' => $this->at($hm),
                ],
            );
        }

        return 1;
    }

    /**
     * Clip the evacuation zone to the TRP-MAJENIN basin (spec §3.12). PostGIS only; keeps the
     * spec rectangle on SQLite, when the basin geometry is missing, or if the result is empty.
     * Takes the largest polygon part because zone_geom is geometry(Polygon, 4326).
     */
    private function clipZoneToBasin(int $orderId): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql'
            || ! Basin::query()->where('basin_code', 'TRP-MAJENIN')->whereNotNull('geom')->exists()) {
            return false;
        }

        try {
            // Savepoint: on PostgreSQL a failed statement would otherwise abort the outer transaction.
            return DB::transaction(fn () => DB::update(<<<'SQL'
                UPDATE dm_evacuation_orders o
                SET zone_geom = ST_SetSRID(c.g, 4326)
                FROM (
                    SELECT d.g FROM (
                        SELECT (ST_Dump(ST_CollectionExtract(
                            ST_Intersection(ST_MakeValid(b.geom), ST_MakeEnvelope(13.183, 32.850, 13.205, 32.890, 4326)), 3
                        ))).geom AS g
                        FROM ew_basins b WHERE b.basin_code = 'TRP-MAJENIN'
                    ) d
                    WHERE NOT ST_IsEmpty(d.g) AND ST_IsValid(d.g)
                    ORDER BY ST_Area(d.g) DESC
                    LIMIT 1
                ) c
                WHERE o.id = ?
                SQL, [$orderId])) === 1;
        } catch (\Throwable $e) {
            report($e);

            return false; // rectangle stays
        }
    }

    /** @return array<string, Incident> */
    private function seedIncidents(int $orgId): array
    {
        // incident_type is free text (max:64) in IncidentController/IngestIncidentController; spec values kept as-is.
        $defs = [
            ['INC-01', 'غمر طريق بالمياه – الظهرة', 'flood', 1, '03:10', 13.1840, 32.8880],
            ['INC-02', 'مركبة عالقة في مجرى الوادي – بن عاشور', 'rescue', 1, '03:18', 13.1950, 32.8700],
            ['INC-03', 'انقطاع كهرباء جزئي', 'power', 2, '03:40', 13.1950, 32.8700],
            ['INC-04', 'تجمّع مياه حول منازل – تاجوراء', 'flood', 2, '04:05', 13.3655, 32.8780],
            ['INC-05', 'انجراف تربة على طريق فرعي – جنزور', 'landslide', 3, '04:20', 13.0360, 32.8300],
        ];
        $out = [];
        foreach ($defs as [$code, $title, $type, $prio, $hm, $lon, $lat]) {
            $out[$code] = Incident::query()->updateOrCreate(
                ['organization_id' => $orgId, 'external_id' => self::PREFIX.$code],
                [
                    'source' => self::ACTIVATION_SOURCE,
                    'incident_type' => $type,
                    'priority' => $prio,
                    'status' => 'open',
                    'opened_at' => $this->at($hm),
                    'title' => $title,
                    'notes' => self::EXERCISE_LABEL.' — بلاغ خيالي.',
                    'geom' => $this->point($lon, $lat),
                ],
            );
        }

        return $out;
    }

    /** @return array<string, AvlUnit> */
    private function seedAvl(int $orgId): array
    {
        $defs = [
            ['RSC-01', 'rescue', 'فريق إنقاذ نهري ١', 13.1900, 32.8780, 25],
            ['EMS-01', 'ems', 'سيارة إسعاف ١', 13.2000, 32.8600, 40],
            ['EMS-02', 'ems', 'سيارة إسعاف ٢', 13.3620, 32.8700, 0],
            ['LAW-01', 'law', 'دورية تنظيم مرور', 13.1880, 32.8800, 10],
        ];
        $units = [];
        foreach ($defs as [$code, $class, $label, $lon, $lat, $speed]) {
            $unitId = self::PREFIX.$code;
            $units[$code] = AvlUnit::query()->updateOrCreate(
                ['organization_id' => $orgId, 'unit_id' => $unitId],
                [
                    'resource_class' => $class,
                    'resource_status' => ResourceStatus::Available->value,
                    'last_ping_at' => now()->subMinutes(2),
                    'speed_kmh' => $speed,
                    'heading_deg' => 180,
                    'extras' => ['label' => $label, 'exercise' => true],
                    'geom' => $this->point($lon, $lat),
                ],
            );
            // Keep exactly one demo ping per unit across re-runs.
            AvlPing::query()->where('organization_id', $orgId)->where('unit_id', $unitId)->delete();
            AvlPing::query()->create([
                'organization_id' => $orgId,
                'unit_id' => $unitId,
                'recorded_at' => now()->subMinutes(2),
                'extras' => ['source' => self::ACTIVATION_SOURCE],
                'geom' => $this->point($lon, $lat),
            ]);
        }

        return $units;
    }

    private function seedClosures(int $orgId): int
    {
        $defs = [
            ['CL-01', 'bridge', 'Severe', '03:30', '14:00', [[13.1870, 32.8805], [13.1890, 32.8795]]],
            ['CL-02', 'road', 'Severe', '03:35', '12:00', [[13.1940, 32.8705], [13.1960, 32.8695]]],
            ['CL-03', 'road', 'Moderate', '04:10', '10:00', [[13.3650, 32.8778], [13.3660, 32.8770]]],
        ];
        foreach ($defs as [$code, $type, $sev, $from, $to, $line]) {
            Closure::query()->updateOrCreate(
                ['organization_id' => $orgId, 'external_id' => self::PREFIX.$code],
                [
                    'closure_type' => $type,
                    'severity' => self::CLOSURE_SEVERITY_MAP[$sev],
                    'valid_from' => $this->at($from),
                    'valid_to' => $this->at($to),
                    'source' => self::ACTIVATION_SOURCE,
                    'geom' => GeometryWriter::lineFromGeoJson(['type' => 'LineString', 'coordinates' => $line]),
                ],
            );
        }

        return count($defs);
    }

    private function seedDamage(int $orgId, Incident $incident): int
    {
        $field = $this->users['field'];
        if (Schema::hasTable('dm_damage_form_schemas')) {
            DamageFormSchema::query()->updateOrCreate(
                ['organization_id' => $orgId, 'name' => self::PREFIX.'حصر سكني سريع'],
                [
                    'is_default' => true,
                    'schema' => ['fields' => [
                        ['key' => 'structure_type', 'label' => 'نوع المبنى', 'type' => 'select', 'options' => [
                            ['value' => 'residential', 'label' => 'سكني'],
                            ['value' => 'commercial', 'label' => 'تجاري'],
                            ['value' => 'critical', 'label' => 'منشأة حيوية'],
                        ]],
                        ['key' => 'water_depth_cm', 'label' => 'عمق المياه (سم)', 'type' => 'number'],
                        ['key' => 'access_blocked', 'label' => 'هل الطريق مغلق؟', 'type' => 'boolean'],
                        // Spec §3.9; values match the Habitability enum.
                        ['key' => 'habitability', 'label' => 'صلاحية السكن', 'type' => 'select', 'options' => [
                            ['value' => 'habitable', 'label' => 'صالح للسكن'],
                            ['value' => 'limited', 'label' => 'صالح جزئيًا'],
                            ['value' => 'uninhabitable', 'label' => 'غير صالح للسكن'],
                        ]],
                    ]],
                ],
            );
        }

        $points = [
            ['DMG-01', 'severe', 'غمر الطابق الأرضي لعمارة سكنية', 13.1840, 32.8880, Habitability::Limited],
            ['DMG-02', 'destroyed', 'انهيار جدار سور على ضفة الوادي', 13.1880, 32.8800, Habitability::Uninhabitable],
            ['DMG-03', 'moderate', 'تلف محلات تجارية', 13.1950, 32.8700, Habitability::Limited],
            ['DMG-04', 'minor', 'ركام على الطريق', 13.3640, 32.8740, Habitability::Habitable],
            ['DMG-05', 'moderate', 'تشقق في رصيف طريق', 13.0335, 32.8350, Habitability::Habitable],
        ];
        foreach ($points as [$code, $level, $notes, $lon, $lat, $hab]) {
            $payload = [
                'damage_level' => $level,
                'notes' => $notes,
                'assessed_at' => $this->at('12:00'),
                'assessed_by' => $field->id,
                'props' => ['incident_external_id' => $incident->external_id, 'exercise' => true],
                'geom' => $this->point($lon, $lat),
            ];
            if (Schema::hasColumn('dm_damage_points', 'review_status')) {
                $payload['review_status'] = DamageReviewStatus::Verified->value;
                $payload['form_schema_version'] = '1.0';
                $payload['habitability'] = $hab->value;
            }
            DamagePoint::query()->updateOrCreate(['organization_id' => $orgId, 'external_id' => self::PREFIX.$code], $payload);
        }

        return count($points);
    }

    /** @return array<string, int> */
    private function seedIcs(int $orgId, Incident $incident, AvlUnit $unit): array
    {
        $director = $this->users['director'];
        $commander = $this->users['commander'];

        $period = OperationalPeriod::query()->updateOrCreate(
            ['organization_id' => $orgId, 'incident_id' => $incident->id],
            [
                'starts_at' => $this->at('03:45'),
                'ends_at' => $this->at('15:45'),
                'objectives' => 'حماية الأرواح، إخلاء ضفتي المجينين الدنيا، تشغيل مركز الإيواء ١.',
            ],
        );

        // Commander is editor (PM override): the plan approval is recorded under the director (admin).
        IncidentPlan::query()->updateOrCreate(
            ['organization_id' => $orgId, 'incident_id' => $incident->id, 'version' => 1],
            [
                'operational_period_id' => $period->id,
                'objectives' => 'إخلاء ضفتي الوادي بين الظهرة وبن عاشور، وتأمين الجسر والمعابر المغلقة.',
                'strategies' => 'استخدام ممر الإخلاء شمال ← جنوب، وفريق الإنقاذ النهري عند الجسر، والتجمع في المنطقة (أ).',
                'safety_message' => 'تمرين: لا يدخل أحد مجرى الوادي دون سترة نجاة وحبل أمان.',
                'status' => IncidentPlanStatus::Approved,
                'approved_by' => $director->id,
                'approved_at' => $this->at('03:50'),
            ],
        );

        $roles = 0;
        foreach (self::USERS as $key => [, , , $icsRole]) {
            if ($icsRole === null) {
                continue;
            }
            IncidentRole::query()->updateOrCreate(
                ['organization_id' => $orgId, 'incident_id' => $incident->id, 'role' => IncidentRoleType::from($icsRole)->value],
                ['user_id' => $this->users[$key]->id],
            );
            $roles++;
        }

        Task::query()->updateOrCreate(
            ['organization_id' => $orgId, 'incident_id' => $incident->id, 'title' => 'تأمين الجسر وإبعاد المارة'],
            ['status' => TaskStatus::Assigned, 'priority' => TaskPriority::High, 'assigned_avl_unit_id' => $unit->id, 'result' => 'التنسيق مع دورية تنظيم المرور.'],
        );
        Task::query()->updateOrCreate(
            ['organization_id' => $orgId, 'incident_id' => $incident->id, 'title' => 'استقبال الأسر في مركز الإيواء ١'],
            ['status' => TaskStatus::InProgress, 'priority' => TaskPriority::High],
        );

        SitRep::query()->updateOrCreate(
            ['organization_id' => $orgId, 'incident_id' => $incident->id, 'summary' => 'تقرير الموقف ١ — '.self::EXERCISE_LABEL],
            [
                'next_steps' => 'متابعة منسوب الوادي، توسيع الإيواء في المركز ١، تحديث تنبيه CAP عند الانحسار.',
                'prepared_by' => $commander->id,
                'prepared_at' => $this->at('06:00'),
                'exported_json' => ['EDXLSitRep' => ['incidentID' => $incident->external_id, 'summary' => 'تمرين: استجابة لسيل وادي المجينين']],
            ],
        );

        return ['operational_periods' => 1, 'plans' => 1, 'roles' => $roles, 'tasks' => 2, 'sitreps' => 1];
    }

    /** @return array<string, int> */
    private function seedInventory(int $orgId, Incident $incident, CriticalSite $shelter): array
    {
        if (! Schema::hasTable('dm_resource_types')) {
            return [];
        }
        $duty = $this->users['duty'];
        $types = [
            'water' => ['مياه شرب (عبوة)', 'relief', 'hydration', 'عبوة', 1000],
            'blanket' => ['بطانية', 'relief', 'shelter', 'قطعة', 400],
            'sandbag' => ['أكياس رمل', 'mitigation', 'flood_control', 'كيس', 2000],
            'firstaid' => ['حقيبة إسعافات أولية', 'medical', 'trauma', 'حقيبة', 120],
            'mattress' => ['فرشة', 'relief', 'shelter', 'قطعة', 300],
        ];
        $models = [];
        foreach ($types as $key => [$name, $cat, $cap, $unit]) {
            $models[$key] = ResourceType::query()->updateOrCreate(
                ['organization_id' => $orgId, 'name' => $name],
                ['category' => $cat, 'capability' => $cap, 'unit' => $unit],
            );
        }

        $warehouse = Warehouse::query()->updateOrCreate(
            ['organization_id' => $orgId, 'name' => 'المخزن المركزي للإغاثة (افتراضي)'],
            ['status' => WarehouseStatus::Active, 'manager_user_id' => $duty->id, 'geom' => $this->point(13.2050, 32.8500)],
        );

        $ledger = app(InventoryLedgerService::class);
        foreach ($types as $key => $def) {
            $qty = InventoryItem::query()->where('warehouse_id', $warehouse->id)->where('resource_type_id', $models[$key]->id)->value('quantity');
            if ((int) $qty === 0) {
                $ledger->stockIn($warehouse, $models[$key]->id, $def[4], $duty, 'تمرين افتراضي — رصيد افتتاحي للمخزن');
            }
        }

        foreach ([['water', 400, 'مياه لمركز الإيواء ١'], ['blanket', 150, 'بطانيات لمركز الإيواء ١']] as [$key, $qty, $note]) {
            ResourceRequest::query()->updateOrCreate(
                ['organization_id' => $orgId, 'incident_id' => $incident->id, 'resource_type_id' => $models[$key]->id, 'notes' => $note],
                [
                    'requester_user_id' => $duty->id,
                    'quantity' => $qty,
                    'priority' => ResourceRequestPriority::High->value,
                    'needed_by' => $this->at('07:00'),
                    'status' => ResourceRequestStatus::Approved->value,
                ],
            );
        }

        return ['resource_types' => count($models), 'warehouses' => 1, 'requests' => 2, 'shelter_site_id' => $shelter->id];
    }

    private function seedVolunteers(int $orgId): int
    {
        if (! Schema::hasTable('dm_volunteers')) {
            return 0;
        }
        $rows = [
            ['متطوع (افتراضي) – أحمد س.', ['إسعافات أولية'], VolunteerStatus::Deployed, 'ليلي', 'فريق متطوعين (افتراضي)'],
            ['متطوعة (افتراضية) – مريم ع.', ['دعم نفسي', 'تسجيل'], VolunteerStatus::Active, 'نهاري', 'فريق الهلال الأحمر (افتراضي)'],
            ['متطوع (افتراضي) – خالد م.', ['قيادة مركبات رباعية'], VolunteerStatus::Deployed, 'كامل', 'فريق الدفاع المدني (افتراضي)'],
            ['متطوعة (افتراضية) – سلمى ف.', ['توزيع إغاثة'], VolunteerStatus::Active, 'نهاري', 'فريق متطوعين (افتراضي)'],
        ];
        foreach ($rows as [$name, $skills, $status, $avail, $team]) {
            Volunteer::query()->updateOrCreate(
                ['organization_id' => $orgId, 'name' => $name],
                ['status' => $status, 'skills' => $skills, 'availability' => $avail, 'organization_name' => $team],
            );
        }

        return count($rows);
    }

    /**
     * @param  array<string, CriticalSite>  $sites
     * @return array<string, int>
     */
    private function seedLifelines(int $orgId, array $sites): array
    {
        if (! Schema::hasTable('dm_lifeline_components')) {
            return [];
        }
        $defs = [
            [Lifeline::SafetyAndSecurity, 'السلامة والأمن', LifelineComponentStatus::AtRisk, 'إغلاق طرق رئيسية', null],
            [Lifeline::FoodHydrationShelter, 'الغذاء والمأوى', LifelineComponentStatus::AtRisk, 'ضغط على مركز الإيواء ١', 'SH-01'],
            [Lifeline::HealthAndMedical, 'الصحة', LifelineComponentStatus::Stable, 'المستشفى (الافتراضي) ١ يعمل', null],
            [Lifeline::Energy, 'الطاقة', LifelineComponentStatus::Impacted, 'انقطاع جزئي بعد تضرر محطة التحويل', 'PW-01'],
            [Lifeline::Communications, 'الاتصالات', LifelineComponentStatus::Stable, null, null],
            [Lifeline::Transportation, 'النقل', LifelineComponentStatus::Impacted, 'جسر الوادي مغلق', 'BR-01'],
            [Lifeline::HazardousMaterials, 'المواد الخطرة', LifelineComponentStatus::Stable, null, null],
            [Lifeline::WaterSystems, 'المياه', LifelineComponentStatus::AtRisk, 'محطة الضخ (الافتراضية) في منطقة الجريان', 'WT-01'],
        ];
        foreach ($defs as [$lifeline, $name, $status, $reason, $siteKey]) {
            $site = $siteKey ? $sites[$siteKey] : null;
            $component = LifelineComponent::query()->updateOrCreate(
                ['organization_id' => $orgId, 'lifeline' => $lifeline->value, 'name' => $name],
                ['linked_asset_type' => $site ? 'critical_site' : null, 'linked_asset_id' => $site?->id],
            );
            LifelineStatus::query()->updateOrCreate(
                ['organization_id' => $orgId, 'component_id' => $component->id],
                ['status' => $status->value, 'reported_at' => $this->at('05:00'), 'reason' => $reason, 'reported_by' => $this->users['duty']->id],
            );
        }

        return ['components' => count($defs)];
    }

    private function seedMessageTemplates(int $orgId): int
    {
        if (! Schema::hasTable('dm_message_templates')) {
            return 0;
        }
        // CAP severity/urgency/certainty/msgType are chosen at dissemination time; there are no template columns for them.
        $cap = [
            ['تمرين: احتمال سيول في وادي المجينين خلال الساعات القادمة', 'تمرين افتراضي. أمطار غزيرة على أعالي حوض وادي المجينين، ويُتوقّع وصول الجريان إلى طرابلس قبل الفجر. ابتعدوا عن مجرى الوادي والجسور، ولا تعبروا الطرق المغمورة بالسيارة، وتابعوا التحديثات من المركز.'],
            ['تمرين: سيل في وادي المجينين — إخلاء ضفتي الوادي بين الظهرة وبن عاشور', 'تمرين افتراضي. ارتفع منسوب المياه في المجرى الأدنى للوادي، وأُغلق الجسر وعدد من الطرق. غادروا المنازل القريبة من الوادي الآن نحو مركز الإيواء المحدد، واصطحبوا الأدوية والوثائق، وساعدوا كبار السن.'],
            ['تمرين: انحسار السيل في وادي المجينين', 'تمرين افتراضي. انخفض منسوب المياه، وتستمر فرق الحصر في تقييم الأضرار. لا تعودوا إلى المنازل المتضررة قبل فحصها، وأبلغوا عن أي ضرر عبر الرقم المعتمد.'],
        ];
        $sms = [
            'SMS-1' => 'تمرين: احتمال سيل بوادي المجينين الليلة. ابتعدوا عن الوادي والجسور.',
            'SMS-2' => 'تمرين: إخلاء فوري لضفتي وادي المجينين. توجهوا لمركز الإيواء ١.',
            'SMS-3' => 'تمرين: جسر الوادي مغلق. لا تعبروا الطرق المغمورة بالمياه.',
            'SMS-4' => 'تمرين: انحسر السيل. لا تعودوا لمنزل متضرر قبل فحصه.',
        ];
        $rows = [];
        foreach ($cap as [$headline, $body]) {
            $rows[] = ['cap', $headline, $body];
        }
        foreach ($sms as $code => $body) {
            // AR-1: SMS has no headline (null); the row is keyed on its body text.
            $rows[] = ['sms', null, $body];
        }
        foreach ($rows as [$channel, $headline, $body]) {
            MessageTemplate::query()->updateOrCreate(
                ['organization_id' => $orgId, 'hazard_type' => 'flood', 'language' => 'ar', 'channel' => $channel, 'headline' => $headline]
                    + ($headline === null ? ['body' => $body] : []),
                ['body' => $body, 'status' => MessageTemplateStatus::Approved, 'approved_by' => $this->users['director']->id, 'approved_at' => $this->at('22:00', -1)],
            );
        }

        return count($rows);
    }

    /**
     * @param  array<string, CriticalSite>  $sites
     * @return array<string, int>
     */
    private function seedRegistry(int $orgId, array $sites): array
    {
        if (! Schema::hasTable('dm_beneficiaries')) {
            return [];
        }
        $duty = $this->users['duty'];
        $rows = [
            ['HH-001', 5, 'SH-01', ['حليب أطفال'], '05:05'],
            ['HH-002', 3, 'SH-01', ['دواء ضغط'], '05:12'],
            ['HH-003', 7, 'SH-01', ['كرسي متحرك'], '05:20'],
            ['HH-004', 4, 'SH-02', [], '05:40'],
            ['HH-005', 2, 'SH-03', ['بطانيات'], '06:10'],
        ];
        $occupancy = [];
        foreach ($rows as [$code, $size, $siteKey, $needs, $hm]) {
            $shelter = $sites[$siteKey];
            $b = Beneficiary::query()->updateOrCreate(
                ['organization_id' => $orgId, 'household_code' => self::PREFIX.$code],
                [
                    'household_size' => $size,
                    'shelter_site_id' => $shelter->id,
                    'needs' => $needs,
                    'contact_ref' => 'مرجع افتراضي',
                    'props' => ['exercise' => true],
                    'retained_until' => now()->addMonths(3)->toDateString(),
                ],
            );
            ShelterCheckin::query()->updateOrCreate(
                ['organization_id' => $orgId, 'beneficiary_id' => $b->id, 'direction' => ShelterCheckinDirection::CheckIn->value],
                ['shelter_site_id' => $shelter->id, 'checked_at' => $this->at($hm), 'checked_by' => $duty->id, 'headcount' => $size],
            );
            $occupancy[$siteKey] = ($occupancy[$siteKey] ?? 0) + $size;
        }
        foreach ($occupancy as $siteKey => $count) {
            $site = $sites[$siteKey];
            $site->forceFill([
                'current_occupancy' => $count,
                'remaining_capacity' => $site->capacity !== null ? max(0, (int) $site->capacity - $count) : null,
            ])->save();
        }

        $missing = MissingPerson::query()->updateOrCreate(
            ['organization_id' => $orgId, 'case_code' => self::PREFIX.'MP-001'],
            [
                'display_name_redacted' => 'ع. ر.',
                'given_name' => null,
                'family_name' => null,
                'status' => MissingPersonStatus::Missing,
                'age_years' => 67,
                'last_seen_at' => $this->at('03:50'),
                'notes' => self::EXERCISE_LABEL.' — آخر مشاهدة قرب منطقة التجمع (أ).',
                'retained_until' => now()->addMonths(3)->toDateString(),
                'geom' => $this->point(13.1980, 32.8650),
            ],
        );
        PersonReport::query()->firstOrCreate(
            ['organization_id' => $orgId, 'missing_person_id' => $missing->id, 'report_type' => PersonReportType::Sighting->value],
            [
                'notes' => 'تمرين: بلاغ مشاهدة محتملة قرب مركز الإيواء ١.',
                'reporter_ref' => 'مرجع افتراضي',
                'geom' => $this->point(13.2140, 32.8310),
                'created_at' => $this->at('05:30'),
            ],
        );

        return ['beneficiaries' => count($rows), 'checkins' => count($rows), 'missing_persons' => 1];
    }
}
