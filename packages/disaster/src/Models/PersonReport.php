<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;
use Lumina\Disaster\Support\Enums\PersonReportType;

class PersonReport extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_person_reports';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = [
        'reporter_ref',
        'geom',
        'notes',
    ];

    protected $casts = [
        'report_type' => PersonReportType::class,
        'created_at' => 'datetime',
    ];

    public function missingPerson(): BelongsTo
    {
        return $this->belongsTo(MissingPerson::class, 'missing_person_id');
    }
}
