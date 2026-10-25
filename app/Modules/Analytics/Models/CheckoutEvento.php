<?php

namespace App\Modules\Analytics\Models;

use App\Http\Middleware\TrackSiteVisit;
use App\Support\Privacidade\Cookies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * O que a pessoa fez na tela de finalização (pedido do Lira 2026-10-23).
 * Nunca guarda o que foi digitado: só QUAL campo foi focado/preenchido.
 * Sem IP. Leitura em `checkout:funil`; apagado aos 6 meses
 * (privacidade:limpar). Documentação: docs/privacidade-e-cookies.md.
 */
class CheckoutEvento extends Model
{
    protected $table = 'checkout_eventos';

    public const UPDATED_AT = null;

    /** Campos do formulário que podem ser medidos (nome do campo, nunca o valor). */
    public const CAMPOS = ['nome', 'celular', 'cpf', 'email', 'cep', 'endereco', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'cupom'];

    public const FORMAS_PAGAMENTO = ['pix', 'cartao', 'boleto'];

    /** Erro mostrado: um campo do formulário ou uma destas partes da tela. */
    public const ERROS_EXTRAS = ['entrega', 'pagamento', 'cartao', 'carrinho', 'outro'];

    public const MARCOS_ROLAGEM = [25, 50, 75, 100];

    /**
     * Eventos aceitos do navegador (lista fechada) e o que cada um pode
     * trazer. pedido_criado só o servidor grava (CheckoutController).
     */
    public const EVENTOS_DO_NAVEGADOR = [
        'abriu', 'viu_formulario', 'rolagem', 'foco', 'preencheu', 'cep_respondido',
        'pagamento', 'finalizar', 'erro', 'saiu',
    ];

    public const PEDIDO_CRIADO = 'pedido_criado';

    protected $fillable = ['visitor_id', 'user_id', 'pagina_id', 'evento', 'campo', 'valor', 'rolagem', 'dispositivo', 'origem'];

    /**
     * Mesmo visitante do log de visitas (TrackSiteVisit, Cookies::visitante):
     * cookie kazakora_visitor, criado no primeiro acesso desde 2026-10-25.
     * Aqui nunca se cria cookie.
     */
    public static function visitante(Request $request): string
    {
        return Cookies::visitante($request);
    }

    /**
     * Origem da visita (Instagram, Meta Ads, Google...): a última de fora da
     * loja que o log de visitas registrou para o visitante nas últimas 24 h.
     */
    public static function origemDaVisita(string $visitante): ?string
    {
        $visitas = SiteVisit::query()
            ->where('site', SiteVisit::SITE_LOJA)
            ->where('visitor_id', $visitante)
            ->where('created_at', '>=', now()->subDay());

        return (clone $visitas)->whereNotIn('origem', ['interno'])->whereNotNull('origem')->latest('id')->value('origem')
            ?? $visitas->latest('id')->value('origem');
    }

    /** Pedido criado pelo checkout (servidor). $visitante calculado antes de criar o pedido. */
    public static function pedidoCriado(Request $request, string $visitante, ?int $userId): void
    {
        rescue(fn () => self::create([
            'visitor_id' => $visitante,
            'user_id' => $userId,
            'evento' => self::PEDIDO_CRIADO,
            'dispositivo' => TrackSiteVisit::dispositivo((string) $request->userAgent()),
        ]), null, false);
    }
}
