<?php

namespace Lumina\Disaster\Support\Enums;

enum DisseminationStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Test = 'test';
}
