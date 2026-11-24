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
     * Motivo do erro no cartão (pedido do Lira 2026-11-24, lead 21 viu só
     * "Confira os dados do cartão."): lista FECHADA de códigos, aceita em
     * `valor` só no evento `erro` com `campo` = cartao e gravada na coluna
     * `motivo`. Nunca o que foi digitado. Mesma lista do navegador
     * (resources/js/Shared/useMercadoPagoSdk.js, MOTIVOS_CARTAO).
     */
    public const MOTIVOS_CARTAO = ['numero', 'validade', 'cvv', 'nome', 'documento', 'bin', 'parcelas', 'nao_carregou', 'sem_token', 'outro'];

    /** Texto do motivo no passo a passo do admin (FluxoDoCheckout). */
    public const TEXTO_MOTIVO_CARTAO = [
        'numero' => 'número do cartão incompleto ou inválido',
        'validade' => 'validade inválida',
        'cvv' => 'código de segurança (CVV) incompleto',
        'nome' => 'nome do titular em branco',
        'documento' => 'CPF do titular inválido',
        'bin' => 'cartão não aceito como crédito',
        'parcelas' => 'parcelas não escolhidas',
        'nao_carregou' => 'o formulário do cartão não carregou',
        'sem_token' => 'o Mercado Pago não validou o cartão (sem token)',
        'outro' => 'outro erro do formulário do cartão',
    ];

    /**
     * Eventos aceitos do navegador (lista fechada) e o que cada um pode
     * trazer. pedido_criado só o servidor grava (CheckoutController).
     */
    public const EVENTOS_DO_NAVEGADOR = [
        'abriu', 'viu_formulario', 'rolagem', 'foco', 'preencheu', 'cep_respondido',
        'pagamento', 'finalizar', 'erro', 'saiu',
        ...self::EVENTOS_CUPOM,
        ...self::EVENTOS_WHATSAPP,
    ];

    /**
     * Botão do cupom (pedido do Lira 2026-11-23): clicou em "Aplicar cupom" (ou
     * Enter no campo), deu certo, deu erro. `campo` = "cupom" e só isso: o
     * código digitado nunca vem.
     */
    public const EVENTOS_CUPOM = ['cupom_clicou', 'cupom_aplicado', 'cupom_erro'];

    /**
     * "Confira seu WhatsApp" (pedido do Lira 2026-11-23): celular com DDD 55 e
     * CEP fora da área do DDD 55 (AlertaWhatsappDdd55). `campo` = "celular" e
     * só isso: o número nunca vem.
     */
    public const EVENTOS_WHATSAPP = ['whatsapp_alerta_ddd55', 'whatsapp_confirmou', 'whatsapp_corrigiu'];

    /** Adicionar ao carrinho ou COMPRAR AGORA na loja (servidor, 2026-11-20): `campo` = id do produto. Tela Comportamento. */
    public const PRODUTO_CARRINHO = 'produto_carrinho';

    public const PEDIDO_CRIADO = 'pedido_criado';

    protected $fillable = ['site', 'visitor_id', 'user_id', 'pagina_id', 'evento', 'campo', 'valor', 'motivo', 'rolagem', 'dispositivo', 'origem'];

    /**
     * Colunas de um evento do navegador já validado (CheckoutEventosController::validar).
     * O motivo do erro no cartão chega em `valor` (texto da lista fechada) e
     * vai para a coluna `motivo`; `valor` (número) fica para rolagem e segundos.
     *
     * @param  array<string, mixed>  $evento
     * @return array<string, mixed>
     */
    public static function colunasDoNavegador(array $evento): array
    {
        $motivo = $evento['evento'] === 'erro' && is_string($evento['valor'] ?? null) ? $evento['valor'] : null;

        return [
            'evento' => $evento['evento'],
            'campo' => $evento['campo'] ?? null,
            'valor' => $motivo !== null ? null : ($evento['valor'] ?? $evento['segundos'] ?? null),
            'motivo' => $motivo,
            'rolagem' => $evento['rolagem'] ?? null,
        ];
    }

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
    public static function origemDaVisita(string $visitante, string $site = SiteVisit::SITE_LOJA): ?string
    {
        $visitas = SiteVisit::query()
            ->where('site', $site)
            ->where('visitor_id', $visitante)
            ->where('created_at', '>=', now()->subDay());

        return (clone $visitas)->whereNotIn('origem', ['interno'])->whereNotNull('origem')->latest('id')->value('origem')
            ?? $visitas->latest('id')->value('origem');
    }

    /**
     * Visitante de outro site (FerraKora, Beauty Kora): sem cookie em outro
     * domínio, é o mesmo código tirado do IP que a visita grava
     * (OutroSiteVisitaController). O IP em si não entra em checkout_eventos.
     */
    public static function visitanteDeOutroSite(Request $request): string
    {
        $ip = $request->ip();

        return $ip !== null ? substr(md5($ip), 0, 32) : 'anonimo';
    }

    /** Pedido criado pelo checkout de outro site (servidor), para o funil daquele site. */
    public static function pedidoCriadoEmOutroSite(Request $request, string $site): void
    {
        rescue(fn () => self::create([
            'site' => $site,
            'visitor_id' => self::visitanteDeOutroSite($request),
            'evento' => self::PEDIDO_CRIADO,
            'dispositivo' => TrackSiteVisit::dispositivo((string) $request->userAgent()),
        ]), null, false);
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
