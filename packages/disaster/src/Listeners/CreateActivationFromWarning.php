<?php

namespace Lumina\Disaster\Listeners;

use App\Events\WarningIssued;
use App\Plugins\PluginManager;
use Lumina\Disaster\Models\Activation;
use Lumina\Disaster\Services\WarningAcknowledgementService;

/**
 * Upserts dm_activations from Core WarningIssued (never reads ew_* tables).
 */
class CreateActivationFromWarning
{
    public function __construct(
        private PluginManager $plugins,
        private WarningAcknowledgementService $acknowledgements,
    ) {}

    public function handle(WarningIssued $event): void
    {
        if (! $this->plugins->isInstalled('disaster')) {
            return;
        }

        $payload = $event->payload;
        if (! $this->plugins->isEnabledFor('disaster', $payload->organizationId)) {
            return;
        }

        $activation = Activation::query()->updateOrCreate(
            [
                'organization_id' => $payload->organizationId,
                'source' => $payload->source,
                'source_alert_id' => $payload->sourceAlertId,
            ],
            [
                'hazard_type' => $payload->hazardType,
                'severity' => $payload->severity,
                'urgency' => $payload->urgency,
                'certainty' => $payload->certainty,
                'valid_from' => $payload->validFrom,
                'valid_to' => $payload->validTo,
                'affected' => $payload->affected,
                'advisory_text' => $payload->advisoryText,
                'is_exercise' => $payload->isExercise,
                'status' => 'proposed',
            ],
        );

        if ($activation->wasRecentlyCreated) {
            $this->acknowledgements->seedInitialRecipients($activation);
        }
    }
}
