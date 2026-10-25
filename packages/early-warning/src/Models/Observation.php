<?php

namespace Lumina\EarlyWarning\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/** Global satellite/reanalysis observation row (ADR D12). No organization_id. */
class Observation extends Model
{
    protected $table = 'ew_observations';

    protected $guarded = ['id'];

    protected $casts = [
        'observed_at' => 'datetime',
        'fetched_at' => 'datetime',
        'precipitation_mm' => 'float',
        'soil_moisture' => 'float',
        'props' => 'array',
    ];

    public function basin(): BelongsTo
    {
        return $this->belongsTo(Basin::class, 'basin_code', 'basin_code');
    }

    /**
     * Latest soil wetness (0–1) at or before $at for the basin.
     */
    public static function soilSaturationNear(string $basinCode, Carbon $at, ?string $source = null): ?float
    {
        $query = static::query()
            ->where('basin_code', $basinCode)
            ->whereNotNull('soil_moisture')
            ->where('observed_at', '<=', $at)
            ->where('observed_at', '>=', $at->copy()->subHours(36))
            ->orderByDesc('observed_at');

        if ($source) {
            $query->where('source', $source);
        }

        $row = $query->first(['soil_moisture']);

        return $row?->soil_moisture !== null ? (float) $row->soil_moisture : null;
    }

    /**
     * Sum observed precip over a trailing window ending at $endInclusive.
     *
     * @return array{sum: float, hours: int}
     */
    public static function precipWindow(string $basinCode, Carbon $endInclusive, int $hours, ?string $source = null): array
    {
        $from = $endInclusive->copy()->subHours($hours - 1)->startOfHour();
        $query = static::query()
            ->where('basin_code', $basinCode)
            ->whereBetween('observed_at', [$from, $endInclusive->copy()->endOfHour()])
            ->whereNotNull('precipitation_mm');

        if ($source) {
            $query->where('source', $source);
        }

        /** @var Collection<int, Observation> $rows */
        $rows = $query->get(['observed_at', 'precipitation_mm']);

        return [
            'sum' => round((float) $rows->sum('precipitation_mm'), 3),
            'hours' => $rows->count(),
        ];
    }
}
