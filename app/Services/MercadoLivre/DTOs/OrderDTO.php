<?php

namespace App\Services\MercadoLivre\DTOs;

use Spatie\LaravelData\Data;

class OrderDTO extends Data
{
    /**
     * @param  array<string, mixed>  $buyer
     * @param  array<int, array<string, mixed>>  $order_items
     * @param  array<string, mixed>  $shipping
     */
    public function __construct(
        public int $id,
        public string $status,
        public float $total_amount,
        public string $currency_id,
        public string $date_created,
        public array $buyer = [],
        public array $order_items = [],
        public array $shipping = [],
    ) {}
}
