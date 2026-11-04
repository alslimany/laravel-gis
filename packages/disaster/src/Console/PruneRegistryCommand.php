<?php

namespace Lumina\Disaster\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Lumina\Disaster\Models\Beneficiary;
use Lumina\Disaster\Models\MissingPerson;
use Lumina\Disaster\Models\PersonReport;

/**
 * Retention prune for registry personal data past retained_until.
 * Deletes expired beneficiaries and missing-person cases (reports cascade/null).
 */
class PruneRegistryCommand extends Command
{
    protected $signature = 'disaster:prune-registry {--dry-run : Report counts without deleting}';

    protected $description = 'Delete or anonymize registry rows past retained_until';

    public function handle(): int
    {
        $today = now()->toDateString();
        $dryRun = (bool) $this->option('dry-run');

        $beneficiaryQuery = Beneficiary::query()
            ->whereNotNull('retained_until')
            ->whereDate('retained_until', '<', $today);

        $missingQuery = MissingPerson::query()
            ->whereNotNull('retained_until')
            ->whereDate('retained_until', '<', $today);

        $beneficiaryCount = (clone $beneficiaryQuery)->count();
        $missingCount = (clone $missingQuery)->count();

        if ($dryRun) {
            $this->info("Dry run: would prune {$beneficiaryCount} beneficiary(ies) and {$missingCount} missing-person case(s).");

            return self::SUCCESS;
        }

        $pruned = DB::transaction(function () use ($beneficiaryQuery, $missingQuery) {
            $missingIds = (clone $missingQuery)->pluck('id');

            $reports = 0;
            if ($missingIds->isNotEmpty()) {
                $reports = PersonReport::query()
                    ->whereIn('missing_person_id', $missingIds)
                    ->delete();
            }

            $beneficiaries = $beneficiaryQuery->delete();
            $missing = $missingQuery->delete();

            return compact('beneficiaries', 'missing', 'reports');
        });

        $this->info(sprintf(
            'Pruned %d beneficiary(ies), %d missing-person case(s), %d related report(s).',
            $pruned['beneficiaries'],
            $pruned['missing'],
            $pruned['reports'],
        ));

        return self::SUCCESS;
    }
}
