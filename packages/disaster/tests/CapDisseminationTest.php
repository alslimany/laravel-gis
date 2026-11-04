<?php

namespace Lumina\Disaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Lumina\Disaster\Contracts\SmsGateway;
use Lumina\Disaster\Models\DisseminationLog;
use Lumina\Disaster\Models\MessageTemplate;
use Lumina\Disaster\Models\PublicToken;
use Lumina\Disaster\Models\SmsDelivery;
use Lumina\Disaster\Services\CapDisseminationService;
use Lumina\Disaster\Support\Cap\Cap12Validator;
use Lumina\Disaster\Support\Dissemination\CapChannelAdapter;
use Lumina\Disaster\Support\Enums\DisseminationStatus;
use Lumina\Disaster\Support\Enums\MessageTemplateStatus;
use Lumina\Disaster\Support\Sms\RecordingSmsGateway;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class CapDisseminationTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_draft_cap_still_creates_log_with_valid_stub_xml(): void
    {
        $log = app(CapDisseminationService::class)->draftCap($this->disasterOrg->id, [
            'headline' => 'Draft headline',
            'event' => 'Flood',
        ]);

        $this->assertSame('cap', $log->channel);
        $this->assertSame(DisseminationStatus::Draft->value, $log->status);
        $this->assertTrue($log->test_mode);
        $this->assertNotEmpty($log->identifier);

        $xml = $log->payload['cap_xml'] ?? '';
        $this->assertStringContainsString('<status>Draft</status>', $xml);
        $this->assertTrue((new Cap12Validator)->isValid($xml));
    }

    public function test_published_cap_validates_against_cap12_structural_rules(): void
    {
        $polygon = [
            [32.8, 13.1],
            [32.9, 13.1],
            [32.9, 13.2],
            [32.8, 13.2],
        ];

        $response = $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/dissemination/cap/publish', [
                'event' => 'Flood',
                'hazard_type' => 'flood',
                'headline' => 'Flood warning',
                'description' => 'Rising water in sector A',
                'headline_ar' => 'تحذير فيضان',
                'description_ar' => 'ارتفاع المياه في القطاع أ',
                'category' => 'Met',
                'urgency' => 'Immediate',
                'severity' => 'Severe',
                'certainty' => 'Likely',
                'area_desc' => 'Sector A',
                'polygon' => $polygon,
                'test_mode' => false,
            ])
            ->assertCreated();

        $log = DisseminationLog::query()->findOrFail($response->json('data.id'));
        $xml = $log->payload['cap_xml'];

        $this->assertTrue((new Cap12Validator)->isValid($xml), implode('; ', (new Cap12Validator)->validate($xml)));
        $this->assertStringContainsString('<msgType>Alert</msgType>', $xml);
        $this->assertStringContainsString('<language>ar</language>', $xml);
        $this->assertStringContainsString('<language>en-US</language>', $xml);
        // CAP-1: the Arabic info block comes first.
        $this->assertLessThan(strpos($xml, '<language>en-US</language>'), strpos($xml, '<language>ar</language>'));
        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('cap', 'urn:oasis:names:tc:emergency:cap:1.2');
        $this->assertSame('ar', $xpath->evaluate('string(/cap:alert/cap:info[1]/cap:language)'));
        $this->assertSame('تحذير فيضان', $xpath->evaluate('string(/cap:alert/cap:info[1]/cap:headline)'));
        $this->assertStringContainsString('<polygon>', $xml);
        $this->assertSame(DisseminationStatus::Sent->value, $log->fresh()->status);
    }

    public function test_update_and_cancel_reference_original_identifier(): void
    {
        $service = app(CapDisseminationService::class);
        $original = $service->publish($this->disasterOrg->id, [
            'event' => 'Flood',
            'headline' => 'Initial',
            'description' => 'Initial flood alert body text',
        ], testMode: false);

        $update = $service->update($this->disasterOrg->id, $original, [
            'event' => 'Flood',
            'headline' => 'Updated',
            'description' => 'Updated flood alert body text',
        ], testMode: false);

        $cancel = $service->cancel($this->disasterOrg->id, $original, [
            'event' => 'Flood',
            'headline' => 'Cancelled',
            'description' => 'Flood alert cancelled for sector',
        ], testMode: false);

        $this->assertSame('Update', $update->msg_type);
        $this->assertSame($original->identifier, $update->references_identifier);
        $this->assertStringContainsString(
            '<references>',
            $update->payload['cap_xml'],
        );
        $this->assertStringContainsString(
            $original->identifier,
            $update->payload['cap_xml'],
        );

        $this->assertSame('Cancel', $cancel->msg_type);
        $this->assertSame($original->identifier, $cancel->references_identifier);
        $this->assertTrue((new Cap12Validator)->isValid($update->payload['cap_xml']));
        $this->assertTrue((new Cap12Validator)->isValid($cancel->payload['cap_xml']));
    }

    public function test_delivery_report_counts_sms_outcomes(): void
    {
        $recording = new RecordingSmsGateway;
        $this->app->instance(SmsGateway::class, $recording);
        $this->rebindCapService();

        $log = app(CapDisseminationService::class)->publish($this->disasterOrg->id, [
            'event' => 'Flood',
            'headline' => 'SMS fanout',
            'description' => 'SMS fanout body for delivery report',
            'sms_recipients' => ['opaque-a', 'opaque-b'],
            'sms_body' => 'Evacuate now',
        ], testMode: false);

        $log->refresh()->load('smsDeliveries');
        $this->assertCount(2, $recording->sent);
        $this->assertSame(2, SmsDelivery::query()->where('dissemination_log_id', $log->id)->count());

        $report = app(CapDisseminationService::class)->deliveryReport($log);
        $this->assertSame(2, $report['sent']);
        $this->assertSame(0, $report['failed']);

        $this->actingAs($this->disasterAdmin)
            ->getJson('/x/disaster/dissemination/cap/'.$log->id.'/report')
            ->assertOk()
            ->assertJsonPath('data.deliveries.sent', 2);
    }

    public function test_test_mode_never_calls_sms_gateway(): void
    {
        $recording = new RecordingSmsGateway;
        $this->app->instance(SmsGateway::class, $recording);
        $this->rebindCapService();

        $log = app(CapDisseminationService::class)->publish($this->disasterOrg->id, [
            'event' => 'Flood',
            'headline' => 'Test alert',
            'description' => 'Test alert must not reach the public SMS gateway',
            'sms_recipients' => ['opaque-should-not-send'],
            'sms_body' => 'Should not send',
        ], testMode: true);

        $this->assertTrue($log->test_mode);
        $this->assertSame(DisseminationStatus::Test->value, $log->fresh()->status);
        $this->assertStringContainsString('<status>Test</status>', $log->payload['cap_xml']);
        $this->assertSame([], $recording->sent);
        $this->assertSame(0, SmsDelivery::query()->where('dissemination_log_id', $log->id)->count());
        $this->assertInstanceOf(RecordingSmsGateway::class, app(SmsGateway::class));
    }

    public function test_template_validate_approve_then_publish_uses_arabic_wording(): void
    {
        $create = $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/message-templates', [
                'hazard_type' => 'flood',
                'language' => 'ar',
                'channel' => 'cap',
                'headline' => 'تحذير فيضان معتمد',
                'body' => 'يرجى الإخلاء الفوري من المناطق المنخفضة',
            ])
            ->assertCreated();

        $id = (int) $create->json('data.id');

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/message-templates/'.$id.'/validate')
            ->assertOk()
            ->assertJsonPath('data.status', MessageTemplateStatus::Validated->value);

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/message-templates/'.$id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', MessageTemplateStatus::Approved->value);

        MessageTemplate::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'hazard_type' => 'flood',
            'language' => 'en',
            'channel' => 'cap',
            'headline' => 'Approved flood warning',
            'body' => 'Please evacuate low-lying areas immediately now',
            'status' => MessageTemplateStatus::Approved->value,
            'approved_by' => $this->disasterAdmin->id,
            'approved_at' => now(),
        ]);

        $log = app(CapDisseminationService::class)->publish($this->disasterOrg->id, [
            'event' => 'Flood',
            'hazard_type' => 'flood',
        ], testMode: false);

        $xml = $log->payload['cap_xml'];
        $this->assertStringContainsString('تحذير فيضان معتمد', $xml);
        $this->assertStringContainsString('Approved flood warning', $xml);
    }

    public function test_public_portal_feed_serves_cached_cap(): void
    {
        $service = app(CapDisseminationService::class);
        $log = $service->publish($this->disasterOrg->id, [
            'event' => 'Flood',
            'headline' => 'Public CAP',
            'description' => 'Public CAP body for portal cache feed',
        ], testMode: false);

        Cache::forget(CapChannelAdapter::cacheKey($this->disasterOrg->id));

        $issued = PublicToken::issue($this->disasterOrg->id, 'public');
        $feed = $this->getJson('/dm/public/'.$issued['plain'].'/feed')
            ->assertOk()
            ->json();

        $this->assertSame($log->identifier, $feed['cap']['identifier']);
        $this->assertNotEmpty($feed['cap']['cap_xml']);
        $this->assertTrue(Cache::has(CapChannelAdapter::cacheKey($this->disasterOrg->id)));
    }

    public function test_retry_command_dispatches_due_failed_logs(): void
    {
        $log = DisseminationLog::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'channel' => 'cap',
            'status' => DisseminationStatus::Failed->value,
            'msg_type' => 'Alert',
            'identifier' => 'retry-me-1',
            'attempt_count' => 1,
            'next_retry_at' => now()->subMinute(),
            'test_mode' => false,
            'payload' => [
                'cap_xml' => app(CapDisseminationService::class)->draftCap($this->disasterOrg->id)->payload['cap_xml'],
                'headline' => 'Retry',
                'sender' => 'lumina-disaster',
                'sent' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            'created_at' => now(),
        ]);

        $this->artisan('disaster:retry-dissemination', [
            '--organization' => $this->disasterOrg->id,
        ])->assertSuccessful();

        $this->assertNotSame(DisseminationStatus::Failed->value, $log->fresh()->status);
    }

    public function test_explicit_info_blocks_are_reordered_arabic_first(): void
    {
        $blocks = CapDisseminationService::arabicFirst([
            ['language' => 'en-US', 'headline' => 'A'],
            ['language' => 'fr', 'headline' => 'B'],
            ['language' => 'ar-LY', 'headline' => 'ج'],
        ]);
        $this->assertSame(['ج', 'A', 'B'], array_column($blocks, 'headline'));
    }

    private function rebindCapService(): void
    {
        $this->app->forgetInstance(CapDisseminationService::class);
        $this->app->make(CapDisseminationService::class);
    }
}
