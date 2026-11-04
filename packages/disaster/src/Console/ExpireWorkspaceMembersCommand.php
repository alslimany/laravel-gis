<?php

namespace Lumina\Disaster\Console;

use Illuminate\Console\Command;
use Lumina\Disaster\Services\IncidentWorkspaceAccessService;

class ExpireWorkspaceMembersCommand extends Command
{
    protected $signature = 'disaster:expire-workspace-members';

    protected $description = 'Revoke incident workspace memberships whose expires_at has passed';

    public function handle(IncidentWorkspaceAccessService $access): int
    {
        $count = $access->expireMembers();
        $this->info("Expired {$count} workspace membership(s).");

        return self::SUCCESS;
    }
}
