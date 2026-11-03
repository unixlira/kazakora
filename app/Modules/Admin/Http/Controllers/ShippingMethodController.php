<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Jobs\PreencherTabelaFreteJob;
use App\Modules\Checkout\Support\TabelaFreteLocalidades;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\Correios\CorreiosTokenService;
use App\Services\MelhorEnvio\MelhorEnvioAuthService;
use App\Support\FreteProvedor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Logística (/admin/logistica). Desde 2026-11-03 (pedido do Lira) a tela
 * mostra Correios e Melhor Envio lado a lado e só um fica conectado:
 * "Conectar" num desconecta o outro (App\Support\FreteProvedor) e a tabela
 * de frete da tela passa a ser a do conectado na hora.
 *
 * Antes o Melhor Envio aparecia "Conectado" só por existir token no banco,
 * mesmo vencido desde 30/08 e com a renovação recusada; agora conectado =
 * conta que responde.
 */
class ShippingMethodController extends Controller
{
    public function __construct(private readonly MelhorEnvioAuthService $melhorEnvioAuth) {}

    public function index(TabelaFreteLocalidades $tabela, CorreiosTokenService $correios): Response
    {
        $ativo = FreteProvedor::escolhido();
        $contaMelhorEnvio = $this->contaMelhorEnvio();

        return Inertia::render('Admin/Logistica/Index', [
            'shippingMethods' => ShippingMethod::query()->orderBy('price')->get(),
            'provedorAtivo' => $ativo,
            'provedores' => [
                [
                    'chave' => FreteProvedor::CORREIOS,
                    'nome' => 'Correios',
                    'descricao' => 'Contrato próprio da loja: PAC e SEDEX no preço do site, pré-postagem e etiqueta dos Correios.',
                    'conectado' => $ativo === FreteProvedor::CORREIOS,
                    'disponivel' => $correios->isConfigured(),
                    'aviso' => $correios->isConfigured() ? null : 'Credencial dos Correios não configurada no servidor.',
                    'conta' => null,
                    'cobertura' => $this->cobertura(FreteProvedor::CORREIOS),
                ],
                [
                    'chave' => FreteProvedor::MELHOR_ENVIO,
                    'nome' => 'Melhor Envio',
                    'descricao' => 'Transportadoras da conta do Melhor Envio: a mais barata vai no preço do site e a etiqueta é comprada com o saldo da carteira.',
                    'conectado' => $ativo === FreteProvedor::MELHOR_ENVIO,
                    'disponivel' => $contaMelhorEnvio['valida'],
                    'aviso' => $contaMelhorEnvio['aviso'],
                    'conta' => $contaMelhorEnvio['conta'],
                    'cobertura' => $this->cobertura(FreteProvedor::MELHOR_ENVIO),
                ],
            ],
            'tabela' => $this->tabelaDeReferencia($tabela),
            'preenchimento' => PreencherTabelaFreteJob::status(),
        ]);
    }

    /**
     * Conecta um provedor e desconecta o outro. Melhor Envio sem conta válida
     * vai primeiro ao login do Melhor Envio e conecta na volta (callback).
     */
    public function conectar(Request $request, string $provedor, TabelaFreteLocalidades $tabela, CorreiosTokenService $correios): HttpResponse
    {
        abort_unless(in_array($provedor, FreteProvedor::TODOS, true), 404);

        if ($provedor === FreteProvedor::CORREIOS && ! $correios->isConfigured()) {
            return back()->with('error', 'A credencial dos Correios não está configurada no servidor.');
        }

        if ($provedor === FreteProvedor::MELHOR_ENVIO && ! $this->contaMelhorEnvio()['valida']) {
            $request->session()->put('frete.conectar_melhor_envio', true);

            return Inertia::location('/api/melhorenvio/auth');
        }

        $mensagem = self::ativar($provedor, $tabela);

        return back()->with('success', $mensagem);
    }

    /**
     * Troca o provedor, já cota o CEP de referência do preço do site (o preço
     * muda na hora) e põe o resto da tabela do provedor na fila.
     */
    public static function ativar(string $provedor, TabelaFreteLocalidades $tabela): string
    {
        $anterior = FreteProvedor::escolhido();
        FreteProvedor::ativar($provedor);

        Log::info('frete.provedor_trocado', ['de' => $anterior, 'para' => $provedor, 'user_id' => auth()->id()]);

        $nome = FreteProvedor::NOMES[$provedor];
        $outro = FreteProvedor::NOMES[$provedor === FreteProvedor::CORREIOS ? FreteProvedor::MELHOR_ENVIO : FreteProvedor::CORREIOS];

        $cep = $tabela->cepDeCotacao((string) config('frete_embutido.cep_fixo'));
        $erro = null;
        if ($cep && ! $tabela->paraCep($cep)) {
            try {
                $tabela->cotar([$cep]);
            } catch (Throwable $e) {
                $erro = $e->getMessage();
                Log::warning('frete.provedor_cotacao_referencia_falhou', ['provedor' => $provedor, 'message' => $erro]);
            }
        }

        $pendentes = count($tabela->cepsPendentes(1));
        if ($pendentes > 0) {
            PreencherTabelaFreteJob::iniciar([]);
        }

        return "{$nome} conectado e {$outro} desconectado."
            .($erro ? " A cotação do CEP de referência falhou ({$erro}); o site usa o valor de segurança até a tabela preencher." : '')
            .($pendentes > 0 ? ' O resto da tabela de frete está sendo preenchido em segundo plano.' : '');
    }

    public function store(Request $request): RedirectResponse
    {
        ShippingMethod::create($this->validated($request));

        return back()->with('success', 'Método de envio criado.');
    }

    public function update(Request $request, ShippingMethod $shippingMethod): RedirectResponse
    {
        $shippingMethod->update($this->validated($request));

        return back()->with('success', 'Método de envio atualizado.');
    }

    public function destroy(ShippingMethod $shippingMethod): RedirectResponse
    {
        $shippingMethod->delete();

        return back()->with('success', 'Método de envio removido.');
    }

    /**
     * Conta do Melhor Envio que responde de verdade (token renovável).
     *
     * @return array{valida: bool, conta: ?string, aviso: ?string}
     */
    private function contaMelhorEnvio(): array
    {
        $token = $this->melhorEnvioAuth->currentToken();
        if (! $token) {
            return ['valida' => false, 'conta' => null, 'aviso' => 'Nenhuma conta conectada: "Conectar" abre o login do Melhor Envio.'];
        }

        try {
            $token = $this->melhorEnvioAuth->ensureValidToken($token);
        } catch (Throwable) {
            return ['valida' => false, 'conta' => $token->account_label, 'aviso' => 'O acesso à conta venceu: "Conectar" abre o login do Melhor Envio de novo.'];
        }

        return ['valida' => true, 'conta' => $token->account_label, 'aviso' => null];
    }

    /** @return array{ceps: int, cotados: int, ultima: ?string} */
    private function cobertura(string $provedor): array
    {
        $linhas = DB::table('frete_tabela')->where('provedor', $provedor);

        return [
            'cotados' => (clone $linhas)->whereNotNull('pac_preco')->distinct()->count('cep_destino'),
            'ceps' => DB::table('frete_localidades')->whereNotNull('cep_cotacao')->distinct()->count('cep_cotacao'),
            'ultima' => (clone $linhas)->max('cotado_em'),
        ];
    }

    /**
     * Frete do provedor conectado no CEP de referência do preço do site
     * (frete_embutido.cep_fixo), por categoria de tamanho: é o que vai somado
     * ao preço dos produtos classificados.
     */
    private function tabelaDeReferencia(TabelaFreteLocalidades $tabela): array
    {
        $cep = (string) config('frete_embutido.cep_fixo');
        $linhas = $cep ? ($tabela->paraCep($cep)['categorias'] ?? []) : [];
        $categorias = DB::table('frete_categorias_pacote')->whereNotNull('ref_peso_g')->orderBy('ordem')->get();

        return [
            'cep' => $cep ?: null,
            'linhas' => $categorias->map(fn ($c) => [
                'categoria' => $c->chave,
                'nome' => $c->nome ?? ucfirst(str_replace('_', ' ', $c->chave)),
                'caixa' => "{$c->ref_peso_g} g, ".rtrim(rtrim((string) $c->ref_comprimento_cm, '0'), '.').' x '.rtrim(rtrim((string) $c->ref_largura_cm, '0'), '.').' x '.rtrim(rtrim((string) $c->ref_altura_cm, '0'), '.').' cm',
                'barato' => $linhas[$c->chave]['pac'] ?? null,
                'barato_prazo' => $linhas[$c->chave]['prazo_pac'] ?? null,
                'barato_servico' => $linhas[$c->chave]['servico_pac'] ?? null,
                'rapido' => $linhas[$c->chave]['sedex'] ?? null,
                'rapido_prazo' => $linhas[$c->chave]['prazo_sedex'] ?? null,
                'rapido_servico' => $linhas[$c->chave]['servico_sedex'] ?? null,
                'erro' => $linhas[$c->chave]['erro'] ?? null,
            ])->values(),
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'estimated_days' => ['required', 'integer', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }
}
