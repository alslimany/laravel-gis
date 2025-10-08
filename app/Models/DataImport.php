<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'organization_id',
        'file_name',
        'file_path',
        'file_type',
        'file_size',
        'status',
        'table_name',
        'geometry_type',
        'feature_count',
        'progress',
        'error_message',
        'metadata',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'file_size' => 'integer',
        'feature_count' => 'integer',
        'progress' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(string $tableName, string $geometryType, int $featureCount): void
    {
        $this->update([
            'status' => 'completed',
            'table_name' => $tableName,
            'geometry_type' => $geometryType,
            'feature_count' => $featureCount,
            'progress' => 100,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    public function updateProgress(int $progress): void
    {
        $this->update([
            'progress' => min(100, max(0, $progress)),
        ]);
    }
}
