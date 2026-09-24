<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $sheet_slug
 * @property string $record_type
 * @property string $source_key
 * @property string|null $source_hash
 * @property array<string, mixed>|null $raw_data
 * @property string $status
 * @property array<string, mixed>|null $validation_errors
 * @property string|null $target_type
 * @property int|null $target_id
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'sheet_slug', 'record_type', 'source_key', 'source_hash', 'raw_data',
    'status', 'validation_errors', 'target_type', 'target_id', 'synced_at',
])]
class GoogleSheetSyncRecord extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'validation_errors' => 'array',
            'synced_at' => 'immutable_datetime',
            'target_id' => 'integer',
        ];
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(GoogleSheetConfig::class, 'sheet_slug', 'slug');
    }
}
