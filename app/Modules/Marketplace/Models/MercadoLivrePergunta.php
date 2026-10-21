<?php

namespace App\Modules\Marketplace\Models;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MercadoLivrePergunta extends Model
{
    /** Quem respondeu: a Manuela sozinha, um atendente pelo admin ou alguém direto no ML. */
    public const POR_MANUELA = 'manuela';

    public const POR_HUMANO = 'humano';

    public const POR_ML = 'ml';

    /** Liga/desliga o envio automático da Manuela (Perguntas MK Place). Desligado por padrão. */
    public const SETTING_AUTO = 'perguntas_ml.manuela_responde_sozinha';

    protected $table = 'ml_perguntas';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'precisa_humano' => 'boolean',
            'perguntada_em' => 'datetime',
            'rascunho_em' => 'datetime',
            'respondida_em' => 'datetime',
            'humano_assumiu_em' => 'datetime',
        ];
    }

    /** Esperando resposta da loja (excluída, banida ou fechada no ML não conta). */
    public function scopePendentes(Builder $query): Builder
    {
        return $query->where('status', 'UNANSWERED')->whereNull('respondida_em');
    }

    public static function manuelaRespondeSozinha(): bool
    {
        return Setting::get(self::SETTING_AUTO) === '1';
    }

    /** Abertas que esperam uma pessoa: a Manuela não respondeu ou alguém assumiu. */
    public function scopeAguardandoHumano(Builder $q): Builder
    {
        return $q->pendentes()->where(fn ($w) => $w->where('precisa_humano', true)->orWhereNotNull('humano_assumiu_em'));
    }

    public function aberta(): bool
    {
        return $this->respondida_em === null && $this->status === 'UNANSWERED';
    }

    public function linkNoMercadoLivre(): string
    {
        // MLB123456 → produto.mercadolivre.com.br/MLB-123456
        return 'https://produto.mercadolivre.com.br/'.preg_replace('/^([A-Z]+)(\d+)$/', '$1-$2', $this->item_id);
    }
}
