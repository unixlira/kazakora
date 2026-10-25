<?php

namespace App\Modules\Checkout\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Frete que o visitante viu para um produto e um CEP (pedido 2026-10-24),
 * guardado pelo IP e pela hash do aparelho. Ver TravaDeFrete.
 */
class FreteTrava extends Model
{
    use MassPrunable;

    protected $table = 'frete_travas';

    protected $fillable = ['site', 'dispositivo_hash', 'ip', 'dispositivo_id', 'cep', 'product_id', 'embutido', 'prazo_dias', 'correios', 'regra', 'expira_em'];

    protected function casts(): array
    {
        return [
            'embutido' => 'float',
            'correios' => 'float',
            'prazo_dias' => 'integer',
            'product_id' => 'integer',
            'expira_em' => 'datetime',
        ];
    }

    /** Travas vencidas há mais de 30 dias saem no model:prune. */
    public function prunable(): Builder
    {
        return static::where('expira_em', '<', now()->subDays(30));
    }
}
