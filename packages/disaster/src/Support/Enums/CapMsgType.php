<?php

namespace Lumina\Disaster\Support\Enums;

enum CapMsgType: string
{
    case Alert = 'Alert';
    case Update = 'Update';
    case Cancel = 'Cancel';
    case Ack = 'Ack';
}
