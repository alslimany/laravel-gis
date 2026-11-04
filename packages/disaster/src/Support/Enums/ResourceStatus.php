<?php

namespace Lumina\Disaster\Support\Enums;

enum ResourceStatus: string
{
    case Available = '01_Available';
    case Dispatched = '02_Dispatched';
    case EnRoute = '03_En_Route';
    case OnScene = '04_On_Scene';
    case Staged = '05_Staged';
    case OutOfService = '06_Out_of_Service';
}
