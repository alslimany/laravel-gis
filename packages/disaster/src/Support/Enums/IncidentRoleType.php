<?php

namespace Lumina\Disaster\Support\Enums;

enum IncidentRoleType: string
{
    case IncidentCommander = 'incident_commander';
    case Operations = 'operations';
    case Planning = 'planning';
    case Logistics = 'logistics';
    case FinanceAdmin = 'finance_admin';
    case Safety = 'safety';
    case Liaison = 'liaison';
    case PublicInformation = 'public_information';
}
