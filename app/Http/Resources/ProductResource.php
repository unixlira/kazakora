<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'brand' => $this->brand,
            'model' => $this->model,
            'color' => $this->color,
            'variation' => $this->variation,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'price' => (float) $this->price,
            'cost_price' => $this->cost_price !== null ? (float) $this->cost_price : null,
            'discount_percentage' => $this->discount_percentage !== null ? (float) $this->discount_percentage : null,
            'discount_amount' => $this->discount_amount !== null ? (float) $this->discount_amount : null,
            'final_price' => (float) $this->final_price,
            'has_discount' => (bool) $this->has_discount,
            'stock' => $this->stock,
            'is_active' => (bool) $this->is_active,
            'is_featured' => (bool) $this->is_featured,
            'is_new_release' => (bool) $this->is_new_release,
            'images' => ImageUrlResource::collection($this->whenLoaded('images')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
