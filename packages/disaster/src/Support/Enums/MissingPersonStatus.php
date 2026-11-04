<?php

namespace Lumina\Disaster\Support\Enums;

enum MissingPersonStatus: string
{
    case Missing = 'missing';
    case Found = 'found';
    case Reunited = 'reunited';
    case Closed = 'closed';
}
