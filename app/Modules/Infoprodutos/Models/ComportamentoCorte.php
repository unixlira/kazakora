<?php

namespace App\Modules\Infoprodutos\Models;

use App\Models\User;
use App\Modules\Infoprodutos\Support\ChaveAtualDaPagina;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Corte de uma página na tela Comportamento ("Zerar a partir de agora",
 * 2026-11-26): a página mudou de layout e os cliques antigos caem em pontos
 * errados. Os eventos continuam no banco; a tela só lê os de depois do corte.
 */
class ComportamentoCorte extends Model
{
    protected $table = 'comportamento_cortes';

    protected $guarded = [];

    protected $casts = ['desde' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Corte mais recente da página (na chave atual do produto), ou null. */
    public static function atual(string $produto, string $pagina): ?self
    {
        return self::query()->where('produto', ChaveAtualDaPagina::atual($produto))->where('pagina', $pagina)
            ->where('desde', '<=', now())->latest('desde')->latest('id')->first();
    }

    public static function zerar(string $produto, string $pagina, ?string $motivo = null, ?int $userId = null): self
    {
        return self::query()->create([
            'produto' => ChaveAtualDaPagina::atual($produto),
            'pagina' => $pagina,
            'desde' => now(),
            'motivo' => $motivo !== null && trim($motivo) !== '' ? mb_substr(trim($motivo), 0, 200) : null,
            'user_id' => $userId,
        ]);
    }
}
