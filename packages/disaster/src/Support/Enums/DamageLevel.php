<?php

namespace Lumina\Disaster\Support\Enums;

enum DamageLevel: string
{
    case None = 'none';
    case Minor = 'minor';
    case Moderate = 'moderate';
    case Severe = 'severe';
    case Destroyed = 'destroyed';
}
