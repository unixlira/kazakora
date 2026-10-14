<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Campanha de ADS por produto da Shopee, copiada pelo shopee:ads-campanhas
 * (Estratégia Shopee, 2026-10-14). Só leitura da Shopee.
 */
class ShopeeAdCampanha extends Model
{
    protected $table = 'shopee_ad_campanhas';

    /** Status em que a campanha está gastando ou vai gastar. */
    public const ATIVAS = ['ongoing', 'scheduled'];

    protected $fillable = [
        'campaign_id', 'ad_type', 'nome', 'status', 'lance', 'posicao', 'orcamento',
        'meta_roas', 'item_id', 'itens', 'inicio', 'fim', 'sincronizado_em',
    ];

    protected function casts(): array
    {
        return [
            'itens' => 'array',
            'orcamento' => 'decimal:2',
            'meta_roas' => 'decimal:2',
            'inicio' => 'datetime',
            'fim' => 'datetime',
            'sincronizado_em' => 'datetime',
        ];
    }
}
