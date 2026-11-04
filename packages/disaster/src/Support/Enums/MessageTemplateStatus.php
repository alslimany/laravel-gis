<?php

namespace Lumina\Disaster\Support\Enums;

enum MessageTemplateStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Approved = 'approved';
}
