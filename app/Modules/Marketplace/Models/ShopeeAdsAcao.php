<?php

namespace App\Modules\Marketplace\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estratégia Shopee, botão Ação (tarefa 569, 2026-10-14): registro de cada
 * pausa, retomada ou troca de meta de ROAS que o Cauê mandou para a Shopee
 * (ou simulou, com a escrita desligada). Serviço:
 * App\Services\Shopee\CaueShopeeAcoes.
 */
class ShopeeAdsAcao extends Model
{
    protected $table = 'shopee_ads_acoes';

    public const ACAO_PAUSAR = 'pausar';

    public const ACAO_RETOMAR = 'retomar';

    public const ACAO_META = 'meta_roas';

    public const ACOES = [self::ACAO_PAUSAR, self::ACAO_RETOMAR, self::ACAO_META];

    public const STATUS_SIMULADA = 'simulada';

    public const STATUS_CONFERIDA = 'conferida';

    public const STATUS_NAO_CONFERIDA = 'nao_conferida';

    public const STATUS_ERRO = 'erro';

    /** Prazo do botão Desfazer. */
    public const DIAS_DESFAZER = 7;

    protected $fillable = [
        'item_id', 'campaign_id', 'nome_produto', 'acao', 'antes', 'depois', 'status',
        'simulacao', 'resposta', 'erro', 'user_id', 'conferida_em', 'desfeita_em', 'desfaz_id',
    ];

    protected function casts(): array
    {
        return [
            'antes' => 'array',
            'depois' => 'array',
            'resposta' => 'array',
            'simulacao' => 'boolean',
            'conferida_em' => 'datetime',
            'desfeita_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function podeDesfazer(): bool
    {
        return ! $this->simulacao
            && $this->desfaz_id === null
            && $this->desfeita_em === null
            && in_array($this->status, [self::STATUS_CONFERIDA, self::STATUS_NAO_CONFERIDA], true)
            && $this->created_at?->gt(now()->subDays(self::DIAS_DESFAZER));
    }

    /** Frase curta do antes → depois, para a tela e o histórico. */
    public function descricao(): string
    {
        return match ($this->acao) {
            self::ACAO_PAUSAR => 'Pausar a campanha',
            self::ACAO_RETOMAR => 'Retomar a campanha',
            self::ACAO_META => sprintf(
                'Meta de ROAS %s → %s',
                isset($this->antes['meta']) ? number_format((float) $this->antes['meta'], 1, ',', '.') : 'sem meta',
                number_format((float) ($this->depois['meta'] ?? 0), 1, ',', '.'),
            ),
            default => $this->acao,
        };
    }
}
