<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\ClientesDosSites;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Clientes\Support\LinhaDoTempoDoCliente;
use App\Modules\Clientes\Support\ResumoDoCliente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin > Clientes dos sites (pedido do Lira 2026-11-16): "quero uma tela de
 * clientes do kazakora e ferrakora, onde eu vejo os dados deles e cadastro
 * que fizeram no site para poder trabalhar". Abas KazaKora e FerraKora,
 * detalhe da conta e CSV do que está filtrado. Só leitura: nada é enviado,
 * editado ou apagado daqui. Regras em ClientesDosSites.
 */
class ClientesDosSitesController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request, ClientesDosSites $clientes): Response
    {
        $site = ClientesDosSites::siteValido($request->string('site')->toString());
        $filtros = $this->filtros($request);

        $paginador = $clientes->consulta($site, $filtros)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Acompanhamento (último acesso, origem, pagamento, senha): só para admin (26/11/2026).
        $admin = (bool) $request->user()?->isAdmin();
        $resumos = $admin ? app(ResumoDoCliente::class)->paraLinhas($paginador->items()) : [];
        $paginador->through(fn ($linha) => [
            ...$clientes->linha($linha),
            'acompanhamento' => $resumos["{$linha->site}:{$linha->id}"] ?? null,
        ]);

        return Inertia::render('Admin/ClientesDosSites/Index', [
            'site' => $site,
            'sites' => [
                ['id' => ClientesDosSites::TODAS, 'nome' => 'KazaKora e FerraKora'],
                ...collect(ClientesDosSites::SITES)->map(fn ($nome, $id) => ['id' => $id, 'nome' => $nome])->values(),
            ],
            'clientes' => $paginador,
            'filters' => ['site' => $site, ...$filtros],
            'podeVerHistorico' => $admin,
        ]);
    }

    public function show(string $site, int $cliente, ClientesDosSites $clientes): Response|RedirectResponse
    {
        abort_unless(isset(ClientesDosSites::SITES[$site]), 404);

        $detalhe = $clientes->detalhe($site, $cliente);
        if (! $detalhe) {
            return redirect()->route('admin.clientes-dos-sites.listar', ['site' => $site])->with('error', 'Cliente não encontrado.');
        }

        return Inertia::render('Admin/ClientesDosSites/Show', [
            ...$detalhe,
            'nomeDoSite' => ClientesDosSites::SITES[$site],
            'podeVerHistorico' => (bool) request()->user()?->isAdmin(),
        ]);
    }

    /**
     * Linha do tempo completa da conta (pedido do Lira 26/11/2026). Só admin
     * (rota com o middleware admin): mostra navegação, logins e mensagens.
     * Cada abertura fica na Auditoria (quem viu o histórico de quem).
     */
    public function historico(Request $request, string $site, int $cliente, ClientesDosSites $clientes, LinhaDoTempoDoCliente $linha): Response|RedirectResponse
    {
        abort_unless(isset(ClientesDosSites::SITES[$site]), 404);

        $resumo = $clientes->consulta($site, [])->where('u.id', $cliente)->first();
        if (! $resumo) {
            return redirect()->route('admin.clientes-dos-sites.listar')->with('error', 'Cliente não encontrado.');
        }
        $conta = \Illuminate\Support\Facades\DB::table($site === ClientesDosSites::KAZAKORA ? 'users' : 'ferrakora_clientes')->where('id', $cliente)->first();

        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => 'view',
            'entity' => 'HistoricoDoCliente',
            'entity_id' => $cliente,
            'old_values' => null,
            'new_values' => ['site' => $site, 'ip' => $request->ip()],
            'created_at' => now(),
        ]);

        return Inertia::render('Admin/ClientesDosSites/Historico', [
            'site' => $site,
            'nomeDoSite' => ClientesDosSites::SITES[$site],
            'cliente' => $clientes->linha($resumo),
            'acompanhamento' => app(ResumoDoCliente::class)->paraLinhas([$resumo])["{$site}:{$cliente}"] ?? null,
            'categorias' => LinhaDoTempoDoCliente::CATEGORIAS,
            ...$linha->montar($site, $conta),
        ]);
    }

    /** CSV (separado por ponto e vírgula, abre direto no Excel) do que está filtrado na tela. */
    public function exportar(Request $request, ClientesDosSites $clientes): StreamedResponse
    {
        $site = ClientesDosSites::siteValido($request->string('site')->toString());
        $filtros = $this->filtros($request);
        $arquivo = "clientes-{$site}-".now()->format('Y-m-d-His').'.csv';

        // Exportar dado pessoal fica registrado na Auditoria (quem, quando, qual site e filtro). LGPD art. 37.
        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => 'export',
            'entity' => 'ClientesDosSites',
            'entity_id' => null,
            'old_values' => null,
            'new_values' => ['site' => $site, 'filtros' => array_filter($filtros), 'ip' => $request->ip()],
            'created_at' => now(),
        ]);

        return response()->streamDownload(function () use ($clientes, $site, $filtros) {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, ['Loja', 'Nome', 'E-mail', 'Telefone', 'CPF', 'Cadastro', 'Aceita promoções', 'Cidade', 'UF', 'Pedidos pagos', 'Total gasto', 'Último pedido'], ';');

            foreach ($clientes->consulta($site, $filtros)->cursor() as $linha) {
                $c = $clientes->linha($linha);
                fputcsv($saida, [
                    ClientesDosSites::SITES[$c['site']] ?? $c['site'],
                    $this->texto($c['nome']),
                    $this->texto($c['email']),
                    $this->telefone($c['telefone']),
                    $c['cpf'] && strlen($c['cpf']) === 11 ? substr($c['cpf'], 0, 3).'.'.substr($c['cpf'], 3, 3).'.'.substr($c['cpf'], 6, 3).'-'.substr($c['cpf'], 9) : $c['cpf'],
                    $this->dataBr($c['cadastro_em'], true),
                    $c['recebe_promocoes'] === null ? '' : ($c['recebe_promocoes'] ? 'Sim' : 'Não'),
                    $this->texto($c['cidade']),
                    $c['uf'],
                    $c['pedidos_pagos'],
                    number_format($c['total_gasto'], 2, ',', ''),
                    $this->dataBr($c['ultimo_pedido_em'], true),
                ], ';');
            }

            fclose($saida);
        }, $arquivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{busca: ?string, de: ?string, ate: ?string, pedido: ?string, foto: ?string, ordem: string} */
    private function filtros(Request $request): array
    {
        $pedido = $request->string('pedido')->toString();
        $ordem = $request->string('ordem')->toString();

        return [
            'busca' => trim($request->string('search')->toString()) ?: null,
            'de' => $request->string('de')->toString() ?: null,
            'ate' => $request->string('ate')->toString() ?: null,
            'pedido' => in_array($pedido, ['com', 'sem'], true) ? $pedido : null,
            'foto' => in_array($foto = $request->string('foto')->toString(), ['com', 'sem'], true) ? $foto : null,
            'ordem' => in_array($ordem, ClientesDosSites::ORDENS, true) ? $ordem : 'recentes',
        ];
    }

    /** Texto digitado pelo cliente: começo com = + - @ viraria fórmula no Excel. */
    private function texto(?string $valor): string
    {
        $valor = (string) $valor;

        return preg_match('/^[=+\-@\t\r]/', $valor) ? "'".$valor : $valor;
    }

    /** Com máscara, para o Excel não tratar como número. */
    private function telefone(?string $digitos): string
    {
        $d = (string) $digitos;

        return match (strlen($d)) {
            11 => '('.substr($d, 0, 2).') '.substr($d, 2, 5).'-'.substr($d, 7),
            10 => '('.substr($d, 0, 2).') '.substr($d, 2, 4).'-'.substr($d, 6),
            default => $d,
        };
    }

    private function dataBr(?string $iso, bool $comHora = false): string
    {
        return $iso ? \Carbon\CarbonImmutable::parse($iso)->timezone(config('app.timezone'))->format($comHora ? 'd/m/Y H:i' : 'd/m/Y') : '';
    }
}
