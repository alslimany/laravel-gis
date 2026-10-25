<?php

namespace Lumina\EarlyWarning\Http\Controllers;

use App\Plugins\PluginManager;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\CitizenReport;
use Lumina\EarlyWarning\Models\PublicToken;
use Lumina\EarlyWarning\Models\RiskAssessment;
use Lumina\EarlyWarning\Models\Subscription;
use Lumina\EarlyWarning\Support\AdvisoryCopy;

/** Token-gated public alert feed + report map (ADR §10 Phase D). */
class PublicFeedController extends Controller
{
    public function show(string $token): Response
    {
        $access = $this->resolve($token, 'feed');

        return Inertia::render('EarlyWarning/Public', [
            ...AdvisoryCopy::forUi(),
            'token' => $token,
            'feed_url' => route('early-warning.public.feed', ['token' => $token]),
            'report_url' => route('early-warning.public.reports', ['token' => $token]),
            'can_report' => $access->allows('report'),
            ...$this->payload($access),
        ]);
    }

    public function feed(string $token): JsonResponse
    {
        $access = $this->resolve($token, 'feed');

        return response()->json([
            'plugin' => 'early-warning',
            ...AdvisoryCopy::forUi(),
            'generated_at' => now()->utc()->toIso8601String(),
            ...$this->payload($access),
        ]);
    }

    public function storeReport(Request $request, string $token): JsonResponse
    {
        $access = $this->resolve($token, 'report');

        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:2000'],
            'basin_code' => ['nullable', 'string', 'max:64'],
            'reporter_contact' => ['nullable', 'string', 'max:120'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store(
                'ew-reports/'.$access->organization_id,
                'public',
            );
        }

        $report = CitizenReport::query()->create([
            'organization_id' => $access->organization_id,
            'basin_code' => $data['basin_code'] ?? null,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'note' => $data['note'] ?? null,
            'photo_path' => $photoPath,
            'status' => 'pending',
            'source' => 'public',
            'reporter_contact' => $data['reporter_contact'] ?? null,
            'reported_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'id' => $report->id,
            'status' => $report->status,
            'advisory' => AdvisoryCopy::reportReceived(),
        ], 201);
    }

    protected function resolve(string $token, string $ability): PublicToken
    {
        $access = PublicToken::findActiveByPlain($token);
        abort_if($access === null, 404);

        $plugins = app(PluginManager::class);
        abort_unless($plugins->isInstalled('early-warning'), 404);
        abort_unless($plugins->isEnabledFor('early-warning', $access->organization_id), 404);
        abort_unless($access->allows($ability), 403);

        $access->touchUsed();

        return $access;
    }

    /**
     * @return array{basins: list<array<string, mixed>>, reports: list<array<string, mixed>>}
     */
    protected function payload(PublicToken $access): array
    {
        $orgId = (int) $access->organization_id;
        $codes = Subscription::query()
            ->where('organization_id', $orgId)
            ->pluck('basin_code');

        if ($codes->isEmpty()) {
            $codes = Basin::query()->orderBy('basin_code')->pluck('basin_code');
        }

        $now = now()->utc();
        $current = RiskAssessment::query()
            ->where('organization_id', $orgId)
            ->whereIn('basin_code', $codes)
            ->where('valid_from', '<=', $now)
            ->where('valid_until', '>', $now)
            ->get()
            ->keyBy('basin_code');

        $basins = Basin::query()
            ->whereIn('basin_code', $codes)
            ->orderBy('basin_code')
            ->get(['basin_code', 'name', 'name_ar', 'area_km2', 'outlet_lat', 'outlet_lon'])
            ->map(function (Basin $basin) use ($current) {
                $risk = $current->get($basin->basin_code);

                return [
                    'basin_code' => $basin->basin_code,
                    'name' => $basin->name,
                    'name_ar' => $basin->name_ar,
                    'area_km2' => $basin->area_km2 !== null ? (float) $basin->area_km2 : null,
                    'outlet_lat' => $basin->outlet_lat !== null ? (float) $basin->outlet_lat : null,
                    'outlet_lon' => $basin->outlet_lon !== null ? (float) $basin->outlet_lon : null,
                    'risk_level' => $risk?->risk_level ?? 'green',
                    'score' => $risk?->score !== null ? (float) $risk->score : null,
                ];
            })
            ->values()
            ->all();

        $reports = CitizenReport::query()
            ->where('organization_id', $orgId)
            ->where('status', 'approved')
            ->where('reported_at', '>=', Carbon::now()->subDays(14))
            ->orderByDesc('reported_at')
            ->limit(100)
            ->get()
            ->map(fn (CitizenReport $r) => [
                'id' => $r->id,
                'latitude' => $r->latitude,
                'longitude' => $r->longitude,
                'note' => $r->note,
                'basin_code' => $r->basin_code,
                'photo_url' => $r->photoUrl(),
                'reported_at' => $r->reported_at?->toIso8601String(),
            ])
            ->all();

        return [
            'basins' => $basins,
            'reports' => $reports,
        ];
    }
}
