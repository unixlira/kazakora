<?php

namespace App\Modules\Checkout\Models;

use App\Modules\Checkout\Support\FreteRegioes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Regra de frete por cidade/região (pedido 2026-10-24): o que fazer com a
 * cotação dos Correios quando o CEP do cliente cai num dos locais dela.
 * Busca e aplicação em FreteRegioes; quem usa é o FreteEmbutido (vitrine,
 * página do produto, carrinho, pedido e NF-e).
 */
class FreteRegiao extends Model
{
    public const FIXO = 'fixo';

    public const ACRESCIMO = 'acrescimo';

    public const DESCONTO = 'desconto';

    public const TETO = 'teto';

    public const TIPOS = [self::FIXO, self::ACRESCIMO, self::DESCONTO, self::TETO];

    public const SERVICOS = ['pac', 'sedex'];

    protected $table = 'frete_regioes';

    protected $fillable = [
        'nome', 'ativo', 'prioridade', 'tipo_preco', 'valor', 'percentual',
        'prazo_dias', 'servico', 'peso_min_g', 'peso_max_g', 'observacao',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'percentual' => 'boolean',
            'prioridade' => 'integer',
            'valor' => 'decimal:2',
            'prazo_dias' => 'integer',
            'peso_min_g' => 'integer',
            'peso_max_g' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => FreteRegioes::esquecer());
        static::deleted(fn () => FreteRegioes::esquecer());
    }

    public function locais(): HasMany
    {
        return $this->hasMany(FreteRegiaoLocal::class, 'frete_regiao_id');
    }
}
