<?php

namespace Lumina\Disaster\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Lumina\Disaster\Models\Beneficiary;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\RegistryAccessLog;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\Enums\RegistryAccessAction;

/**
 * Beneficiary registry with mandatory access auditing for personal-record reads/exports.
 */
final class BeneficiaryRegistryService
{
    /**
     * @param  array{
     *     household_code: string,
     *     household_size?: int,
     *     needs?: array<mixed>|null,
     *     shelter_site_id?: int|null,
     *     contact_ref?: string|null,
     *     props?: array<string, mixed>|null,
     *     retained_until?: string|\DateTimeInterface|null
     * }  $data
     */
    public function create(int $organizationId, array $data, ?User $actor = null, ?string $ip = null): Beneficiary
    {
        $this->assertShelter($organizationId, $data['shelter_site_id'] ?? null);

        $beneficiary = Beneficiary::query()->create([
            'organization_id' => $organizationId,
            'household_code' => $data['household_code'],
            'household_size' => max(1, (int) ($data['household_size'] ?? 1)),
            'needs' => $data['needs'] ?? null,
            'shelter_site_id' => $data['shelter_site_id'] ?? null,
            'contact_ref' => $data['contact_ref'] ?? null,
            'props' => $data['props'] ?? null,
            'retained_until' => $data['retained_until'] ?? null,
        ]);

        if ($actor !== null) {
            $this->audit($actor, $beneficiary, RegistryAccessAction::Update, $ip);
        }

        return $beneficiary;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Beneficiary $beneficiary, array $data, User $actor, ?string $ip = null): Beneficiary
    {
        if (array_key_exists('shelter_site_id', $data)) {
            $this->assertShelter((int) $beneficiary->organization_id, $data['shelter_site_id']);
        }

        $beneficiary->fill($data)->save();
        $this->audit($actor, $beneficiary, RegistryAccessAction::Update, $ip);

        return $beneficiary->fresh();
    }

    public function findForRead(int $organizationId, int $id, User $actor, ?string $ip = null): Beneficiary
    {
        $beneficiary = Beneficiary::query()
            ->forOrganization($organizationId)
            ->whereKey($id)
            ->firstOrFail();

        $this->audit($actor, $beneficiary, RegistryAccessAction::Read, $ip);

        return $beneficiary;
    }

    /**
     * @return Collection<int, Beneficiary>
     */
    public function listForOrganization(int $organizationId, User $actor, ?string $ip = null): Collection
    {
        $rows = Beneficiary::query()
            ->forOrganization($organizationId)
            ->orderBy('household_code')
            ->get();

        foreach ($rows as $row) {
            $this->audit($actor, $row, RegistryAccessAction::Read, $ip);
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function export(int $organizationId, User $actor, ?string $ip = null): array
    {
        $rows = Beneficiary::query()
            ->forOrganization($organizationId)
            ->orderBy('household_code')
            ->get();

        $payload = [];
        foreach ($rows as $row) {
            $this->audit($actor, $row, RegistryAccessAction::Export, $ip);
            $payload[] = $this->present($row, includeContactRef: false);
        }

        return $payload;
    }

    /**
     * Safe presentation: contact_ref omitted unless explicitly requested by a privileged caller.
     *
     * @return array<string, mixed>
     */
    public function present(Beneficiary $beneficiary, bool $includeContactRef = false): array
    {
        $data = [
            'id' => $beneficiary->id,
            'household_code' => $beneficiary->household_code,
            'household_size' => (int) $beneficiary->household_size,
            'needs' => $beneficiary->needs ?? [],
            'shelter_site_id' => $beneficiary->shelter_site_id,
            'props' => $beneficiary->props ?? [],
            'retained_until' => $beneficiary->retained_until?->toDateString(),
            'has_contact_ref' => filled($beneficiary->getAttribute('contact_ref')),
        ];

        if ($includeContactRef) {
            $data['contact_ref'] = $beneficiary->getAttribute('contact_ref');
        }

        return $data;
    }

    protected function audit(User $actor, Beneficiary $beneficiary, RegistryAccessAction $action, ?string $ip): void
    {
        RegistryAccessLog::record(
            (int) $beneficiary->organization_id,
            (int) $actor->getKey(),
            Beneficiary::class,
            (int) $beneficiary->id,
            $action,
            $ip,
        );
    }

    protected function assertShelter(int $organizationId, mixed $shelterSiteId): void
    {
        if ($shelterSiteId === null || $shelterSiteId === '') {
            return;
        }

        $exists = CriticalSite::query()
            ->forOrganization($organizationId)
            ->whereKey((int) $shelterSiteId)
            ->where('type', CriticalSiteType::Shelter->value)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'shelter_site_id' => 'Shelter site not found for this organization.',
            ]);
        }
    }
}
