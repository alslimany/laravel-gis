<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * The only way to grant or revoke users.is_super_admin (ADR-0001 D1).
 */
class MakeSuperAdmin extends Command
{
    protected $signature = 'lumina:make-super-admin {email} {--revoke : Remove the super-admin flag}';

    protected $description = 'Grant (or revoke with --revoke) platform super admin for a user';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        $user->forceFill(['is_super_admin' => $grant])->save();

        $this->info($grant
            ? "{$user->email} is now a super admin."
            : "{$user->email} is no longer a super admin.");

        return self::SUCCESS;
    }
}
