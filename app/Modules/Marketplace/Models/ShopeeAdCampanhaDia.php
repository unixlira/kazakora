<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Desempenho de uma campanha da Shopee num dia (Estratégia Shopee,
 * 2026-10-14). pedidos/venda = atribuição broad (a do painel da Shopee);
 * pedidos_diretos/venda_direta = só o produto anunciado.
 */
class ShopeeAdCampanhaDia extends Model
{
    protected $table = 'shopee_ad_campanha_dias';

    protected $fillable = [
        'campaign_id', 'data', 'impressoes', 'cliques', 'gasto', 'pedidos',
        'unidades', 'venda', 'pedidos_diretos', 'venda_direta',
    ];

    protected function casts(): array
    {
        return [
            // Mesmo cuidado do ChannelAdSpend: 'date' puro grava com hora e o
            // updateOrCreate por (campanha, data) nunca casaria.
            'data' => 'date:Y-m-d',
            'gasto' => 'decimal:2',
            'venda' => 'decimal:2',
            'venda_direta' => 'decimal:2',
        ];
    }
}
