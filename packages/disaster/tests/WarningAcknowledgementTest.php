<?php

namespace Lumina\Disaster\Tests;

use App\Events\WarningIssued;
use App\Models\Role;
use App\Models\User;
use App\Support\WarningIssuedPayload;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Lumina\Disaster\Models\Activation;
use Lumina\Disaster\Models\EscalationRule;
use Lumina\Disaster\Models\WarningAck;
use Lumina\Disaster\Models\WarningAuditLog;
use Lumina\Disaster\Models\WarningRecipient;
use Lumina\Disaster\Notifications\ActivationProposedNotification;
use Lumina\Disaster\Notifications\WarningEscalatedNotification;
use Lumina\Disaster\Support\Enums\WarningAckResponse;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class WarningAcknowledgementTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_warning_issued_seeds_recipients_acks_and_audit(): void
    {
        Notification::fake();

        event(new WarningIssued($this->payload('seed-1')));

        $activation = Activation::query()->first();
        $this->assertNotNull($activation);
        $this->assertSame(1, WarningRecipient::query()->count());
        $this->assertSame(1, WarningAck::query()->count());
        $this->assertSame(1, WarningAuditLog::query()->where('event_type', 'notified')->count());

        Notification::assertSentTo($this->disasterAdmin, ActivationProposedNotification::class);
    }

    public function test_unacked_warning_escalates_after_timeout(): void
    {
        Notification::fake();

        $editorRole = Role::query()->firstOrCreate(
            ['name' => 'editor'],
            ['description' => 'Editor'],
        );
        $editor = User::factory()->create(['organization_id' => $this->disasterOrg->id]);
        $editor->roles()->attach($editorRole->id, ['organization_id' => $this->disasterOrg->id]);

        EscalationRule::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'timeout_minutes' => 10,
            'next_recipient_role' => 'editor',
            'channel' => 'email',
            'sort_order' => 0,
        ]);

        event(new WarningIssued($this->payload('esc-1')));
        $activation = Activation::query()->firstOrFail();

        $this->assertSame(1, WarningRecipient::query()->where('activation_id', $activation->id)->count());

        $this->travel(11)->minutes();
        $this->artisan('disaster:escalate-warnings', [
            '--organization' => $this->disasterOrg->id,
        ])->assertSuccessful();

        $this->assertTrue(
            WarningRecipient::query()
                ->where('activation_id', $activation->id)
                ->where('user_id', $editor->id)
                ->where('role', 'editor')
                ->exists(),
        );
        $this->assertTrue(
            WarningAuditLog::query()
                ->where('activation_id', $activation->id)
                ->where('event_type', 'escalated')
                ->exists(),
        );

        Notification::assertSentTo($editor, WarningEscalatedNotification::class);
    }

    public function test_acknowledgement_stops_escalation_chain(): void
    {
        Notification::fake();

        $editorRole = Role::query()->firstOrCreate(
            ['name' => 'editor'],
            ['description' => 'Editor'],
        );
        $editor = User::factory()->create(['organization_id' => $this->disasterOrg->id]);
        $editor->roles()->attach($editorRole->id, ['organization_id' => $this->disasterOrg->id]);

        EscalationRule::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'timeout_minutes' => 5,
            'next_recipient_role' => 'editor',
            'channel' => 'log',
            'sort_order' => 0,
        ]);

        event(new WarningIssued($this->payload('ack-stop')));
        $activation = Activation::query()->firstOrFail();

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/activations/'.$activation->id.'/acknowledge', [
                'response' => 'acknowledged',
            ])
            ->assertOk();

        $this->assertSame(
            WarningAckResponse::Acknowledged,
            WarningAck::query()->first()?->response,
        );
        $this->assertTrue(
            WarningAuditLog::query()
                ->where('activation_id', $activation->id)
                ->where('event_type', 'acknowledged')
                ->exists(),
        );

        $this->travel(10)->minutes();
        $this->artisan('disaster:escalate-warnings', [
            '--organization' => $this->disasterOrg->id,
        ])->assertSuccessful();

        $this->assertFalse(
            WarningRecipient::query()
                ->where('activation_id', $activation->id)
                ->where('user_id', $editor->id)
                ->exists(),
        );
        Notification::assertNotSentTo($editor, WarningEscalatedNotification::class);
    }

    public function test_escalate_command_is_idempotent(): void
    {
        Notification::fake();

        $editorRole = Role::query()->firstOrCreate(
            ['name' => 'editor'],
            ['description' => 'Editor'],
        );
        $editor = User::factory()->create(['organization_id' => $this->disasterOrg->id]);
        $editor->roles()->attach($editorRole->id, ['organization_id' => $this->disasterOrg->id]);

        EscalationRule::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'timeout_minutes' => 1,
            'next_recipient_role' => 'editor',
            'channel' => 'email',
            'sort_order' => 0,
        ]);

        event(new WarningIssued($this->payload('idem')));
        $activation = Activation::query()->firstOrFail();

        $this->travel(2)->minutes();
        $this->artisan('disaster:escalate-warnings', ['--organization' => $this->disasterOrg->id])->assertSuccessful();
        $this->artisan('disaster:escalate-warnings', ['--organization' => $this->disasterOrg->id])->assertSuccessful();

        $this->assertSame(
            1,
            WarningRecipient::query()
                ->where('activation_id', $activation->id)
                ->where('user_id', $editor->id)
                ->count(),
        );
        $this->assertSame(
            1,
            WarningAuditLog::query()
                ->where('activation_id', $activation->id)
                ->where('event_type', 'escalated')
                ->where('payload->escalation_rule_id', EscalationRule::query()->first()->id)
                ->count(),
        );
    }

    public function test_audit_log_is_immutable(): void
    {
        Notification::fake();
        event(new WarningIssued($this->payload('audit')));
        $log = WarningAuditLog::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $log->update(['event_type' => 'tampered']);
    }

    public function test_audit_log_cannot_be_deleted(): void
    {
        Notification::fake();
        event(new WarningIssued($this->payload('audit-del')));
        $log = WarningAuditLog::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_member_cannot_manage_rules_but_dispatch_can_ack(): void
    {
        Notification::fake();
        event(new WarningIssued($this->payload('perms')));
        $activation = Activation::query()->firstOrFail();

        $member = User::factory()->create(['organization_id' => $this->disasterOrg->id]);

        $this->actingAs($member)
            ->postJson('/x/disaster/escalation-rules', [
                'timeout_minutes' => 15,
                'next_recipient_role' => 'admin',
                'channel' => 'email',
            ])
            ->assertForbidden();

        $this->actingAs($member)
            ->postJson('/x/disaster/activations/'.$activation->id.'/acknowledge')
            ->assertOk();

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/escalation-rules', [
                'timeout_minutes' => 15,
                'next_recipient_role' => 'admin',
                'channel' => 'log',
                'sort_order' => 1,
            ])
            ->assertCreated();
    }

    public function test_activation_show_includes_timeline(): void
    {
        Notification::fake();
        event(new WarningIssued($this->payload('timeline')));
        $activation = Activation::query()->firstOrFail();

        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/activations/'.$activation->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/ActivationShow')
                ->has('timeline')
                ->where('activation.id', $activation->id)
                ->has('acknowledge_url')
            );
    }

    public function test_escalation_rules_index_for_manage(): void
    {
        EscalationRule::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'timeout_minutes' => 30,
            'next_recipient_role' => 'admin',
            'channel' => 'telegram',
            'sort_order' => 0,
        ]);

        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/escalation-rules')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/EscalationRules')
                ->has('rules', 1)
            );
    }

    public function test_warning_ack_tables_exist(): void
    {
        foreach ([
            'dm_warning_recipients',
            'dm_warning_acks',
            'dm_escalation_rules',
            'dm_warning_audit_log',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    protected function payload(string $alertId): WarningIssuedPayload
    {
        return new WarningIssuedPayload(
            source: 'early-warning',
            sourceAlertId: $alertId,
            organizationId: (int) $this->disasterOrg->id,
            hazardType: 'flood',
            severity: 'Severe',
            urgency: 'Immediate',
            certainty: 'Likely',
            validFrom: Carbon::parse('2026-10-28T12:00:00Z'),
            validTo: Carbon::parse('2026-10-28T18:00:00Z'),
            affected: [['basin_code' => 'TRP-HIRA']],
            advisoryText: 'Forecast-based decision support only.',
            isExercise: false,
        );
    }
}
