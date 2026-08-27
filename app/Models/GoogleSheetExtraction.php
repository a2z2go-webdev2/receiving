<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $sheet_slug
 * @property int $serial_number
 * @property string|null $ai_status
 * @property string|null $raw_ai_json
 * @property string|null $corrected_json
 * @property string|null $extracted_at
 * @property string|null $error_message
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'sheet_slug', 'serial_number', 'ai_status', 'raw_ai_json',
    'corrected_json', 'extracted_at', 'error_message',
])]
class GoogleSheetExtraction extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'serial_number' => 'integer',
        ];
    }
}
