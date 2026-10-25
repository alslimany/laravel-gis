<?php

namespace Lumina\EarlyWarning\Http\Controllers;

use App\Models\OrganizationPlugin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\EarlyWarning\Alerts\DefaultAlertRules;
use Lumina\EarlyWarning\Models\AlertLog;
use Lumina\EarlyWarning\Models\AlertRule;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\Subscription;
use Lumina\EarlyWarning\Support\AdvisoryCopy;

class AlertRulesController extends Controller
{
    private const METRICS = [
        'precipitation_mm',
        'precip_1h_mm',
        'precip_6h_mm',
        'precip_24h_mm',
        'risk_score',
    ];

    private const OPERATORS = ['>', '>=', '<', '<=', '=='];

    private const RISK_LEVELS = ['yellow', 'orange', 'red'];

    public function index(Request $request): Response
    {
        $organizationId = (int) $request->user()->organization_id;

        $rules = AlertRule::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get()
            ->map(fn (AlertRule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'basin_code' => $rule->basin_code,
                'metric' => $rule->metric,
                'operator' => $rule->operator,
                'threshold' => (float) $rule->threshold,
                'risk_level_min' => $rule->risk_level_min,
                'channels' => $rule->channels ?? ['log'],
                'enabled' => (bool) $rule->enabled,
            ])
            ->all();

        $codes = Subscription::query()
            ->where('organization_id', $organizationId)
            ->pluck('basin_code');

        if ($codes->isEmpty()) {
            $codes = Basin::query()->pluck('basin_code');
        }

        $basins = Basin::query()
            ->whereIn('basin_code', $codes)
            ->orderBy('basin_code')
            ->get(['basin_code', 'name'])
            ->map(fn (Basin $b) => [
                'basin_code' => $b->basin_code,
                'name' => $b->name,
            ])
            ->all();

        $recentLogs = AlertLog::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (AlertLog $log) => [
                'id' => $log->id,
                'basin_code' => $log->basin_code,
                'channel' => $log->channel,
                'status' => $log->status,
                'rule_name' => $log->payload['rule_name'] ?? null,
                'value' => $log->payload['value'] ?? null,
                'threshold' => $log->payload['threshold'] ?? null,
                'forecast_at' => $log->payload['forecast_at'] ?? null,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();

        $settings = $this->orgPluginSettings($organizationId);

        return Inertia::render('EarlyWarning/AlertRules', [
            ...AdvisoryCopy::forUi(),
            'calibration_note' => 'Defaults are Tripoli-oriented and capped below Storm Daniel / Derna extremes. Re-seed with early-warning:seed-alert-rules; tune with early-warning:suggest-thresholds.',
            'rules' => $rules,
            'basins' => $basins,
            'metrics' => self::METRICS,
            'operators' => self::OPERATORS,
            'risk_levels' => self::RISK_LEVELS,
            'recent_logs' => $recentLogs,
            'channel_settings' => [
                'notify_emails' => implode(', ', $settings['notify_emails'] ?? []),
                'telegram_chat_ids' => implode(', ', array_map('strval', $settings['telegram_chat_ids'] ?? [])),
                'notify_phones' => implode(', ', array_map('strval', $settings['notify_phones'] ?? [])),
                'telegram_configured' => filled(config('early-warning.channels.telegram.bot_token')),
                'sms_webhook_configured' => filled(config('early-warning.channels.sms.webhook_url')),
            ],
            'store_url' => route('early-warning.alert-rules.store'),
            'seed_url' => route('early-warning.alert-rules.seed'),
            'channels_url' => route('early-warning.alert-rules.channels'),
        ]);
    }

    public function updateChannels(Request $request): RedirectResponse
    {
        Gate::authorize('early-warning.manage');

        $organizationId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'notify_emails' => ['nullable', 'string', 'max:2000'],
            'telegram_chat_ids' => ['nullable', 'string', 'max:1000'],
            'notify_phones' => ['nullable', 'string', 'max:1000'],
        ]);

        $emailParts = preg_split('/[\s,;]+/', (string) ($data['notify_emails'] ?? '')) ?: [];
        $emails = collect($emailParts)
            ->filter(fn ($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL))
            ->values()
            ->all();

        $chatParts = preg_split('/[\s,;]+/', (string) ($data['telegram_chat_ids'] ?? '')) ?: [];
        $chatIds = collect($chatParts)
            ->filter(fn ($id) => $id !== '')
            ->values()
            ->all();

        $phoneParts = preg_split('/[\s,;]+/', (string) ($data['notify_phones'] ?? '')) ?: [];
        $phones = collect($phoneParts)
            ->filter(fn ($id) => $id !== '')
            ->values()
            ->all();

        $row = OrganizationPlugin::query()->firstOrNew([
            'organization_id' => $organizationId,
            'plugin_key' => 'early-warning',
        ]);
        if (! $row->exists) {
            $row->forceFill([
                'enabled' => true,
                'enabled_at' => now(),
            ]);
        }
        $settings = is_array($row->settings) ? $row->settings : [];
        $settings['notify_emails'] = $emails;
        $settings['telegram_chat_ids'] = $chatIds;
        $settings['notify_phones'] = $phones;
        $row->settings = $settings;
        $row->save();

        return redirect()
            ->route('early-warning.alert-rules.index')
            ->with('success', 'Alert channels updated.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function orgPluginSettings(int $organizationId): array
    {
        $row = OrganizationPlugin::query()
            ->where('organization_id', $organizationId)
            ->where('plugin_key', 'early-warning')
            ->first();

        return is_array($row?->settings) ? $row->settings : [];
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('early-warning.manage');

        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validated($request);

        AlertRule::query()->create([
            ...$data,
            'organization_id' => $organizationId,
            'channels' => $data['channels'] ?? ['log'],
        ]);

        return redirect()
            ->route('early-warning.alert-rules.index')
            ->with('success', 'Alert rule created.');
    }

    public function update(Request $request, AlertRule $alertRule): RedirectResponse
    {
        Gate::authorize('early-warning.manage');
        $this->authorizeOrg($request, $alertRule);
        $data = $this->validated($request, updating: true);

        $alertRule->fill($data);
        if (array_key_exists('channels', $data)) {
            $alertRule->channels = $data['channels'] ?? ['log'];
        }
        $alertRule->save();

        return redirect()
            ->route('early-warning.alert-rules.index')
            ->with('success', 'Alert rule updated.');
    }

    public function destroy(Request $request, AlertRule $alertRule): RedirectResponse
    {
        Gate::authorize('early-warning.manage');
        $this->authorizeOrg($request, $alertRule);
        $alertRule->delete();

        return redirect()
            ->route('early-warning.alert-rules.index')
            ->with('success', 'Alert rule deleted.');
    }

    public function seed(Request $request, DefaultAlertRules $seeder): RedirectResponse
    {
        Gate::authorize('early-warning.manage');

        $org = $request->user()->organization;
        $created = $seeder->seed($org);

        return redirect()
            ->route('early-warning.alert-rules.index')
            ->with('success', $created > 0
                ? "Seeded {$created} default rule(s)."
                : 'Default rules already present.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        $rules = [
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:120'],
            'basin_code' => [
                'nullable',
                'string',
                'max:64',
                Rule::exists('ew_basins', 'basin_code'),
            ],
            'metric' => [$updating ? 'sometimes' : 'required', Rule::in(self::METRICS)],
            'operator' => [$updating ? 'sometimes' : 'required', Rule::in(self::OPERATORS)],
            'threshold' => [$updating ? 'sometimes' : 'required', 'numeric', 'min:0', 'max:5000'],
            'risk_level_min' => ['nullable', Rule::in(self::RISK_LEVELS)],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', Rule::in(['log', 'email', 'telegram', 'sms', 'webhook'])],
            'enabled' => [$updating ? 'sometimes' : 'nullable', 'boolean'],
        ];

        $data = $request->validate($rules);

        if (array_key_exists('enabled', $data)) {
            $data['enabled'] = (bool) $data['enabled'];
        } elseif (! $updating) {
            $data['enabled'] = true;
        }

        return $data;
    }

    protected function authorizeOrg(Request $request, AlertRule $alertRule): void
    {
        abort_unless(
            (int) $alertRule->organization_id === (int) $request->user()->organization_id,
            404,
        );
    }
}
