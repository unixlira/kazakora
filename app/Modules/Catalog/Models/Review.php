<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends Model
{
    /** Cadastrada à mão no admin (Avaliações › Cadastro e controle, 2026-10-18). */
    public const CHANNEL_MANUAL = 'manual';

    /** Importada de outra loja (reviews:importar-outra-loja, 2026-10-18). */
    public const CHANNEL_OUTRA_LOJA = 'outra_loja';

    /** Selo obrigatório da avaliação de outra loja (sem ele seria avaliação falsa, CDC). */
    public const SELO_OUTRA_LOJA = 'Avaliação feita em outra loja';

    public const NOME_OUTRA_LOJA = 'Comprador de outra loja';

    protected $fillable = [
        'user_id',
        'product_id',
        'rating',
        'comment',
        'seller_reply',
        'seller_replied_at',
        'seller_reply_attempted_at',
        'seller_reply_status',
        'seller_reply_template',
        'seller_reply_error',
        'seller_reply_payload',
        'reviewer_name',
        'channel',
        'external_id',
        'outra_loja',
        'origem_loja',
    ];

    /**
     * `channel`/`external_id` só existem pra controle interno do admin (de
     * qual marketplace veio) — pedido explícito 2026-08-16 pra NÃO
     * identificar a plataforma pro público na loja. Escondido por padrão em
     * toda serialização (inclusive props do Inertia pro storefront);
     * AdminReviewController usa ->makeVisible() explicitamente nas telas
     * internas de avaliações.
     */
    protected $hidden = [
        'channel',
        'external_id',
        // Loja e anúncio de origem da avaliação de outra loja: só o admin vê.
        'origem_loja',
        'seller_reply',
        'seller_replied_at',
        'seller_reply_attempted_at',
        'seller_reply_status',
        'seller_reply_template',
        'seller_reply_error',
        'seller_reply_payload',
    ];

    protected $appends = ['reviewer_display_name'];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'outra_loja' => 'boolean',
            'seller_replied_at' => 'datetime',
            'seller_reply_attempted_at' => 'datetime',
            'seller_reply_payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ReviewImage::class)->orderBy('position');
    }

    /**
     * Nome real do usuário do site quando a avaliação foi feita por lá, ou
     * o nome/apelido devolvido pelo canal quando importada — mesmo fallback
     * final ("Cliente KazaKora") já usado antes desta feature existir, só
     * centralizado aqui em vez de repetido em cada tela do storefront.
     */
    public function getReviewerDisplayNameAttribute(): string
    {
        return $this->user?->name ?? $this->reviewer_name ?? 'Cliente KazaKora';
    }

    /** Só as avaliações da KazaKora: as que contam na nota, na contagem e no JSON-LD. */
    public function scopeDaLoja(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('outra_loja'), false);
    }

    public function scopeDeOutraLoja(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('outra_loja'), true);
    }

    /**
     * "j_lucas_oliveira" vira "j***a". Idempotente ("J***a" continua "J***a")
     * para poder rodar de novo na exibição; já mascarado pela origem
     * ("g*****o") vira "g***o". Nome curto demais, vazio ou que cita a
     * plataforma (ex.: "AliExpress Shopper") vira o texto genérico.
     */
    public static function anonimizarNome(?string $nome): string
    {
        $limpo = trim(ltrim((string) $nome, '@'));

        if ($limpo === self::NOME_OUTRA_LOJA) {
            return self::NOME_OUTRA_LOJA;
        }

        if (preg_match('/^([\p{L}\p{N}])\*+([\p{L}\p{N}])$/u', $limpo, $m)) {
            return $m[1].'***'.$m[2];
        }

        $letras = preg_replace('/[^\p{L}\p{N}]/u', '', $limpo);

        if (mb_strlen($letras) < 3 || preg_match('/shopee|mercado|aliexpress|amazon|tiktok|shein|magalu|kicool/i', $limpo)) {
            return self::NOME_OUTRA_LOJA;
        }

        return mb_substr($letras, 0, 1).'***'.mb_substr($letras, -1);
    }
}
