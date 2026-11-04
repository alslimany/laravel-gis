<?php

namespace Lumina\Disaster\Services;

use App\Models\User;
use App\Support\EnumLabel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lumina\Disaster\Models\DamagePhoto;
use Lumina\Disaster\Models\DamagePoint;
use Lumina\Disaster\Models\LifelineStatus;
use Lumina\Disaster\Support\Enums\DamageReviewStatus;
use Lumina\Disaster\Support\Enums\LifelineComponentStatus;
use Lumina\Disaster\Support\GeometryWriter;

final class DamageAssessmentWorkflow
{
    public const MAX_PHOTOS = 5;

    public const PHOTO_DISK = 'local';

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $photos
     */
    public function submit(User $actor, array $data, array $photos = []): DamagePoint
    {
        abort_unless(
            Gate::forUser($actor)->any(['disaster.manage', 'disaster.field']),
            403,
        );

        if (count($photos) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photos' => __('disaster.damage.max_photos', ['count' => self::MAX_PHOTOS]),
            ]);
        }

        return DB::transaction(function () use ($actor, $data, $photos) {
            $point = DamagePoint::query()->create([
                'organization_id' => (int) $actor->organization_id,
                'external_id' => $data['external_id'] ?? null,
                'damage_level' => $data['damage_level'],
                'structure_type' => $data['structure_type'] ?? null,
                'habitability' => $data['habitability'] ?? null,
                'hazards_present' => $data['hazards_present'] ?? null,
                'urgent_needs' => $data['urgent_needs'] ?? null,
                'people_affected' => $data['people_affected'] ?? null,
                'notes' => $data['notes'] ?? null,
                'props' => $data['props'] ?? null,
                'incident_id' => $data['incident_id'] ?? null,
                'lifeline_component_id' => $data['lifeline_component_id'] ?? null,
                'form_schema_version' => $data['form_schema_version'] ?? '1.0',
                'review_status' => DamageReviewStatus::Submitted,
                'assessed_by' => $data['assessed_by'] ?? $actor->getKey(),
                'assessed_at' => $data['assessed_at'] ?? now(),
                'geom' => isset($data['latitude'], $data['longitude'])
                    ? GeometryWriter::pointFromCoordinates($data['latitude'], $data['longitude'])
                    : null,
            ]);

            $this->storePhotos($point, $actor, $photos);

            return $point->fresh(['photos']);
        });
    }

    public function transition(
        DamagePoint $point,
        DamageReviewStatus $to,
        User $actor,
        ?string $comments = null,
    ): DamagePoint {
        abort_unless(Gate::forUser($actor)->allows('disaster.manage'), 403);

        $from = $point->review_status instanceof DamageReviewStatus
            ? $point->review_status
            : DamageReviewStatus::from((string) $point->review_status);

        if (! $from->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'review_status' => __('disaster.damage.cannot_transition', ['from' => EnumLabel::for($from), 'to' => EnumLabel::for($to)]),
            ]);
        }

        return DB::transaction(function () use ($point, $to, $actor, $comments) {
            $point->review_status = $to;
            $point->reviewed_by = $actor->getKey();
            $point->reviewed_at = now();
            if ($comments !== null) {
                $point->reviewer_comments = $comments;
            }
            $point->save();

            if ($to === DamageReviewStatus::Verified && $point->lifeline_component_id) {
                $this->linkVerifiedToLifeline($point, $actor);
            }

            return $point->fresh(['photos', 'lifelineComponent']);
        });
    }

    public function markReviewed(DamagePoint $point, User $actor, ?string $comments = null): DamagePoint
    {
        return $this->transition($point, DamageReviewStatus::Reviewed, $actor, $comments);
    }

    public function verify(DamagePoint $point, User $actor, ?string $comments = null): DamagePoint
    {
        return $this->transition($point, DamageReviewStatus::Verified, $actor, $comments);
    }

    public function reject(DamagePoint $point, User $actor, ?string $comments = null): DamagePoint
    {
        return $this->transition($point, DamageReviewStatus::Rejected, $actor, $comments);
    }

    /**
     * @param  list<UploadedFile>  $photos
     */
    protected function storePhotos(DamagePoint $point, User $actor, array $photos): void
    {
        foreach (array_values($photos) as $index => $file) {
            $directory = "disaster/damage/{$point->organization_id}/{$point->id}";
            $storedName = Str::uuid().'_'.$file->getClientOriginalName();
            $path = $file->storeAs($directory, $storedName, self::PHOTO_DISK);

            DamagePhoto::query()->create([
                'organization_id' => $point->organization_id,
                'damage_point_id' => $point->id,
                'storage_path' => $path,
                'sort_order' => $index,
                'uploaded_by' => $actor->getKey(),
                'created_at' => now(),
            ]);
        }
    }

    protected function linkVerifiedToLifeline(DamagePoint $point, User $actor): void
    {
        $level = $point->damage_level instanceof \BackedEnum
            ? $point->damage_level->value
            : (string) $point->damage_level;

        LifelineStatus::query()->create([
            'organization_id' => $point->organization_id,
            'component_id' => $point->lifeline_component_id,
            'status' => LifelineComponentStatus::Impacted,
            'reason' => "Verified damage assessment #{$point->id} ({$level})",
            'reported_by' => $actor->getKey(),
            'reported_at' => now(),
        ]);
    }

    public function deleteStoredPhotos(DamagePoint $point): void
    {
        foreach ($point->photos as $photo) {
            if ($photo->storage_path && Storage::disk(self::PHOTO_DISK)->exists($photo->storage_path)) {
                Storage::disk(self::PHOTO_DISK)->delete($photo->storage_path);
            }
        }
    }
}
