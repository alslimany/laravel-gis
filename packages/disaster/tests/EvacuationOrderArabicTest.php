<?php

namespace Lumina\Disaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\Disaster\Models\EvacuationOrder;
use Lumina\Disaster\Services\CopSnapshotBuilder;
use Lumina\Disaster\Support\Enums\EvacuationOrderStatus;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

/** EVAC-1: Arabic-first evacuation orders (labels, validation, optional English, COP title). */
class EvacuationOrderArabicTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    private function asArabic(): static
    {
        return $this->actingAs($this->disasterAdmin)->withSession(['locale' => 'ar']);
    }

    public function test_arabic_only_order_is_accepted(): void
    {
        $this->asArabic()
            ->postJson('/x/disaster/evacuation-orders', ['text_ar' => 'إخلاء الحي فورًا.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $order = EvacuationOrder::query()->firstOrFail();
        $this->assertSame('إخلاء الحي فورًا.', $order->text_ar);
        $this->assertEmpty($order->text_en);
    }

    public function test_both_texts_empty_is_rejected_with_arabic_messages(): void
    {
        $response = $this->asArabic()
            ->postJson('/x/disaster/evacuation-orders', ['text_ar' => '', 'text_en' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['text_ar'])
            ->assertJsonMissingValidationErrors(['text_en']);

        $message = $response->json('errors.text_ar.0');
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $message);
        $this->assertStringNotContainsString('text ar', $message);
        $this->assertStringNotContainsString('validation.', $message);
        $this->assertSame('يجب إدخال نص الأمر', $message);
        $this->assertCount(1, $response->json('errors.text_ar'));
        $this->assertStringNotContainsString('الإنجليزية', $message);
    }

    public function test_update_cannot_clear_both_texts(): void
    {
        $order = $this->draft();
        $this->asArabic()
            ->putJson("/x/disaster/evacuation-orders/{$order->id}", ['text_ar' => '', 'text_en' => ''])
            ->assertUnprocessable();
        $this->assertSame('أمر تجريبي', $order->fresh()->text_ar);
    }

    public function test_invalid_transition_error_is_arabic_with_status_labels(): void
    {
        $order = $this->draft();
        $response = $this->asArabic()
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", ['status' => 'completed'])
            ->assertUnprocessable();

        $message = (string) $response->json('errors.status.0');
        $this->assertStringContainsString('مسودة', $message);
        $this->assertStringContainsString('مكتمل', $message);
        $this->assertStringNotContainsString('completed', $message);
    }

    public function test_cop_title_uses_arabic_text_in_arabic(): void
    {
        $order = $this->draft(['text_en' => 'Evacuate now.', 'status' => EvacuationOrderStatus::Issued, 'issued_at' => now()]);

        app()->setLocale('ar');
        $snapshot = (new CopSnapshotBuilder($this->disasterOrg->id, $this->disasterAdmin))->build();
        $titles = collect($snapshot['evacuation_orders'])->pluck('title');
        $this->assertContains('أمر تجريبي', $titles->all());

        app()->setLocale('en');
        $snapshot = (new CopSnapshotBuilder($this->disasterOrg->id, $this->disasterAdmin))->build();
        $titles = collect($snapshot['evacuation_orders'])->pluck('title');
        $this->assertContains('Evacuate now.', $titles->all());
        $this->assertNotNull($order);
    }

    /** @param array<string, mixed> $attrs */
    private function draft(array $attrs = []): EvacuationOrder
    {
        return EvacuationOrder::query()->create($attrs + [
            'organization_id' => $this->disasterOrg->id,
            'created_by' => $this->disasterAdmin->id,
            'text_ar' => 'أمر تجريبي',
            'status' => EvacuationOrderStatus::Draft,
        ]);
    }
}
