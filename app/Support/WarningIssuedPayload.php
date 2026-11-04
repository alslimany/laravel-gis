<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Immutable Core contract payload for cross-plugin early warnings.
 * Plugins map domain alerts into these CAP-like fields; Core never imports plugins.
 *
 * @phpstan-type AffectedItem array<string, mixed>|int|string
 */
final readonly class WarningIssuedPayload
{
    /**
     * @param  list<AffectedItem>  $affected  GeoJSON geometries/features and/or Core feature IDs
     */
    public function __construct(
        public string $source,
        public string $sourceAlertId,
        public int $organizationId,
        public string $hazardType,
        public string $severity,
        public string $urgency,
        public string $certainty,
        public ?DateTimeInterface $validFrom,
        public ?DateTimeInterface $validTo,
        public array $affected,
        public string $advisoryText,
        public bool $isExercise = false,
    ) {}

    /**
     * @return array{
     *     source: string,
     *     source_alert_id: string,
     *     organization_id: int,
     *     hazard_type: string,
     *     severity: string,
     *     urgency: string,
     *     certainty: string,
     *     valid_from: string|null,
     *     valid_to: string|null,
     *     affected: list<AffectedItem>,
     *     advisory_text: string,
     *     is_exercise: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'source_alert_id' => $this->sourceAlertId,
            'organization_id' => $this->organizationId,
            'hazard_type' => $this->hazardType,
            'severity' => $this->severity,
            'urgency' => $this->urgency,
            'certainty' => $this->certainty,
            'valid_from' => $this->formatDate($this->validFrom),
            'valid_to' => $this->formatDate($this->validTo),
            'affected' => $this->affected,
            'advisory_text' => $this->advisoryText,
            'is_exercise' => $this->isExercise,
        ];
    }

    private function formatDate(?DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return $value->format(DateTimeInterface::ATOM);
    }
}
