<?php

namespace Lumina\Disaster\Support\Enums;

use App\Support\EnumLabel;

enum Lifeline: string
{
    case SafetyAndSecurity = 'safety_and_security';
    case FoodHydrationShelter = 'food_hydration_shelter';
    case HealthAndMedical = 'health_and_medical';
    case Energy = 'energy';
    case Communications = 'communications';
    case Transportation = 'transportation';
    case HazardousMaterials = 'hazardous_materials';
    case WaterSystems = 'water_systems';

    public function label(): string
    {
        $translated = EnumLabel::for($this);
        if ($translated !== '' && $translated !== ucfirst(str_replace('_', ' ', $this->value))) {
            return $translated;
        }

        return match ($this) {
            self::SafetyAndSecurity => 'Safety and Security',
            self::FoodHydrationShelter => 'Food, Hydration, Shelter',
            self::HealthAndMedical => 'Health and Medical',
            self::Energy => 'Energy',
            self::Communications => 'Communications',
            self::Transportation => 'Transportation',
            self::HazardousMaterials => 'Hazardous Materials',
            self::WaterSystems => 'Water Systems',
        };
    }
}
