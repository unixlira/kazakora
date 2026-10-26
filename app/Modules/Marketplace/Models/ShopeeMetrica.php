<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Um número da tela do Seller Center da Shopee (tabela shopee_metricas,
 * 2026-10-26). Gravado pelo comando shopee:metricas-importar; lido pela
 * Análise Profunda de Conta (LeitorShopeeMetricas). Porcentagem sempre em
 * pontos percentuais (3,04 e não 0,0304).
 */
class ShopeeMetrica extends Model
{
    protected $table = 'shopee_metricas';

    public const ESCOPOS = ['loja', 'produto', 'anuncio_ads', 'campanha_ads', 'hora', 'palavra_chave', 'outro'];

    protected $fillable = [
        'chave', 'coletado_em', 'fonte', 'tela', 'url', 'periodo_inicio', 'periodo_fim', 'escopo', 'item_id',
        'model_id', 'nome', 'data', 'hora', 'metrica', 'rotulo', 'valor', 'unidade', 'texto_original', 'arquivo',
    ];

    protected function casts(): array
    {
        return [
            'coletado_em' => 'datetime',
            'periodo_inicio' => 'date:Y-m-d',
            'periodo_fim' => 'date:Y-m-d',
            'data' => 'date:Y-m-d',
            'hora' => 'integer',
            'valor' => 'float',
        ];
    }

    /** Chave única da linha: número de um dia não depende do período da coleta. */
    public static function chave(string $escopo, ?string $itemId, ?string $modelId, ?string $inicio, ?string $fim, ?string $data, ?int $hora, string $metrica): string
    {
        $periodo = $data !== null ? '' : ($inicio ?? '').'~'.($fim ?? '');

        return sha1(implode('|', [$escopo, $itemId ?? '', $modelId ?? '', $periodo, $data ?? '', $hora ?? '', $metrica]));
    }
}
