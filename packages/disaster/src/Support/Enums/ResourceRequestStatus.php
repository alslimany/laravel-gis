<?php

namespace Lumina\Disaster\Support\Enums;

enum ResourceRequestStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Matched = 'matched';
    case Dispatched = 'dispatched';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Cancelled],
            self::Approved => [self::Matched, self::Cancelled],
            self::Matched => [self::Dispatched, self::Cancelled],
            self::Dispatched => [self::Delivered, self::Cancelled],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
