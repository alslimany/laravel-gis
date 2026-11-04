<?php

namespace Lumina\Disaster\Support\Enums;

enum ResourceRequestPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
