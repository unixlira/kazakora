<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

/** Um dia programado pelo shopee:relampago-diaria (pedido 2026-10-17). */
class ShopeeRelampagoExecucao extends Model
{
    protected $table = 'shopee_relampago_execucoes';

    protected $fillable = [
        'dia', 'timeslot_id', 'flash_sale_id', 'status', 'adicionados', 'recusados',
        'ativos_na_shopee', 'detalhe',
    ];

    protected function casts(): array
    {
        return [
            'dia' => 'date',
            'detalhe' => 'array',
        ];
    }
}
