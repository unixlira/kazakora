<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use App\Support\NomeBrasileiro;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'rating',
        'comment',
        'comentario_original',
        'ocultar_nome',
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
        'seller_reply',
        'seller_replied_at',
        'seller_reply_attempted_at',
        'seller_reply_status',
        'seller_reply_template',
        'seller_reply_error',
        'seller_reply_payload',
        // Apelido e texto como vieram do canal (ver AnonimizaAvaliacao).
        'nome_original',
        'comentario_original',
    ];

    protected $appends = ['reviewer_display_name'];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'ocultar_nome' => 'boolean',
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
        // Nome oculto a pedido do cliente (pedido 2026-10-20).
        if ($this->ocultar_nome) {
            return 'Cliente KazaKora';
        }

        if ($this->user) {
            return $this->user->name;
        }

        // Avaliação importada (de outra loja ou de canal) sem autor, gravada como
        // "Comprador de outra loja" ou com apelido mascarado ("s***a") mostra um
        // nome sorteado pelo id: o mesmo para a mesma avaliação (pedidos 20 e
        // 22/10). Não depende de outra_loja: em 22/10 o campo estava zerado e a
        // tela caía no "Cliente KazaKora". O banco continua com o texto original.
        $nome = trim((string) $this->reviewer_name);
        $importada = $this->getAttribute('outra_loja') || $this->getAttribute('origem_loja') || $this->external_id;

        if ($importada && ($nome === '' || $nome === 'Comprador de outra loja' || str_contains($nome, '***'))) {
            return NomeBrasileiro::gerar($this->getKey() ?? $this->external_id);
        }

        return $nome !== '' ? $this->reviewer_name : 'Cliente KazaKora';
    }
}
