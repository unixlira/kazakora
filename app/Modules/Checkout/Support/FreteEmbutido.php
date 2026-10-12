<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Services\CorreiosTabelaPorEstado;
use App\Modules\Fiscal\Models\Company;
use App\Services\Correios\CorreiosTokenService;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Frete embutido no preço (pedido do Lira em 2026-10-12, config/frete_embutido.php).
 *
 * Nas telas da loja (middleware AtivarFreteEmbutido) o final_price do produto
 * já vem com o frete do mais barato entre PAC e SEDEX do contrato para a
 * região do cliente; o carrinho, o pedido e a NF-e saem por esse preço e a
 * entrega aparece como grátis. Fora da loja (admin, marketplaces, filas) o
 * preço continua o de cadastro: o serviço fica desligado.
 *
 * Preço por destino e faixa de peso, cotado na API dos Correios uma vez por
 * CEP e guardado por 24 h (11 faixas x PAC/SEDEX, em lotes de 5).
 */
class FreteEmbutido
{
    public const PAC = '03298';

    public const SEDEX = '03220';

    private bool $ligado = false;

    private ?string $cepForcado = null;

    /** @var array<string, ?array> tabela por CEP, nesta requisição */
    private array $tabelas = [];

    /** @var array<int, int>|null peso tarifável (g) por produto */
    private ?array $pesos = null;

    private ?array $destino = null;

    public function __construct(private readonly Request $request)
    {
    }

    public function ligar(): void
    {
        $this->ligado = (bool) config('frete_embutido.ativo', true);
    }

    public function desligar(): void
    {
        $this->ligado = false;
    }

    public function ligado(): bool
    {
        return $this->ligado;
    }

    /** CEP do endereço de entrega do checkout: vale acima de cookie e IP. */
    public function usarCep(?string $cep): void
    {
        $cep = self::digitos($cep);
        $this->cepForcado = strlen($cep) === 8 ? $cep : null;
        $this->destino = null;
    }

    /**
     * Região do cliente: CEP do checkout → CEP digitado (sessão/cookie) → estado
     * pelo IP (Cloudflare) → nenhuma.
     *
     * @return array{cep: ?string, uf: ?string, origem: ?string}
     */
    public function destino(): array
    {
        if ($this->destino !== null) {
            return $this->destino;
        }

        if ($this->cepForcado) {
            return $this->destino = ['cep' => $this->cepForcado, 'uf' => null, 'origem' => 'cep'];
        }

        $cep = self::digitos($this->request->hasSession() ? $this->request->session()->get('frete_embutido.cep') : null)
            ?: self::digitos($this->request->cookie((string) config('frete_embutido.cookie')));
        if (strlen($cep) === 8) {
            return $this->destino = ['cep' => $cep, 'uf' => null, 'origem' => 'cep'];
        }

        $uf = $this->ufPeloIp();
        if ($uf) {
            return $this->destino = ['cep' => CorreiosTabelaPorEstado::CAPITAIS[$uf][3], 'uf' => $uf, 'origem' => 'ip'];
        }

        return $this->destino = ['cep' => null, 'uf' => null, 'origem' => null];
    }

    /**
     * Estado pelo IP: cabeçalhos "visitor location" da Cloudflare (precisa da
     * regra "Add visitor location headers" ligada no painel). Fora da
     * produção, ?simular_uf=CE na URL testa sem Cloudflare (homolog).
     */
    private function ufPeloIp(): ?string
    {
        if (! app()->isProduction()) {
            $simulada = strtoupper((string) ($this->request->query('simular_uf') ?? ($this->request->hasSession() ? $this->request->session()->get('frete_embutido.simular_uf') : '')));
            if (isset(CorreiosTabelaPorEstado::CAPITAIS[$simulada])) {
                if ($this->request->hasSession()) {
                    $this->request->session()->put('frete_embutido.simular_uf', $simulada);
                }

                return $simulada;
            }
        }

        if (strtoupper((string) $this->request->header('CF-IPCountry')) !== 'BR') {
            return null;
        }

        $uf = strtoupper((string) $this->request->header('CF-Region-Code'));

        return isset(CorreiosTabelaPorEstado::CAPITAIS[$uf]) ? $uf : null;
    }

    /** Valor somado ao preço do produto (0 com o serviço desligado). */
    public function acrescimo(Product $product): float
    {
        return $this->ligado ? $this->paraProduto($product)['embutido'] : 0.0;
    }

    /**
     * Frete de 1 unidade para o destino atual.
     *
     * @return array{embutido: float, servico: string, prazo: ?int, pac: float, sedex: float, prazo_pac: ?int, prazo_sedex: ?int, estimado: bool}
     */
    public function paraProduto(Product $product): array
    {
        $tabela = $this->tabela($this->destino()['cep']);
        $peso = $this->pesoTarifavel($product);

        $faixa = null;
        if ($tabela) {
            foreach ($tabela as $limite => $linha) {
                if ($peso <= $limite) {
                    $faixa = $linha;
                    break;
                }
            }
        }

        if (! $faixa || $faixa['pac'] === null || $faixa['sedex'] === null) {
            $pac = (float) config('frete_embutido.fallback_pac', 30);
            $sedex = (float) config('frete_embutido.fallback_sedex', 60);

            return ['embutido' => $pac, 'servico' => 'pac', 'prazo' => null, 'pac' => $pac, 'sedex' => $sedex, 'prazo_pac' => null, 'prazo_sedex' => null, 'estimado' => true];
        }

        // O mais barato dos dois vai embutido (em SP o SEDEX do contrato sai mais em conta que o PAC).
        $sedexMaisBarato = $faixa['sedex'] <= $faixa['pac'];

        return [
            'embutido' => $sedexMaisBarato ? $faixa['sedex'] : $faixa['pac'],
            'servico' => $sedexMaisBarato ? 'sedex' : 'pac',
            'prazo' => $sedexMaisBarato ? $faixa['prazo_sedex'] : $faixa['prazo_pac'],
            'pac' => $faixa['pac'],
            'sedex' => $faixa['sedex'],
            'prazo_pac' => $faixa['prazo_pac'],
            'prazo_sedex' => $faixa['prazo_sedex'],
            'estimado' => false,
        ];
    }

    /**
     * Entrega do carrinho para o destino atual: prazo da entrega grátis e a
     * opção TURBO (SEDEX) com o valor a mais, quando ela chega antes.
     *
     * @param  Collection<int, array{product: Product, quantity: int}>  $itens
     * @return array{prazo: ?int, turbo: ?array{preco: float, prazo: ?int, dias_a_menos: ?int}, estimado: bool}
     */
    public function entregaDoCarrinho(Collection $itens): array
    {
        $extra = 0.0;
        $prazo = null;
        $prazoSedex = null;
        $estimado = false;
        $todosPac = true;

        foreach ($itens as $item) {
            $f = $this->paraProduto($item['product']);
            $estimado = $estimado || $f['estimado'];
            $todosPac = $todosPac && $f['servico'] === 'pac';
            $extra += max(0, $f['sedex'] - $f['embutido']) * (int) $item['quantity'];
            $prazo = max($prazo ?? 0, (int) $f['prazo']) ?: null;
            $prazoSedex = max($prazoSedex ?? 0, (int) $f['prazo_sedex']) ?: null;
        }

        // TURBO só quando é PAC embutido e o SEDEX chega antes (ou, sem
        // cotação, com o valor de segurança: SEDEX − PAC).
        $turbo = null;
        if ($itens->isNotEmpty() && $todosPac && $extra > 0 && ($estimado || ($prazo && $prazoSedex && $prazoSedex < $prazo))) {
            $turbo = [
                'preco' => round($extra, 2),
                'prazo' => $prazoSedex,
                'dias_a_menos' => $prazo && $prazoSedex ? $prazo - $prazoSedex : null,
            ];
        }

        return ['prazo' => $prazo, 'turbo' => $turbo, 'estimado' => $estimado];
    }

    /**
     * Preço e prazo de PAC e SEDEX por faixa de peso para um CEP (null = sem
     * cotação: entra o valor de segurança).
     *
     * @return array<int, array{pac: ?float, sedex: ?float, prazo_pac: ?int, prazo_sedex: ?int}>|null
     */
    public function tabela(?string $cep): ?array
    {
        if (! $cep) {
            return null;
        }

        if (array_key_exists($cep, $this->tabelas)) {
            return $this->tabelas[$cep];
        }

        $origem = self::digitos(Company::query()->value('zip'));
        $chave = "frete-embutido:{$origem}:{$cep}";
        $tabela = Cache::get($chave);

        if ($tabela === null) {
            $tabela = $this->cotar($origem, $cep);
            // Falha da API não fica presa: só guarda o que veio com preço.
            if ($tabela) {
                Cache::put($chave, $tabela, now()->addDay());
            }
        }

        return $this->tabelas[$cep] = $tabela ?: null;
    }

    private function cotar(string $origem, string $cep): ?array
    {
        $tokens = app(CorreiosTokenService::class);
        if (strlen($origem) !== 8 || ! $tokens->isConfigured()) {
            return null;
        }

        try {
            $token = $tokens->tokenForPrecoPrazo();
        } catch (Throwable $exception) {
            Log::channel('correios')->warning('frete_embutido.token_failed', ['message' => $exception->getMessage()]);

            return null;
        }

        $faixas = array_map('intval', (array) config('frete_embutido.faixas_g'));
        $precos = [];
        $prazos = [];
        foreach ([self::PAC, self::SEDEX] as $servico) {
            $prazos[] = ['coProduto' => $servico, 'nuRequisicao' => $servico, 'cepOrigem' => $origem, 'cepDestino' => $cep];
            foreach ($faixas as $peso) {
                // Caixa mínima dos Correios: o peso já é o tarifável (real ou cúbico).
                $precos[] = [
                    'coProduto' => $servico, 'nuRequisicao' => "{$servico}-{$peso}", 'cepOrigem' => $origem, 'cepDestino' => $cep,
                    'psObjeto' => (string) $peso, 'tpObjeto' => '2', 'comprimento' => '16', 'largura' => '11', 'altura' => '2',
                ];
            }
        }

        $urlPreco = rtrim((string) config('services.correios.preco_base_url'), '/').'/v1/nacional';
        $urlPrazo = rtrim((string) config('services.correios.prazo_base_url'), '/').'/v1/nacional';

        try {
            $respostas = Http::pool(function (Pool $pool) use ($token, $precos, $prazos, $urlPreco, $urlPrazo) {
                $pedidos = [];
                foreach (array_chunk($precos, 5) as $lote) {
                    $pedidos[] = $pool->withToken($token)->acceptJson()->timeout(15)->post($urlPreco, ['idLote' => (string) Str::uuid(), 'parametrosProduto' => $lote]);
                }
                $pedidos[] = $pool->withToken($token)->acceptJson()->timeout(15)->post($urlPrazo, ['idLote' => (string) Str::uuid(), 'parametrosPrazo' => $prazos]);

                return $pedidos;
            });
        } catch (Throwable $exception) {
            Log::channel('correios')->warning('frete_embutido.request_failed', ['message' => $exception->getMessage()]);

            return null;
        }

        $preco = [];
        $prazo = [];
        foreach ($respostas as $resposta) {
            if (! $resposta instanceof Response || $resposta->failed()) {
                continue;
            }
            foreach ((array) $resposta->json() as $item) {
                if (! is_array($item) || ! isset($item['nuRequisicao'])) {
                    continue;
                }
                if (isset($item['pcFinal']) && empty($item['txErro'])) {
                    $preco[(string) $item['nuRequisicao']] = (float) str_replace(',', '.', (string) $item['pcFinal']);
                }
                if (isset($item['prazoEntrega'])) {
                    $prazo[(string) $item['nuRequisicao']] = (int) $item['prazoEntrega'];
                }
            }
        }

        if ($preco === []) {
            Log::channel('correios')->warning('frete_embutido.sem_preco', ['cep' => $cep]);

            return null;
        }

        $tabela = [];
        foreach ($faixas as $peso) {
            $tabela[$peso] = [
                'pac' => $preco[self::PAC."-{$peso}"] ?? null,
                'sedex' => $preco[self::SEDEX."-{$peso}"] ?? null,
                'prazo_pac' => $prazo[self::PAC] ?? null,
                'prazo_sedex' => $prazo[self::SEDEX] ?? null,
            ];
        }

        return $tabela;
    }

    /**
     * Peso que os Correios cobram (g): o real, ou o cúbico (C x L x A / 6000 kg)
     * quando ele passa de 5 kg (regra do PAC/SEDEX). Mapa de todos os produtos
     * em cache por 1 h (uma consulta só por requisição).
     */
    public function pesoTarifavel(Product $product): int
    {
        if ($this->pesos === null) {
            $this->pesos = Cache::remember('frete-embutido:pesos', now()->addHour(), fn () => DB::table('product_fiscal_data')
                ->whereNotNull('peso_bruto')->where('peso_bruto', '>', 0)
                ->get(['product_id', 'peso_bruto', 'altura_cm', 'largura_cm', 'profundidade_cm'])
                ->mapWithKeys(function ($f) {
                    $real = (int) round((float) $f->peso_bruto * 1000);
                    $cubico = (int) round((float) $f->altura_cm * (float) $f->largura_cm * (float) $f->profundidade_cm / 6);

                    return [(int) $f->product_id => $cubico > 5000 ? max($real, $cubico) : $real];
                })->all());
        }

        return $this->pesos[$product->id]
            ?? ($product->parent_product_id ? ($this->pesos[$product->parent_product_id] ?? null) : null)
            ?? (int) config('frete_embutido.peso_padrao_g', 1000);
    }

    private static function digitos(mixed $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor) ?? '';
    }
}
