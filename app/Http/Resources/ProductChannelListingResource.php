<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductChannelListingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'channel' => $this->channel,
            'is_enabled' => (bool) $this->is_enabled,
            'status' => $this->status,
            'external_id' => $this->external_id,
            'external_model_id' => $this->external_model_id,
            'attributes' => $this->attributes,
            'last_error' => $this->last_error,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
