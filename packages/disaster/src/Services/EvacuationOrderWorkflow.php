<?php

namespace Lumina\Disaster\Services;

use App\Models\User;
use App\Support\EnumLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\EvacuationOrder;
use Lumina\Disaster\Models\EvacuationOrderEvent;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\Enums\EvacuationOrderStatus;

final class EvacuationOrderWorkflow
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function transition(
        EvacuationOrder $order,
        EvacuationOrderStatus $to,
        User $actor,
        string $action,
        array $meta = [],
    ): EvacuationOrder {
        $from = $order->status instanceof EvacuationOrderStatus
            ? $order->status
            : EvacuationOrderStatus::from((string) $order->status);

        if (! $from->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => __('disaster.evac.cannot_transition', [
                    'from' => EnumLabel::for($from),
                    'to' => EnumLabel::for($to),
                ]),
            ]);
        }

        $this->authorizeTransition($from, $to, $order, $actor);

        return DB::transaction(function () use ($order, $from, $to, $actor, $action, $meta) {
            $order->status = $to;

            if ($to === EvacuationOrderStatus::Approved) {
                $order->approved_by = $actor->getKey();
            }

            if ($to === EvacuationOrderStatus::Issued) {
                $order->issued_at = $order->issued_at ?? now();
            }

            $order->updated_by = $actor->getKey();
            $order->save();

            EvacuationOrderEvent::query()->create([
                'organization_id' => $order->organization_id,
                'evacuation_order_id' => $order->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'action' => $action,
                'user_id' => $actor->getKey(),
                'meta' => $meta === [] ? null : $meta,
                'created_at' => now(),
            ]);

            return $order->refresh();
        });
    }

    /**
     * @param  array<int, int>  $shelterRemaining  site id => remaining capacity
     */
    public function updateExecution(
        EvacuationOrder $order,
        User $actor,
        ?int $evacuatedCount = null,
        ?int $unaccountedCount = null,
        ?int $estimatedPopulation = null,
        array $shelterRemaining = [],
    ): EvacuationOrder {
        $status = $order->status instanceof EvacuationOrderStatus
            ? $order->status
            : EvacuationOrderStatus::from((string) $order->status);

        if (! in_array($status, [
            EvacuationOrderStatus::Issued,
            EvacuationOrderStatus::InProgress,
            EvacuationOrderStatus::Completed,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => __('disaster.evac.counters_after_issue'),
            ]);
        }

        abort_unless(
            Gate::forUser($actor)->any(['disaster.manage', 'disaster.field']),
            403,
        );

        return DB::transaction(function () use (
            $order,
            $actor,
            $evacuatedCount,
            $unaccountedCount,
            $estimatedPopulation,
            $shelterRemaining,
        ) {
            if ($evacuatedCount !== null) {
                $order->evacuated_count = $evacuatedCount;
            }
            if ($unaccountedCount !== null) {
                $order->unaccounted_count = $unaccountedCount;
            }
            if ($estimatedPopulation !== null) {
                $order->estimated_population = $estimatedPopulation;
            }

            $order->updated_by = $actor->getKey();
            $order->save();

            $this->syncShelterRemainingCapacity($order, $shelterRemaining);

            EvacuationOrderEvent::query()->create([
                'organization_id' => $order->organization_id,
                'evacuation_order_id' => $order->id,
                'from_status' => $order->status->value,
                'to_status' => $order->status->value,
                'action' => 'update_execution',
                'user_id' => $actor->getKey(),
                'meta' => [
                    'evacuated_count' => $order->evacuated_count,
                    'unaccounted_count' => $order->unaccounted_count,
                    'estimated_population' => $order->estimated_population,
                    'shelter_remaining' => $shelterRemaining === [] ? null : $shelterRemaining,
                ],
                'created_at' => now(),
            ]);

            return $order->refresh();
        });
    }

    /**
     * @param  array<int, int>  $shelterRemaining
     */
    public function syncShelterRemainingCapacity(EvacuationOrder $order, array $shelterRemaining = []): void
    {
        if (! Schema::hasColumn('dm_critical_sites', 'remaining_capacity')) {
            return;
        }

        $linkedIds = collect($order->shelter_site_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($linkedIds === [] && $shelterRemaining === []) {
            return;
        }

        if ($shelterRemaining !== []) {
            foreach ($shelterRemaining as $siteId => $remaining) {
                $siteId = (int) $siteId;
                if (! in_array($siteId, $linkedIds, true) && $linkedIds !== []) {
                    continue;
                }

                CriticalSite::query()
                    ->forOrganization((int) $order->organization_id)
                    ->where('id', $siteId)
                    ->where('type', CriticalSiteType::Shelter->value)
                    ->update(['remaining_capacity' => max(0, (int) $remaining)]);
            }

            return;
        }

        // Default: when a single linked shelter has capacity, derive remaining from evacuated count.
        if (count($linkedIds) === 1) {
            $site = CriticalSite::query()
                ->forOrganization((int) $order->organization_id)
                ->where('id', $linkedIds[0])
                ->where('type', CriticalSiteType::Shelter->value)
                ->first();

            if ($site !== null && $site->capacity !== null) {
                $site->remaining_capacity = max(0, (int) $site->capacity - (int) $order->evacuated_count);
                $site->save();
            }
        }
    }

    protected function authorizeTransition(
        EvacuationOrderStatus $from,
        EvacuationOrderStatus $to,
        EvacuationOrder $order,
        User $actor,
    ): void {
        if ($to === EvacuationOrderStatus::Approved) {
            abort_unless(Gate::forUser($actor)->allows('disaster.approve_evacuation'), 403);

            if ((int) $order->authored_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages([
                    'status' => __('disaster.evac.no_self_approval'),
                ]);
            }

            return;
        }

        if ($to === EvacuationOrderStatus::Issued) {
            if ($from !== EvacuationOrderStatus::Approved) {
                throw ValidationException::withMessages([
                    'status' => __('disaster.evac.issue_needs_approval'),
                ]);
            }

            abort_unless(
                Gate::forUser($actor)->any(['disaster.manage', 'disaster.dispatch']),
                403,
            );

            return;
        }

        if (in_array($to, [EvacuationOrderStatus::InProgress, EvacuationOrderStatus::Completed], true)) {
            abort_unless(
                Gate::forUser($actor)->any(['disaster.manage', 'disaster.field']),
                403,
            );

            return;
        }

        // submit for approval, return to draft, cancel
        abort_unless(
            Gate::forUser($actor)->any(['disaster.manage', 'disaster.dispatch']),
            403,
        );
    }
}
