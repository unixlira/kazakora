<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Pedido de texto para o Claude quando o Gemini não responde (2026-10-25) —
 * ver a migration criar_anuncio_claude_pedidos e AnuncioConteudoService.
 */
class AnuncioClaudePedido extends Model
{
    public const TIPO_CONTEUDO = 'conteudo';

    public const TIPO_CTA = 'cta';

    public const TIPO_DESCRICAO = 'descricao';

    public const STATUS_PENDENTE = 'pendente';

    public const STATUS_GERANDO = 'gerando';

    public const STATUS_PRONTO = 'pronto';

    public const STATUS_FALHOU = 'falhou';

    /** Worker que pegou e sumiu (PC desligado no meio): volta para a fila. */
    private const MINUTOS_GERANDO = 30;

    protected $table = 'anuncio_claude_pedidos';

    protected $fillable = [
        'product_id',
        'tipo',
        'status',
        'origem_hash',
        'sistema',
        'prompt',
        'imagens',
        'total_imagens',
        'resposta',
        'erro',
        'pego_em',
        'concluido_em',
    ];

    protected function casts(): array
    {
        return [
            'imagens' => 'array',
            'resposta' => 'array',
            'pego_em' => 'datetime',
            'concluido_em' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Pedido ainda na fila ou sendo escrito para este produto e tipo. */
    public static function emAberto(int $productId, string $tipo): ?self
    {
        return self::query()
            ->where('product_id', $productId)
            ->where('tipo', $tipo)
            ->whereIn('status', [self::STATUS_PENDENTE, self::STATUS_GERANDO])
            ->latest('id')
            ->first();
    }

    /** O botão do admin vem antes do lote automático. */
    public static function reivindicarProximo(): ?self
    {
        return DB::transaction(function (): ?self {
            self::query()
                ->where('status', self::STATUS_GERANDO)
                ->where('pego_em', '<', now()->subMinutes(self::MINUTOS_GERANDO))
                ->update(['status' => self::STATUS_PENDENTE]);

            $pedido = self::query()
                ->where('status', self::STATUS_PENDENTE)
                ->orderByRaw('CASE WHEN tipo = ? THEN 0 ELSE 1 END', [self::TIPO_DESCRICAO])
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $pedido?->forceFill(['status' => self::STATUS_GERANDO, 'pego_em' => now(), 'erro' => null])->save();

            return $pedido;
        });
    }
}
