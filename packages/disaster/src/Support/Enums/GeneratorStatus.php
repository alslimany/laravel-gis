<?php

namespace Lumina\Disaster\Support\Enums;

enum GeneratorStatus: string
{
    case Online = 'ONLINE';
    case Offline = 'OFFLINE';
    case Critical = 'CRITICAL';
}
