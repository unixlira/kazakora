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
     * Mesmo visitante do log de visitas (TrackSiteVisit): cookie
     * kazakora_visitor se a pessoa deu OK nos cookies, senão um código tirado
     * da sessão. Aqui nunca se cria cookie.
     */
    public static function visitante(Request $request): string
    {
        if (Cookies::aceitou($request) && is_string($cookie = $request->cookie(Cookies::COOKIE_VISITANTE)) && $cookie !== '') {
            return substr($cookie, 0, 36);
        }

        return 's-'.substr(hash('sha256', $request->session()->getId()), 0, 34);
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
