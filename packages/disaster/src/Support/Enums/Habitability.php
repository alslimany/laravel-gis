<?php

namespace Lumina\Disaster\Support\Enums;

enum Habitability: string
{
    case Habitable = 'habitable';
    case Limited = 'limited';
    case Uninhabitable = 'uninhabitable';
}
