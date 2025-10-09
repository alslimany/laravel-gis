<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectComment extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'project_id',
        'user_id',
        'content',
        'parent_id',
    ];

    /**
     * Get the project that owns the comment.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the user that wrote the comment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parent comment.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProjectComment::class, 'parent_id');
    }

    /**
     * Get the replies to this comment.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(ProjectComment::class, 'parent_id');
    }
}
