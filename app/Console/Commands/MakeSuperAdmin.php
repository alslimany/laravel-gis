<?php

namespace App\Console\Commands;

use App\Models\PluginEvent;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * The only way to grant or revoke users.is_super_admin (ADR-0001 D1).
 */
class MakeSuperAdmin extends Command
{
    protected $signature = 'lumina:make-super-admin {email} {--revoke : Remove the super-admin flag}
        {--actor= : Email of the super admin doing this (optional: omitted = CLI, audited as actor=cli)}';

    protected $description = 'Grant (or revoke with --revoke) platform super admin for a user';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        $actor = null;
        if ($this->option('actor')) {
            $actor = User::query()->where('email', $this->option('actor'))->first();
            if (! $actor || ! $actor->isSuperAdmin()) {
                $this->error('--actor must be the email of an existing super admin.');

                return self::FAILURE;
            }
        }

        if (! $grant && $user->isSuperAdmin()
            && User::query()->where('is_super_admin', true)->count() <= 1) {
            $this->error('Refusing to remove the last super admin. Grant another super admin first.');

            return self::FAILURE;
        }

        $was = $user->isSuperAdmin();
        $user->forceFill(['is_super_admin' => $grant])->save();

        // Audited in plugin_events under the reserved "core" key (ADR-0001 D1/D9).
        PluginEvent::record('core', $grant ? PluginEvent::SUPER_ADMIN_GRANT : PluginEvent::SUPER_ADMIN_REVOKE, [
            'actor_id' => $actor?->getKey(),
            'meta' => ['user_id' => $user->getKey(), 'email' => $user->email, 'changed' => $was !== $grant],
        ]);

        $this->info($grant
            ? "{$user->email} is now a super admin."
            : "{$user->email} is no longer a super admin.");

        return self::SUCCESS;
    }
}
