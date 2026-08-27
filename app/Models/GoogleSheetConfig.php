<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $spreadsheet_id
 * @property CarbonImmutable|null $last_synced_at
 * @property int $total_serials
 * @property int $synced_serials
 * @property int $pending_serials
 * @property int $failed_serials
 * @property string|null $webhook_secret
 * @property bool $auto_sync_on_webhook
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'slug', 'name', 'spreadsheet_id', 'webhook_secret', 'auto_sync_on_webhook', 'last_synced_at',
    'total_serials', 'synced_serials', 'pending_serials', 'failed_serials',
])]
class GoogleSheetConfig extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'immutable_datetime',
            'auto_sync_on_webhook' => 'boolean',
            'total_serials' => 'integer',
            'synced_serials' => 'integer',
            'pending_serials' => 'integer',
            'failed_serials' => 'integer',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(GoogleSheetLog::class, 'sheet_slug', 'slug');
    }
}
