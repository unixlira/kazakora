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

    /**
     * Canais da tela Perguntas MK Place. Hoje só o Mercado Livre tem perguntas
     * públicas e resposta pela API; os outros entram quando houver integração.
     */
    public const CANAL_MERCADO_LIVRE = 'mercado_livre';

    public const CANAL_SHOPEE = 'shopee';

    public const CANAL_AMAZON = 'amazon';

    public const CANAL_TIKTOK_SHOP = 'tiktok_shop';

    /** Canais em que a resposta sai pela API (RespostaPerguntaService). */
    public const CANAIS_COM_ENVIO = [self::CANAL_MERCADO_LIVRE];

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

    public function podeEnviar(): bool
    {
        return in_array($this->canal ?? self::CANAL_MERCADO_LIVRE, self::CANAIS_COM_ENVIO, true);
    }

    /** Link do anúncio no marketplace de origem. */
    public function link(): ?string
    {
        return match ($this->canal ?? self::CANAL_MERCADO_LIVRE) {
            self::CANAL_MERCADO_LIVRE => $this->linkNoMercadoLivre(),
            self::CANAL_SHOPEE => 'https://seller.shopee.com.br/portal/product/'.$this->item_id,
            self::CANAL_AMAZON => 'https://www.amazon.com.br/dp/'.$this->item_id,
            default => null,
        };
    }

    /**
     * Onde responder direto no painel do marketplace (Lira, 2026-10-20: se não
     * der para responder aqui, um clique leva à pergunta lá). O ML não tem
     * endereço por pergunta: a tela de perguntas do vendedor lista as abertas
     * por anúncio. Sem tela de perguntas conhecida, cai no anúncio.
     */
    public function linkDaPergunta(): ?string
    {
        return match ($this->canal ?? self::CANAL_MERCADO_LIVRE) {
            self::CANAL_MERCADO_LIVRE => 'https://www.mercadolivre.com.br/perguntas/vendedor',
            self::CANAL_AMAZON => 'https://sellercentral.amazon.com.br/messaging/inbox',
            default => $this->link(),
        };
    }

    public function linkNoMercadoLivre(): string
    {
        // MLB123456 → produto.mercadolivre.com.br/MLB-123456
        return 'https://produto.mercadolivre.com.br/'.preg_replace('/^([A-Z]+)(\d+)$/', '$1-$2', $this->item_id);
    }
}
