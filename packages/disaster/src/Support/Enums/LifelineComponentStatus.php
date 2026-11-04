<?php

namespace Lumina\Disaster\Support\Enums;

enum LifelineComponentStatus: string
{
    case Stable = 'stable';
    case AtRisk = 'at_risk';
    case Impacted = 'impacted';
    case Unknown = 'unknown';

    /**
     * Higher = worse for roll-up (impacted wins over at_risk, etc.).
     */
    public function severityRank(): int
    {
        return match ($this) {
            self::Stable => 0,
            self::Unknown => 1,
            self::AtRisk => 2,
            self::Impacted => 3,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Stable => 'green',
            self::AtRisk => 'amber',
            self::Impacted => 'red',
            self::Unknown => 'gray',
        };
    }

    /**
     * @param  list<self>  $statuses
     */
    public static function rollUp(array $statuses): self
    {
        if ($statuses === []) {
            return self::Unknown;
        }

        $worst = self::Stable;
        foreach ($statuses as $status) {
            if ($status->severityRank() > $worst->severityRank()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
