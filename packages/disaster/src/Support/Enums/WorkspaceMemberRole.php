<?php

namespace Lumina\Disaster\Support\Enums;

enum WorkspaceMemberRole: string
{
    case Viewer = 'viewer';
    case Collaborator = 'collaborator';
}
