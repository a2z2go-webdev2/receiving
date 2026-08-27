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
 * @property string|null $file_no
 * @property string $file_name
 * @property string|null $file_id
 * @property string|null $file_url
 * @property string $mime_type
 * @property string|null $r2_url
 * @property int|null $row_index
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'sheet_slug', 'serial_number', 'file_no', 'file_name',
    'file_id', 'file_url', 'mime_type', 'r2_url', 'row_index',
])]
class GoogleSheetFile extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'serial_number' => 'integer',
            'row_index' => 'integer',
        ];
    }
}
