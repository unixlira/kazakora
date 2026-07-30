<?php

namespace App\Services\MercadoLivre\DTOs;

use Spatie\LaravelData\Data;

class ProductDTO extends Data
{
    /**
     * @param  array<int, array{source: string}>  $pictures
     * @param  array<int, array{id: string, value_name: string}>  $attributes
     */
    public function __construct(
        public string $category_id,
        public float $price,
        public int $available_quantity,
        public ?string $title = null,
        public string $buying_mode = 'buy_it_now',
        public string $listing_type_id = 'gold_special',
        public string $condition = 'new',
        public string $currency_id = 'BRL',
        public ?string $description = null,
        public array $pictures = [],
        // Some ML categories require items to belong to a "product family"
        // instead of having a plain title (confirmed live: HTTP 400
        // body.required_fields → missing [family_name]). Leave $title null
        // when sending this — ML rejects requests that carry both.
        public ?string $family_name = null,
        public array $attributes = [],
    ) {}
}
