<?php

namespace Lumina\Disaster\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lumina\Disaster\Models\Beneficiary;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\ShelterCheckin;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\Enums\ShelterCheckinDirection;

/**
 * Sole writer of shelter current_occupancy. Check-in/out ledger rows are applied
 * inside a transaction with a locked shelter row so occupancy stays ledger-consistent.
 */
final class ShelterOccupancyService
{
    public const APPROACHING_RATIO = 0.8;

    /**
     * @return array{
     *     checkin: ShelterCheckin,
     *     shelter: CriticalSite,
     *     capacity_warning: null|string
     * }
     *
     * @throws ValidationException
     */
    public function checkIn(
        CriticalSite $shelter,
        int $headcount = 1,
        ?User $actor = null,
        ?Beneficiary $beneficiary = null,
        ?string $notes = null,
        ?\DateTimeInterface $checkedAt = null,
    ): array {
        return $this->record(
            $shelter,
            ShelterCheckinDirection::CheckIn,
            $headcount,
            $actor,
            $beneficiary,
            $notes,
            $checkedAt,
        );
    }

    /**
     * @return array{
     *     checkin: ShelterCheckin,
     *     shelter: CriticalSite,
     *     capacity_warning: null|string
     * }
     *
     * @throws ValidationException
     */
    public function checkOut(
        CriticalSite $shelter,
        int $headcount = 1,
        ?User $actor = null,
        ?Beneficiary $beneficiary = null,
        ?string $notes = null,
        ?\DateTimeInterface $checkedAt = null,
    ): array {
        return $this->record(
            $shelter,
            ShelterCheckinDirection::CheckOut,
            $headcount,
            $actor,
            $beneficiary,
            $notes,
            $checkedAt,
        );
    }

    /**
     * @return array{
     *     checkin: ShelterCheckin,
     *     shelter: CriticalSite,
     *     capacity_warning: null|string
     * }
     *
     * @throws ValidationException
     */
    public function record(
        CriticalSite $shelter,
        ShelterCheckinDirection $direction,
        int $headcount = 1,
        ?User $actor = null,
        ?Beneficiary $beneficiary = null,
        ?string $notes = null,
        ?\DateTimeInterface $checkedAt = null,
    ): array {
        if ($headcount < 1) {
            throw ValidationException::withMessages([
                'headcount' => 'Headcount must be at least 1.',
            ]);
        }

        if ($shelter->type !== CriticalSiteType::Shelter->value && $shelter->type !== CriticalSiteType::Shelter) {
            throw ValidationException::withMessages([
                'shelter_site_id' => 'Check-ins are only allowed for shelter critical sites.',
            ]);
        }

        if ($beneficiary !== null && (int) $beneficiary->organization_id !== (int) $shelter->organization_id) {
            throw ValidationException::withMessages([
                'beneficiary_id' => 'Beneficiary must belong to the same organization as the shelter.',
            ]);
        }

        return DB::transaction(function () use (
            $shelter,
            $direction,
            $headcount,
            $actor,
            $beneficiary,
            $notes,
            $checkedAt,
        ) {
            /** @var CriticalSite $locked */
            $locked = CriticalSite::query()
                ->whereKey($shelter->id)
                ->lockForUpdate()
                ->firstOrFail();

            $occupancy = (int) $locked->current_occupancy;
            $delta = $direction === ShelterCheckinDirection::CheckIn ? $headcount : -$headcount;
            $next = $occupancy + $delta;

            if ($next < 0) {
                throw ValidationException::withMessages([
                    'headcount' => "Cannot check out {$headcount}: current occupancy is {$occupancy}.",
                ]);
            }

            $locked->current_occupancy = $next;
            $locked->save();

            $checkin = ShelterCheckin::query()->create([
                'organization_id' => $locked->organization_id,
                'shelter_site_id' => $locked->id,
                'beneficiary_id' => $beneficiary?->id,
                'direction' => $direction,
                'headcount' => $headcount,
                'checked_at' => $checkedAt ?? now(),
                'checked_by' => $actor?->getKey(),
                'notes' => $notes,
            ]);

            if ($beneficiary !== null && $direction === ShelterCheckinDirection::CheckIn) {
                $beneficiary->shelter_site_id = $locked->id;
                $beneficiary->save();
            }

            return [
                'checkin' => $checkin,
                'shelter' => $locked->fresh(),
                'capacity_warning' => $this->capacityWarning($locked),
            ];
        });
    }

    /**
     * Aggregate occupancy suitable for public surfaces (no personal data).
     *
     * @return array{
     *     shelter_count: int,
     *     total_capacity: int|null,
     *     total_occupancy: int,
     *     shelters: list<array{site_id: string, name: string, capacity: int|null, current_occupancy: int}>
     * }
     */
    public function publicAggregates(int $organizationId): array
    {
        $shelters = CriticalSite::query()
            ->forOrganization($organizationId)
            ->where('type', CriticalSiteType::Shelter->value)
            ->orderBy('name')
            ->get(['site_id', 'name', 'capacity', 'current_occupancy']);

        $totalCapacity = $shelters->contains(fn (CriticalSite $s) => $s->capacity === null)
            ? null
            : (int) $shelters->sum(fn (CriticalSite $s) => (int) $s->capacity);

        return [
            'shelter_count' => $shelters->count(),
            'total_capacity' => $totalCapacity,
            'total_occupancy' => (int) $shelters->sum(fn (CriticalSite $s) => (int) $s->current_occupancy),
            'shelters' => $shelters->map(fn (CriticalSite $s) => [
                'site_id' => $s->site_id,
                'name' => $s->name,
                'capacity' => $s->capacity !== null ? (int) $s->capacity : null,
                'current_occupancy' => (int) $s->current_occupancy,
            ])->values()->all(),
        ];
    }

    public function capacityWarning(CriticalSite $shelter): ?string
    {
        if ($shelter->capacity === null || (int) $shelter->capacity <= 0) {
            return null;
        }

        $occupancy = (int) $shelter->current_occupancy;
        $capacity = (int) $shelter->capacity;

        if ($occupancy > $capacity) {
            return 'exceeding';
        }

        if ($occupancy >= (int) ceil($capacity * self::APPROACHING_RATIO)) {
            return 'approaching';
        }

        return null;
    }
}
