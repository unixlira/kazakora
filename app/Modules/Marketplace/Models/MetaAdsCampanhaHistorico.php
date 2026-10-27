<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Campanha da Meta já excluída, com os números de toda a vida dela (pedido
 * do Lira em 2026-10-27, tela Gasto e resultados). Só leitura: as linhas
 * vêm da foto tirada antes da exclusão (ver a migration da tabela).
 */
class MetaAdsCampanhaHistorico extends Model
{
    protected $table = 'meta_ads_campanhas_historico';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'inicio' => 'date:Y-m-d',
            'fim' => 'date:Y-m-d',
            'excluida_em' => 'date:Y-m-d',
            'orcamento_diario' => 'float',
            'gasto' => 'float',
            'ctr_link' => 'float',
            'vendas' => 'float',
            'roas' => 'float',
            'impressoes' => 'integer',
            'alcance' => 'integer',
            'cliques_link' => 'integer',
            'carrinho' => 'integer',
            'checkout' => 'integer',
            'compras' => 'integer',
            'conjuntos' => 'integer',
            'anuncios' => 'integer',
        ];
    }
}
