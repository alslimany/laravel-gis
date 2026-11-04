<?php

namespace Lumina\Disaster\Support\Enums;

enum InventoryLedgerReason: string
{
    case In = 'in';
    case Out = 'out';
    case Adjust = 'adjust';
}
