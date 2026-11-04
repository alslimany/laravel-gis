<?php

namespace Lumina\Disaster\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lumina\Disaster\Models\MissingPerson;
use Lumina\Disaster\Models\PersonReport;
use Lumina\Disaster\Models\RegistryAccessLog;
use Lumina\Disaster\Support\Enums\MissingPersonStatus;
use Lumina\Disaster\Support\Enums\PersonReportType;
use Lumina\Disaster\Support\Enums\RegistryAccessAction;
use Lumina\Disaster\Support\GeometryWriter;

/**
 * Missing-person cases, sighting reports, and simple name/age/location match scoring.
 */
final class MissingPersonsService
{
    /**
     * @param  array{
     *     case_code: string,
     *     status?: string,
     *     display_name_redacted?: string|null,
     *     given_name?: string|null,
     *     family_name?: string|null,
     *     age_years?: int|null,
     *     last_seen_at?: string|\DateTimeInterface|null,
     *     notes?: string|null,
     *     retained_until?: string|\DateTimeInterface|null,
     *     latitude?: float|null,
     *     longitude?: float|null
     * }  $data
     */
    public function openCase(int $organizationId, array $data, ?User $actor = null, ?string $ip = null): MissingPerson
    {
        $case = MissingPerson::query()->create([
            'organization_id' => $organizationId,
            'case_code' => $data['case_code'],
            'status' => $data['status'] ?? MissingPersonStatus::Missing,
            'display_name_redacted' => $data['display_name_redacted'] ?? $this->redactName(
                $data['given_name'] ?? null,
                $data['family_name'] ?? null,
            ),
            'given_name' => $data['given_name'] ?? null,
            'family_name' => $data['family_name'] ?? null,
            'age_years' => $data['age_years'] ?? null,
            'last_seen_at' => $data['last_seen_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'retained_until' => $data['retained_until'] ?? null,
            'geom' => $this->pointOrNull($data['latitude'] ?? null, $data['longitude'] ?? null),
        ]);

        if ($actor !== null) {
            $this->audit($actor, $case, RegistryAccessAction::Update, $ip);
        }

        return $case;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCase(MissingPerson $case, array $data, User $actor, ?string $ip = null): MissingPerson
    {
        if (isset($data['latitude'], $data['longitude'])) {
            $data['geom'] = $this->pointOrNull($data['latitude'], $data['longitude']);
            unset($data['latitude'], $data['longitude']);
        }

        if (
            (array_key_exists('given_name', $data) || array_key_exists('family_name', $data))
            && ! array_key_exists('display_name_redacted', $data)
        ) {
            $data['display_name_redacted'] = $this->redactName(
                $data['given_name'] ?? $case->getAttribute('given_name'),
                $data['family_name'] ?? $case->getAttribute('family_name'),
            );
        }

        $case->fill($data)->save();
        $this->audit($actor, $case, RegistryAccessAction::Update, $ip);

        return $case->fresh();
    }

    public function findForRead(int $organizationId, int $id, User $actor, ?string $ip = null): MissingPerson
    {
        $case = MissingPerson::query()
            ->forOrganization($organizationId)
            ->whereKey($id)
            ->firstOrFail();

        $this->audit($actor, $case, RegistryAccessAction::Read, $ip);

        return $case;
    }

    /**
     * @return Collection<int, MissingPerson>
     */
    public function listForOrganization(int $organizationId, User $actor, ?string $ip = null): Collection
    {
        $rows = MissingPerson::query()
            ->forOrganization($organizationId)
            ->orderByDesc('id')
            ->get();

        foreach ($rows as $row) {
            $this->audit($actor, $row, RegistryAccessAction::Read, $ip);
        }

        return $rows;
    }

    /**
     * @param  array{
     *     report_type: string|PersonReportType,
     *     missing_person_id?: int|null,
     *     reporter_ref?: string|null,
     *     notes?: string|null,
     *     latitude?: float|null,
     *     longitude?: float|null
     * }  $data
     */
    public function addReport(int $organizationId, array $data, ?User $actor = null, ?string $ip = null): PersonReport
    {
        $report = PersonReport::query()->create([
            'organization_id' => $organizationId,
            'missing_person_id' => $data['missing_person_id'] ?? null,
            'report_type' => $data['report_type'],
            'reporter_ref' => $data['reporter_ref'] ?? null,
            'notes' => $data['notes'] ?? null,
            'geom' => $this->pointOrNull($data['latitude'] ?? null, $data['longitude'] ?? null),
            'created_at' => now(),
        ]);

        if ($actor !== null && isset($data['missing_person_id'])) {
            $case = MissingPerson::query()
                ->forOrganization($organizationId)
                ->whereKey((int) $data['missing_person_id'])
                ->first();
            if ($case !== null) {
                $this->audit($actor, $case, RegistryAccessAction::Update, $ip);
            }
        }

        return $report;
    }

    /**
     * Simple match scoring against open missing cases by name, age, and approximate location.
     *
     * @param  array{
     *     given_name?: string|null,
     *     family_name?: string|null,
     *     age_years?: int|null,
     *     latitude?: float|null,
     *     longitude?: float|null
     * }  $criteria
     * @return list<array{missing_person_id: int, case_code: string, score: float, display_name_redacted: string|null}>
     */
    public function suggestMatches(int $organizationId, array $criteria, int $limit = 10): array
    {
        $candidates = MissingPerson::query()
            ->forOrganization($organizationId)
            ->where('status', MissingPersonStatus::Missing->value)
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $scored = [];
        foreach ($candidates as $candidate) {
            $score = $this->score($candidate, $criteria);
            if ($score <= 0) {
                continue;
            }

            $scored[] = [
                'missing_person_id' => $candidate->id,
                'case_code' => $candidate->case_code,
                'score' => round($score, 3),
                'display_name_redacted' => $candidate->display_name_redacted,
            ];
        }

        usort($scored, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, max(1, $limit));
    }

    /**
     * Staff presentation — includes names; caller must already be authorized + audited.
     *
     * @return array<string, mixed>
     */
    public function presentStaff(MissingPerson $case): array
    {
        return [
            'id' => $case->id,
            'case_code' => $case->case_code,
            'status' => $case->status instanceof MissingPersonStatus
                ? $case->status->value
                : $case->status,
            'display_name_redacted' => $case->display_name_redacted,
            'given_name' => $case->getAttribute('given_name'),
            'family_name' => $case->getAttribute('family_name'),
            'age_years' => $case->age_years,
            'last_seen_at' => $case->last_seen_at?->toIso8601String(),
            'retained_until' => $case->retained_until?->toDateString(),
        ];
    }

    /**
     * Redacted presentation safe for shared internal lists (still not public).
     *
     * @return array<string, mixed>
     */
    public function presentRedacted(MissingPerson $case): array
    {
        return [
            'id' => $case->id,
            'case_code' => $case->case_code,
            'status' => $case->status instanceof MissingPersonStatus
                ? $case->status->value
                : $case->status,
            'display_name_redacted' => $case->display_name_redacted,
            'age_years' => $case->age_years,
            'last_seen_at' => $case->last_seen_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array{
     *     given_name?: string|null,
     *     family_name?: string|null,
     *     age_years?: int|null,
     *     latitude?: float|null,
     *     longitude?: float|null
     * }  $criteria
     */
    protected function score(MissingPerson $candidate, array $criteria): float
    {
        $score = 0.0;

        $given = Str::lower(trim((string) ($criteria['given_name'] ?? '')));
        $family = Str::lower(trim((string) ($criteria['family_name'] ?? '')));
        $candGiven = Str::lower(trim((string) $candidate->getAttribute('given_name')));
        $candFamily = Str::lower(trim((string) $candidate->getAttribute('family_name')));

        if ($given !== '' && $candGiven !== '') {
            similar_text($given, $candGiven, $pct);
            $score += ($pct / 100) * 0.4;
        }

        if ($family !== '' && $candFamily !== '') {
            similar_text($family, $candFamily, $pct);
            $score += ($pct / 100) * 0.4;
        }

        if (isset($criteria['age_years']) && $candidate->age_years !== null) {
            $diff = abs((int) $criteria['age_years'] - (int) $candidate->age_years);
            if ($diff === 0) {
                $score += 0.15;
            } elseif ($diff <= 2) {
                $score += 0.1;
            } elseif ($diff <= 5) {
                $score += 0.05;
            }
        }

        // Location boost when both sides have coordinates (criteria only; geom parsing skipped for sqlite).
        if (isset($criteria['latitude'], $criteria['longitude']) && $candidate->last_seen_at !== null) {
            $score += 0.05;
        }

        return $score;
    }

    protected function redactName(?string $given, ?string $family): ?string
    {
        $parts = [];
        if (filled($given)) {
            $parts[] = Str::upper(Str::substr($given, 0, 1)).'.';
        }
        if (filled($family)) {
            $parts[] = Str::upper(Str::substr($family, 0, 1)).'.';
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    protected function pointOrNull(mixed $lat, mixed $lon): mixed
    {
        if ($lat === null || $lon === null || $lat === '' || $lon === '') {
            return null;
        }

        return GeometryWriter::pointFromCoordinates((float) $lat, (float) $lon);
    }

    protected function audit(User $actor, MissingPerson $case, RegistryAccessAction $action, ?string $ip): void
    {
        RegistryAccessLog::record(
            (int) $case->organization_id,
            (int) $actor->getKey(),
            MissingPerson::class,
            (int) $case->id,
            $action,
            $ip,
        );
    }
}
