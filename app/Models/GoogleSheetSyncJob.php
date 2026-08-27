<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $sheet_slug
 * @property string $batch_id
 * @property string $status
 * @property int $total_items
 * @property int $processed_items
 * @property int $successful_items
 * @property int $failed_items
 * @property int|null $current_serial
 * @property string|null $current_status_text
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property array<string, mixed>|null $logs
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'sheet_slug', 'batch_id', 'status', 'total_items', 'processed_items',
    'successful_items', 'failed_items', 'current_serial', 'current_status_text',
    'started_at', 'completed_at', 'logs',
])]
class GoogleSheetSyncJob extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'total_items' => 'integer',
            'processed_items' => 'integer',
            'successful_items' => 'integer',
            'failed_items' => 'integer',
            'current_serial' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'logs' => 'array',
        ];
    }
}
