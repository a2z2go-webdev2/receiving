<?php

namespace App\Http\Resources;

use App\Data\UserData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return UserData::fromModel($this->resource)->toArray();
    }
}
