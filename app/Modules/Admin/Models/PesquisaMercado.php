<?php

namespace App\Modules\Admin\Models;

use App\Models\User;
use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pesquisa de mercado lançada à mão no menu Pesquisas (pedido do Lira
 * 2026-10-12). Removida = vai para a lixeira (soft delete), não some.
 */
class PesquisaMercado extends Model
{
    use Auditable, SoftDeletes;

    protected $table = 'pesquisas_mercado';

    public const STATUS_RASCUNHO = 'rascunho';

    public const STATUS_CONCLUIDA = 'concluida';

    public const STATUS = [
        self::STATUS_RASCUNHO => 'Rascunho',
        self::STATUS_CONCLUIDA => 'Concluída',
    ];

    /** Mesmo vocabulário de orders.origin; "outra" pede o nome livre. */
    public const PLATAFORMAS = [
        'mercado_livre' => 'Mercado Livre',
        'shopee' => 'Shopee',
        'tiktok_shop' => 'TikTok Shop',
        'amazon' => 'Amazon',
        'loja' => 'Site próprio',
        'outra' => 'Outra',
    ];

    /** Abas da tela: ML, Shopee e o resto junto em "Outras plataformas". */
    public const ABAS = [
        'mercado_livre' => ['mercado_livre'],
        'shopee' => ['shopee'],
        'outras' => ['tiktok_shop', 'amazon', 'loja', 'outra'],
    ];

    protected $fillable = [
        'plataforma',
        'plataforma_nome',
        'titulo',
        'data_pesquisa',
        'user_id',
        'status',
        'resumo',
    ];

    protected $appends = ['plataforma_label'];

    protected function casts(): array
    {
        return [
            'data_pesquisa' => 'date:Y-m-d',
        ];
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PesquisaMercadoItem::class)->orderBy('posicao')->orderBy('id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getPlataformaLabelAttribute(): string
    {
        if ($this->plataforma === 'outra' && filled($this->plataforma_nome)) {
            return (string) $this->plataforma_nome;
        }

        return self::PLATAFORMAS[$this->plataforma] ?? (string) $this->plataforma;
    }

    public static function abaDa(string $plataforma): string
    {
        foreach (self::ABAS as $aba => $plataformas) {
            if (in_array($plataforma, $plataformas, true)) {
                return $aba;
            }
        }

        return 'outras';
    }
}
