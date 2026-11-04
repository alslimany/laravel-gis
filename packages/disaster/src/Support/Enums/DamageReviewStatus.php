<?php

namespace Lumina\Disaster\Support\Enums;

enum DamageReviewStatus: string
{
    case Submitted = 'submitted';
    case Reviewed = 'reviewed';
    case Verified = 'verified';
    case Rejected = 'rejected';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::Reviewed],
            self::Reviewed => [self::Verified, self::Rejected],
            self::Verified, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isOfficial(): bool
    {
        return $this === self::Verified;
    }
}
