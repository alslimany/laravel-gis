<?php

namespace Lumina\Disaster\Tests;

use App\Events\WarningIssued;
use App\Support\WarningIssuedPayload;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Lumina\Disaster\Models\Activation;
use Lumina\Disaster\Models\WarningAck;
use Lumina\Disaster\Models\WarningAuditLog;
use Lumina\Disaster\Models\WarningRecipient;
use Lumina\Disaster\Notifications\ActivationProposedNotification;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class WarningActivationTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_warning_issued_creates_exactly_one_activation_idempotently(): void
    {
        Notification::fake();

        $payload = new WarningIssuedPayload(
            source: 'early-warning',
            sourceAlertId: '99',
            organizationId: (int) $this->disasterOrg->id,
            hazardType: 'flood',
            severity: 'Severe',
            urgency: 'Immediate',
            certainty: 'Likely',
            validFrom: Carbon::parse('2026-10-28T12:00:00Z'),
            validTo: Carbon::parse('2026-10-28T18:00:00Z'),
            affected: [
                ['type' => 'Feature', 'properties' => ['basin_code' => 'TRP-HIRA'], 'geometry' => null],
            ],
            advisoryText: 'Forecast-based decision support only.',
            isExercise: false,
        );

        event(new WarningIssued($payload));
        event(new WarningIssued($payload));

        $this->assertSame(1, Activation::query()->count());
        $activation = Activation::query()->first();
        $this->assertNotNull($activation);
        $this->assertSame('proposed', $activation->status);
        $this->assertSame('early-warning', $activation->source);
        $this->assertSame('99', $activation->source_alert_id);
        $this->assertSame((int) $this->disasterOrg->id, (int) $activation->organization_id);
        $this->assertSame('flood', $activation->hazard_type);
        $this->assertSame('Severe', $activation->severity);
        $this->assertFalse($activation->is_exercise);

        Notification::assertSentTo(
            $this->disasterAdmin,
            ActivationProposedNotification::class,
            fn (ActivationProposedNotification $notification) => $notification->activation->is($activation),
        );
        Notification::assertCount(1);

        $this->assertSame(1, WarningRecipient::query()->where('activation_id', $activation->id)->count());
        $this->assertSame(1, WarningAck::query()->count());
        $this->assertSame(1, WarningAuditLog::query()->where('event_type', 'notified')->count());
    }

    public function test_activations_console_list_and_show(): void
    {
        $activation = Activation::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'source' => 'early-warning',
            'source_alert_id' => '7',
            'hazard_type' => 'flood',
            'severity' => 'Moderate',
            'urgency' => 'Expected',
            'certainty' => 'Likely',
            'valid_from' => now(),
            'valid_to' => now()->addHours(6),
            'affected' => [['basin_code' => 'TRP-HIRA']],
            'advisory_text' => 'Advisory',
            'is_exercise' => false,
            'status' => 'proposed',
        ]);

        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/activations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/Activations')
                ->has('activations', 1)
            );

        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/activations/'.$activation->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/ActivationShow')
                ->where('activation.id', $activation->id)
                ->where('activation.source_alert_id', '7')
            );
    }
}
