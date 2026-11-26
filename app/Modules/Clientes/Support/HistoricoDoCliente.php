<?php

namespace App\Modules\Clientes\Support;

use App\Http\Middleware\TrackSiteVisit;
use App\Models\User;
use App\Modules\Clientes\Models\ClienteEvento;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use App\Support\Privacidade\Cookies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Grava o histórico da conta do cliente (pedido do Lira 26/11/2026: "quero
 * rastreio de histórico total dos clientes"). Regras (docs/privacidade-e-cookies.md,
 * "Histórico do cliente"):
 *
 * - Só contas de cliente: equipe e admin (users com outro role) ficam fora.
 * - Nunca guarda senha, token, o que foi digitado nem o endereço inteiro.
 * - Nunca quebra o fluxo do cliente: qualquer falha aqui é engolida.
 * - Ligar a navegação anterior (mesmo kazakora_visitor) à conta só com o
 *   aceite de cookies registrado na hora (Cookies::aceitou). FerraKora: o
 *   visitante de lá é o IP, que pode ser de várias pessoas, então nada do
 *   passado é ligado; só as páginas abertas logado e com "Aceito".
 */
class HistoricoDoCliente
{
    public const KAZAKORA = 'kazakora';

    public const FERRAKORA = 'ferrakora';

    /** Dia em que cada tipo passou a ser gravado (o que existe antes vem de outras tabelas). */
    public const REGISTRADO_DESDE = '2026-11-26';

    public const TIPOS = [
        'cadastro' => 'Cadastro',
        'login' => 'Entrou na conta',
        'logout' => 'Saiu da conta',
        'senha_link_enviado' => 'Pediu link de nova senha',
        'senha_redefinida' => 'Redefiniu a senha pelo link',
        'senha_trocada' => 'Trocou a senha no perfil',
        'foto_trocada' => 'Trocou a foto de perfil',
        'foto_removida' => 'Removeu a foto de perfil',
        'endereco_criado' => 'Cadastrou endereço',
        'endereco_alterado' => 'Alterou endereço',
        'endereco_apagado' => 'Apagou endereço',
        'perfil_alterado' => 'Alterou dados do perfil',
        'visitas_ligadas' => 'Navegação anterior ligada à conta',
    ];

    /** Tipos que somem aos 6 meses (registro de acesso, Marco Civil art. 15). */
    public const TIPOS_DE_ACESSO = ['login', 'logout', 'visitas_ligadas'];

    /** Janela da navegação anterior que pode ser ligada à conta (o mesmo prazo das visitas). */
    private const MESES_LIGAR = 6;

    public static function kazakora(?User $user, string $tipo, array $detalhe = [], ?Request $request = null): void
    {
        if (! $user || $user->role !== User::ROLE_CUSTOMER) {
            return;
        }

        self::gravar(self::KAZAKORA, ['user_id' => $user->id], $tipo, $detalhe, $request);
    }

    public static function ferrakora(?FerraKoraCliente $cliente, string $tipo, array $detalhe = [], ?Request $request = null): void
    {
        if (! $cliente) {
            return;
        }

        self::gravar(self::FERRAKORA, ['ferrakora_cliente_id' => $cliente->id], $tipo, $detalhe, $request);
    }

    /**
     * Cadastro na KazaKora com a origem da visita que trouxe a pessoa
     * (primeira página de fora do site deste navegador nos últimos 30 dias).
     */
    public static function cadastroKazaKora(User $user, string $via, ?Request $request = null): void
    {
        $request ??= request();
        $origem = rescue(function () use ($request) {
            $visitante = Cookies::visitante($request);
            $visita = DB::table('site_visits')
                ->where('site', self::KAZAKORA)
                ->where('visitor_id', $visitante)
                ->where('created_at', '>=', now()->subDays(30))
                ->whereNotNull('origem')->where('origem', '!=', 'interno')
                ->orderBy('id')
                ->first(['origem', 'utm_source', 'utm_medium', 'utm_campaign', 'referer', 'click_id_param', 'created_at']);

            return $visita ? self::origemDaVisita($visita) : null;
        }, null, false);

        self::kazakora($user, 'cadastro', array_filter(['via' => $via, 'origem' => $origem]), $request);
    }

    /**
     * Cadastro na FerraKora. O site manda o que guardou na entrada da aba
     * (utm_*, qual parâmetro de clique veio, site de onde veio): o valor do
     * clique não é guardado, só o nome do parâmetro.
     */
    public static function cadastroFerraKora(FerraKoraCliente $cliente, string $via, ?Request $request = null): void
    {
        $request ??= request();
        $texto = fn (string $campo, int $limite) => is_string($v = $request->input("origem.{$campo}")) && trim($v) !== '' ? Str::limit(strip_tags(trim($v)), $limite, '') : null;
        $ref = $texto('ref', 500);
        $host = $ref ? preg_replace('/^www\./', '', strtolower((string) parse_url($ref, PHP_URL_HOST))) : null;
        $clique = $texto('clique', 20);

        $origem = array_filter([
            'utm_source' => $texto('utm_source', 80),
            'utm_medium' => $texto('utm_medium', 80),
            'utm_campaign' => $texto('utm_campaign', 120),
            'referer' => $host ?: null,
            'clique' => $clique && preg_match('/^[a-z]{3,10}$/', $clique) ? $clique : null,
        ]);
        if ($origem !== []) {
            $origem['origem'] = \App\Modules\Analytics\Support\OrigemVisita::classificar($ref, [
                'utm_source' => $origem['utm_source'] ?? null,
                'utm_medium' => $origem['utm_medium'] ?? null,
                'utm_campaign' => $origem['utm_campaign'] ?? null,
            ], $origem['clique'] ?? null);
        }

        self::ferrakora($cliente, 'cadastro', array_filter(['via' => $via, 'origem' => array_filter($origem) ?: null]), $request);
    }

    /**
     * Liga à conta as páginas que este navegador abriu antes de entrar
     * (mesmo kazakora_visitor), só com o aceite de cookies registrado agora.
     * Visitante de sessão (s-...) ou anônimo não liga nada.
     */
    public static function ligarVisitasKazaKora(?User $user, Request $request): int
    {
        if (! $user || $user->role !== User::ROLE_CUSTOMER) {
            return 0;
        }

        return (int) rescue(function () use ($user, $request) {
            if (! Cookies::aceitou($request)) {
                return 0;
            }

            $visitante = Cookies::visitante($request);
            if (! Str::isUuid($visitante)) {
                return 0;
            }

            $ligadas = DB::table('site_visits')
                ->where('site', self::KAZAKORA)
                ->where('visitor_id', $visitante)
                ->whereNull('user_id')
                ->where('created_at', '>=', now()->subMonths(self::MESES_LIGAR))
                ->update(['user_id' => $user->id]);

            if ($ligadas > 0) {
                self::kazakora($user, 'visitas_ligadas', ['visitas' => $ligadas], $request);
            }

            return $ligadas;
        }, 0, false);
    }

    /** Origem resumida de uma linha de site_visits (sem o valor do clique). */
    public static function origemDaVisita(object $visita): array
    {
        return array_filter([
            'origem' => $visita->origem ?? null,
            'utm_source' => $visita->utm_source ?? null,
            'utm_medium' => $visita->utm_medium ?? null,
            'utm_campaign' => $visita->utm_campaign ?? null,
            'referer' => ($visita->referer ?? null) ? (preg_replace('/^www\./', '', strtolower((string) parse_url($visita->referer, PHP_URL_HOST))) ?: $visita->referer) : null,
            'clique' => $visita->click_id_param ?? null,
            'em' => isset($visita->created_at) ? \Carbon\CarbonImmutable::parse($visita->created_at)->toIso8601String() : null,
        ]);
    }

    private static function gravar(string $site, array $dono, string $tipo, array $detalhe, ?Request $request): void
    {
        if (! isset(self::TIPOS[$tipo])) {
            return;
        }

        rescue(function () use ($site, $dono, $tipo, $detalhe, $request) {
            $request ??= request();

            ClienteEvento::query()->create([
                'site' => $site,
                ...$dono,
                'tipo' => $tipo,
                'detalhe' => $detalhe === [] ? null : $detalhe,
                'ip' => $request?->ip(),
                'dispositivo' => $request ? TrackSiteVisit::dispositivo((string) $request->userAgent()) : null,
                'created_at' => now(),
            ]);
        }, null, false);
    }
}
