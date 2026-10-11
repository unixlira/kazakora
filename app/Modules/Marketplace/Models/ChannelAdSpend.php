<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Gasto diário real com anúncio por canal (Shopee Ads, Mercado Ads) — ver
 * App\Services\Shopee\ShopeeAdsService / App\Services\MercadoLivre\
 * MercadoLivreAdsService e o comando ads:sync-spend.
 */
class ChannelAdSpend extends Model
{
    protected $fillable = [
        'date',
        'channel',
        'impressions',
        'clicks',
        'attributed_orders',
        'attributed_gmv',
        'spend',
    ];

    protected function casts(): array
    {
        return [
            // BUG REAL encontrado 2026-08-09: 'date' puro (sem parâmetro de
            // formato) grava no banco como "Y-m-d H:i:s" (o $dateFormat
            // padrão do model), não "Y-m-d" — o comando ads:sync-spend
            // casa contra a string "Y-m-d" pura na hora do updateOrCreate,
            // então NUNCA batia com o que estava salvo, e a
            // re-sincronização diária (que existe justamente pra corrigir
            // o número parcial do dia anterior) silenciosamente nunca
            // atualizava nada — só falhava a inserção por violar o
            // unique(date, channel) e o erro ficava engolido pelo
            // try/catch do comando. 'date:Y-m-d' força o mesmo formato na
            // gravação e na leitura.
            'date' => 'date:Y-m-d',
            'attributed_gmv' => 'decimal:2',
            'spend' => 'decimal:2',
        ];
    }
}
