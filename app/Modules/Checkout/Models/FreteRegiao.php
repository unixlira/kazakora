<?php

namespace App\Modules\Checkout\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Regra de frete por lugar (pedido 2026-10-24): o que acontece com o frete
 * dos Correios onde ela vale (locais). Aplicada no FreteEmbutido.
 */
class FreteRegiao extends Model
{
    /** Frete = valor (0 = grátis). */
    public const FIXO = 'fixo';

    /** Frete = Correios + valor. */
    public const ACRESCIMO = 'acrescimo';

    /** Frete = Correios − valor (nunca abaixo de 0). */
    public const DESCONTO = 'desconto';

    /** Frete = Correios, até no máximo o valor. */
    public const TETO = 'teto';

    public const TIPOS = [self::FIXO, self::ACRESCIMO, self::DESCONTO, self::TETO];

    protected $table = 'frete_regioes';

    protected $fillable = ['site', 'nome', 'prioridade', 'ativo', 'tipo_preco', 'categoria', 'valor', 'prazo_dias', 'servico', 'peso_min_g', 'peso_max_g'];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'valor' => 'float',
            'prioridade' => 'integer',
            'prazo_dias' => 'integer',
            'peso_min_g' => 'integer',
            'peso_max_g' => 'integer',
        ];
    }

    public function locais(): HasMany
    {
        return $this->hasMany(FreteRegiaoLocal::class);
    }
}
