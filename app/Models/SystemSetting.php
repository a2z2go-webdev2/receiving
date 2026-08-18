<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $key
 * @property array{value: mixed} $value
 * @property int|null $updated_by
 */
#[Fillable(['key', 'value', 'updated_by'])]
class SystemSetting extends Model
{
    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
