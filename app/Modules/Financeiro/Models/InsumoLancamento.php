<?php

namespace App\Modules\Financeiro\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lançamento da tela Insumos (pedido do Lira em 12/11/2026): material de
 * escritório, do galpão ou de embalagem comprado. Sai do lucro pela data
 * (ContributionMargin::insumosLancados), além dos R$ por pedido da
 * embalagem (config loja.insumos_por_pedido).
 */
class InsumoLancamento extends Model
{
    public const CATEGORIAS = [
        'escritorio' => 'Escritório',
        'galpao' => 'Galpão',
        'embalagem' => 'Embalagem',
        'outro' => 'Outro',
    ];

    protected $table = 'insumos_lancamentos';

    protected $fillable = ['data', 'descricao', 'categoria', 'valor', 'observacao', 'user_id'];

    protected function casts(): array
    {
        return [
            'data' => 'date:Y-m-d',
            'valor' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
