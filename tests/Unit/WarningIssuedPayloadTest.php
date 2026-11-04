<?php

namespace Tests\Unit;

use App\Events\WarningIssued;
use App\Support\WarningIssuedPayload;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Core WarningIssued contract — payload shape for plugin emitters/listeners. */
class WarningIssuedPayloadTest extends TestCase
{
    #[Test]
    public function payload_is_immutable_and_serializes_cap_like_fields(): void
    {
        $from = Carbon::parse('2026-10-28T12:00:00Z');
        $to = Carbon::parse('2026-10-28T18:00:00Z');

        $payload = new WarningIssuedPayload(
            source: 'early-warning',
            sourceAlertId: '42',
            organizationId: 7,
            hazardType: 'flood',
            severity: 'Severe',
            urgency: 'Immediate',
            certainty: 'Likely',
            validFrom: $from,
            validTo: $to,
            affected: [
                ['type' => 'Feature', 'properties' => ['basin_code' => 'TRP-HIRA'], 'geometry' => null],
                101,
            ],
            advisoryText: 'Forecast-based decision support only.',
            isExercise: false,
        );

        $this->assertSame('early-warning', $payload->source);
        $this->assertSame('42', $payload->sourceAlertId);
        $this->assertSame(7, $payload->organizationId);
        $this->assertFalse($payload->isExercise);

        $array = $payload->toArray();
        $this->assertSame('early-warning', $array['source']);
        $this->assertSame('42', $array['source_alert_id']);
        $this->assertSame(7, $array['organization_id']);
        $this->assertSame('flood', $array['hazard_type']);
        $this->assertSame('Severe', $array['severity']);
        $this->assertSame('Immediate', $array['urgency']);
        $this->assertSame('Likely', $array['certainty']);
        $this->assertNotNull($array['valid_from']);
        $this->assertNotNull($array['valid_to']);
        $this->assertCount(2, $array['affected']);
        $this->assertSame('Forecast-based decision support only.', $array['advisory_text']);
        $this->assertFalse($array['is_exercise']);

        $event = new WarningIssued($payload);
        $this->assertSame($payload, $event->payload);
    }

    #[Test]
    public function is_exercise_defaults_to_false(): void
    {
        $payload = new WarningIssuedPayload(
            source: 'early-warning',
            sourceAlertId: '1',
            organizationId: 1,
            hazardType: 'flood',
            severity: 'Moderate',
            urgency: 'Expected',
            certainty: 'Possible',
            validFrom: null,
            validTo: null,
            affected: [],
            advisoryText: 'Advisory',
        );

        $this->assertFalse($payload->isExercise);
        $this->assertNull($payload->toArray()['valid_from']);
        $this->assertNull($payload->toArray()['valid_to']);
    }
}
