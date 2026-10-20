<?php

namespace App\Modules\Marketplace\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma ação do board "Sugestões do dia" (pedido do Lira 2026-10-20). Gerada
 * toda madrugada por App\Modules\Marketplace\Support\Sugestoes\GeradorDeSugestoes;
 * a equipe marca como feita ou ignorada. Nada aqui muda preço nem anúncio:
 * é só a recomendação, quem age é o Lira.
 */
class Sugestao extends Model
{
    protected $table = 'sugestoes';

    public const AREA_PRECO = 'preco';

    public const AREA_CRIATIVO = 'criativo';

    public const AREA_PAGINA = 'pagina';

    public const AREA_ORCAMENTO = 'orcamento';

    /** Colunas do board, na ordem da tela. */
    public const AREAS = [
        self::AREA_PRECO => 'Preço',
        self::AREA_CRIATIVO => 'Criativo',
        self::AREA_PAGINA => 'Página e checkout',
        self::AREA_ORCAMENTO => 'Orçamento do ADS',
    ];

    public const STATUS_ABERTA = 'aberta';

    public const STATUS_FEITA = 'feita';

    public const STATUS_IGNORADA = 'ignorada';

    public const STATUS = [self::STATUS_ABERTA, self::STATUS_FEITA, self::STATUS_IGNORADA];

    /** Ignorada não volta a aparecer por esse tempo (não insistir). */
    public const DIAS_SILENCIO = 7;

    protected $fillable = [
        'dia', 'chave', 'area', 'tipo', 'prioridade', 'titulo', 'acao', 'motivo', 'dados',
        'product_id', 'meta_ad_id', 'status', 'resolvida_em', 'resolvida_por',
    ];

    protected function casts(): array
    {
        return [
            'dia' => 'date',
            'dados' => 'array',
            'prioridade' => 'integer',
            'resolvida_em' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function resolvidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvida_por');
    }
}
