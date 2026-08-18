<?php

namespace App\Models;

use App\Enums\PurchaseOrderLinkSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $po_extraction_id
 * @property int $ai_extraction_id
 * @property PurchaseOrderLinkSource $source
 * @property int|null $linked_by_user_id
 * @property int|null $unlinked_by_user_id
 * @property CarbonImmutable|null $unlinked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read PoExtraction $poExtraction
 * @property-read AiExtraction $aiExtraction
 * @property-read User|null $linkedBy
 * @property-read User|null $unlinkedBy
 * @property-read Collection<int, PurchaseOrderItemArrival> $arrivals
 */
#[Fillable([
    'po_extraction_id', 'ai_extraction_id', 'source', 'linked_by_user_id',
    'unlinked_by_user_id', 'unlinked_at',
])]
class PurchaseOrderDocumentLink extends Model
{
    protected function casts(): array
    {
        return [
            'source' => PurchaseOrderLinkSource::class,
            'unlinked_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('unlinked_at');
    }

    public function poExtraction(): BelongsTo
    {
        return $this->belongsTo(PoExtraction::class);
    }

    public function aiExtraction(): BelongsTo
    {
        return $this->belongsTo(AiExtraction::class);
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }

    public function unlinkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlinked_by_user_id');
    }

    public function arrivals(): HasMany
    {
        return $this->hasMany(PurchaseOrderItemArrival::class);
    }
}
