<?php

namespace Lumina\Disaster\Support\Enums;

enum InfrastructureConditionGrade: string
{
    case Good = 'good';
    case Fair = 'fair';
    case Poor = 'poor';
    case Critical = 'critical';
}
