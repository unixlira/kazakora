<?php

namespace App\Services\MercadoLivre\DTOs;

use Spatie\LaravelData\Data;

class ProductDTO extends Data
{
    /**
     * @param  array<int, array{source: string}>  $pictures
     */
    public function __construct(
        public string $title,
        public string $category_id,
        public float $price,
        public int $available_quantity,
        public string $buying_mode = 'buy_it_now',
        public string $listing_type_id = 'gold_special',
        public string $condition = 'new',
        public string $currency_id = 'BRL',
        public ?string $description = null,
        public array $pictures = [],
    ) {}
}
