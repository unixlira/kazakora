<?php

namespace App\Modules\Checkout\Services;

use App\Modules\Fiscal\Models\Company;
use App\Services\Correios\CorreiosTokenService;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tabela de frete dos Correios para as 27 capitais (pedido do Lira em
 * 2026-10-12, menu Logística → Fretes por estado): PAC e SEDEX, preço do
 * contrato da KazaKora e prazo, saindo do CEP da empresa, para um pacote.
 *
 * Mesmas APIs e credencial do CorreiosFreightQuoteService (Preço e Prazo
 * /v1/nacional). A API aceita no máximo 5 itens por lote (PRZ-109,
 * confirmado em produção em 12/10), então as 54 cotações vão em lotes de 5,
 * todos ao mesmo tempo (Http::pool, ~1 s no total).
 *
 * O CEP de cada estado é o do centro da capital: o interior pode ter preço e
 * prazo diferentes. Resultado guardado por 6 h por pacote, para não bater na
 * API a cada abertura da tela.
 */
class CorreiosTabelaPorEstado
{
    public const SERVICOS = ['03298' => 'pac', '03220' => 'sedex'];

    private const LOTE = 5;

    /** UF => [estado, capital, região, CEP do centro da capital] */
    public const CAPITAIS = [
        'AC' => ['Acre', 'Rio Branco', 'Norte', '69900100'],
        'AL' => ['Alagoas', 'Maceió', 'Nordeste', '57020000'],
        'AP' => ['Amapá', 'Macapá', 'Norte', '68900073'],
        'AM' => ['Amazonas', 'Manaus', 'Norte', '69005010'],
        'BA' => ['Bahia', 'Salvador', 'Nordeste', '40020000'],
        'CE' => ['Ceará', 'Fortaleza', 'Nordeste', '60025000'],
        'DF' => ['Distrito Federal', 'Brasília', 'Centro-Oeste', '70040010'],
        'ES' => ['Espírito Santo', 'Vitória', 'Sudeste', '29010000'],
        'GO' => ['Goiás', 'Goiânia', 'Centro-Oeste', '74003010'],
        'MA' => ['Maranhão', 'São Luís', 'Nordeste', '65010000'],
        'MT' => ['Mato Grosso', 'Cuiabá', 'Centro-Oeste', '78005000'],
        'MS' => ['Mato Grosso do Sul', 'Campo Grande', 'Centro-Oeste', '79002000'],
        'MG' => ['Minas Gerais', 'Belo Horizonte', 'Sudeste', '30130000'],
        'PA' => ['Pará', 'Belém', 'Norte', '66010000'],
        'PB' => ['Paraíba', 'João Pessoa', 'Nordeste', '58010000'],
        'PR' => ['Paraná', 'Curitiba', 'Sul', '80010000'],
        'PE' => ['Pernambuco', 'Recife', 'Nordeste', '50010000'],
        'PI' => ['Piauí', 'Teresina', 'Nordeste', '64000020'],
        'RJ' => ['Rio de Janeiro', 'Rio de Janeiro', 'Sudeste', '20040000'],
        'RN' => ['Rio Grande do Norte', 'Natal', 'Nordeste', '59025000'],
        'RS' => ['Rio Grande do Sul', 'Porto Alegre', 'Sul', '90010000'],
        'RO' => ['Rondônia', 'Porto Velho', 'Norte', '76801000'],
        'RR' => ['Roraima', 'Boa Vista', 'Norte', '69301000'],
        'SC' => ['Santa Catarina', 'Florianópolis', 'Sul', '88010000'],
        'SP' => ['São Paulo', 'São Paulo', 'Sudeste', '01001000'],
        'SE' => ['Sergipe', 'Aracaju', 'Nordeste', '49010000'],
        'TO' => ['Tocantins', 'Palmas', 'Norte', '77001000'],
    ];

    public function __construct(private readonly CorreiosTokenService $tokenService)
    {
    }

    /**
     * @param  array{peso_g: int, comprimento: float, largura: float, altura: float}  $pacote
     * @return array{origem: ?string, erro: ?string, linhas: array<int, array<string, mixed>>}
     */
    public function cotar(array $pacote): array
    {
        $origem = preg_replace('/\D/', '', (string) Company::query()->first()?->zip);

        if (! $this->tokenService->isConfigured()) {
            return ['origem' => $origem ?: null, 'erro' => 'A integração com os Correios não está configurada.', 'linhas' => []];
        }

        if (! $origem) {
            return ['origem' => null, 'erro' => 'Cadastre o CEP da empresa em Configurações → Empresa.', 'linhas' => []];
        }

        $chave = 'correios.tabela-estados.'.md5(json_encode([$origem, $pacote]));

        $resultado = Cache::get($chave);
        if ($resultado === null) {
            $resultado = $this->consultar($origem, $pacote);
            // Só guarda quando veio preço: falha da API não fica presa 6 h.
            if (collect($resultado['linhas'])->contains(fn ($l) => $l['pac']['preco'] !== null || $l['sedex']['preco'] !== null)) {
                Cache::put($chave, $resultado, now()->addHours(6));
            }
        }

        return $resultado;
    }

    private function consultar(string $origem, array $pacote): array
    {
        try {
            $token = $this->tokenService->tokenForPrecoPrazo();
        } catch (Throwable $exception) {
            Log::channel('correios')->warning('correios.tabela_estados.token_failed', ['message' => $exception->getMessage()]);

            return ['origem' => $origem, 'erro' => 'Não consegui entrar na API dos Correios. Tente de novo em instantes.', 'linhas' => []];
        }

        $precos = [];
        $prazos = [];
        foreach (self::CAPITAIS as $uf => [, , , $cep]) {
            foreach (array_keys(self::SERVICOS) as $servico) {
                $precos[] = [
                    'coProduto' => $servico,
                    'nuRequisicao' => "{$uf}-{$servico}",
                    'cepOrigem' => $origem,
                    'cepDestino' => $cep,
                    'psObjeto' => (string) $pacote['peso_g'],
                    'tpObjeto' => '2',
                    'comprimento' => (string) $pacote['comprimento'],
                    'largura' => (string) $pacote['largura'],
                    'altura' => (string) $pacote['altura'],
                ];
                $prazos[] = [
                    'coProduto' => $servico,
                    'nuRequisicao' => "{$uf}-{$servico}",
                    'cepOrigem' => $origem,
                    'cepDestino' => $cep,
                ];
            }
        }

        $urlPreco = rtrim((string) config('services.correios.preco_base_url'), '/').'/v1/nacional';
        $urlPrazo = rtrim((string) config('services.correios.prazo_base_url'), '/').'/v1/nacional';

        $respostas = Http::pool(function (Pool $pool) use ($token, $precos, $prazos, $urlPreco, $urlPrazo) {
            $pedidos = [];
            foreach (array_chunk($precos, self::LOTE) as $lote) {
                $pedidos[] = $pool->withToken($token)->acceptJson()->timeout(20)
                    ->post($urlPreco, ['idLote' => (string) Str::uuid(), 'parametrosProduto' => $lote]);
            }
            foreach (array_chunk($prazos, self::LOTE) as $lote) {
                $pedidos[] = $pool->withToken($token)->acceptJson()->timeout(20)
                    ->post($urlPrazo, ['idLote' => (string) Str::uuid(), 'parametrosPrazo' => $lote]);
            }

            return $pedidos;
        });

        // nuRequisicao "UF-servico" => [preco, prazo, erro]
        $dados = [];
        $falhas = 0;
        foreach ($respostas as $resposta) {
            if (! $resposta instanceof Response || $resposta->failed()) {
                $falhas++;
                Log::channel('correios')->warning('correios.tabela_estados.failed', [
                    'status' => $resposta instanceof Response ? $resposta->status() : null,
                    'body' => $resposta instanceof Response ? $resposta->json() : (string) $resposta?->getMessage(),
                ]);

                continue;
            }

            foreach ((array) $resposta->json() as $item) {
                if (! is_array($item) || ! isset($item['nuRequisicao'])) {
                    continue;
                }
                $k = (string) $item['nuRequisicao'];
                if (isset($item['pcFinal']) && empty($item['txErro'])) {
                    // pcFinal vem como string com vírgula decimal (ex.: "23,45").
                    $dados[$k]['preco'] = (float) str_replace(',', '.', (string) $item['pcFinal']);
                }
                if (isset($item['prazoEntrega'])) {
                    $dados[$k]['prazo'] = (int) $item['prazoEntrega'];
                }
                if (! empty($item['txErro'])) {
                    $dados[$k]['erro'] = (string) $item['txErro'];
                }
            }
        }

        $linhas = [];
        foreach (self::CAPITAIS as $uf => [$estado, $capital, $regiao, $cep]) {
            $linha = ['uf' => $uf, 'estado' => $estado, 'capital' => $capital, 'regiao' => $regiao, 'cep' => $cep];
            foreach (self::SERVICOS as $codigo => $nome) {
                $d = $dados["{$uf}-{$codigo}"] ?? [];
                $linha[$nome] = ['preco' => $d['preco'] ?? null, 'prazo' => $d['prazo'] ?? null, 'erro' => $d['erro'] ?? null];
            }
            $linhas[] = $linha;
        }

        return [
            'origem' => $origem,
            'erro' => $falhas === count($respostas) ? 'A API dos Correios não respondeu. Tente de novo em instantes.' : null,
            'linhas' => $linhas,
        ];
    }
}
