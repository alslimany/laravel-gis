<?php

namespace Lumina\Disaster\Support\Enums;

enum VolunteerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Deployed = 'deployed';
}
