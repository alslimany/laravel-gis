<?php

namespace Lumina\Disaster\Support\Enums;

enum CriticalSiteType: string
{
    case Shelter = 'shelter';
    case Bridge = 'bridge';
    case PowerPlant = 'power_plant';
    case SupplyDepot = 'supply_depot';
    case Dam = 'dam';
    case Reservoir = 'reservoir';
    case WaterPlant = 'water_plant';
    case WastewaterPlant = 'wastewater_plant';
    case TelecomTower = 'telecom_tower';
    case FuelStation = 'fuel_station';
    case Airport = 'airport';
    case Port = 'port';
    case HospitalField = 'hospital_field';
}
