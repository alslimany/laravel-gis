<?php

namespace Lumina\Disaster\Support\Enums;

enum WarningAckResponse: string
{
    case Acknowledged = 'acknowledged';
    case Escalated = 'escalated';
    case Declined = 'declined';
}
