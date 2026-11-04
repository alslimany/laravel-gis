<?php

namespace Lumina\Disaster\Support\Enums;

enum RegistryAccessAction: string
{
    case Read = 'read';
    case Export = 'export';
    case Update = 'update';
}
