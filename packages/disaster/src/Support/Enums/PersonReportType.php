<?php

namespace Lumina\Disaster\Support\Enums;

enum PersonReportType: string
{
    case Sighting = 'sighting';
    case Claim = 'claim';
    case Found = 'found';
}
