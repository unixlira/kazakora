<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\FreteCategoriaPacote;
use App\Modules\Checkout\Services\CorreiosTabelaPorEstado;
use App\Modules\Fiscal\Models\Company;
use App\Services\Correios\CorreiosTokenService;
use App\Support\SiteAtual;
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
 *
 * Tabela por localidade (pedido 2026-10-25, TabelaFreteLocalidades): produto
 * com categoria de tamanho (Pequeno, Médio, Grande, Extra Grande) usa o
 * PAC/SEDEX salvo no banco para a localidade do CEP, sem chamar os Correios.
 * As regras por lugar valem por cima dele do mesmo jeito.
 *
 * Regras por lugar (pedido 2026-10-24, RegrasDeFrete): onde houver regra
 * para a cidade, UF ou faixa de CEP do cliente, o frete embutido segue a
 * regra (valor fixo, acréscimo, desconto ou teto sobre os Correios). O custo
 * real da caixa (custo) continua o dos Correios: a loja paga a diferença.
 * A regra pode ser só de uma categoria de tamanho do pacote (Pequena, Média,
 * Grande, Extra Grande; FreteCategoriaPacote).
 *
 * Trava do frete (pedido 2026-10-24, TravaDeFrete): o frete que o visitante
 * viu ao calcular pelo CEP fica guardado pelo IP e pela hash do aparelho e,
 * enquanto valer, não sobe para ele.
 */
class FreteEmbutido
{
    public const PAC = '03298';

    public const SEDEX = '03220';

    /** shipping_carrier_name do pedido com TURBO (ContributionMargin abate do frete). */
    public const TURBO = 'SEDEX (TURBO)';

    private bool $ligado = false;

    private ?string $cepForcado = null;

    /** @var array<string, ?array> tabela por CEP, nesta requisição */
    private array $tabelas = [];

    /** @var array<int, array{0: int, 1: float}>|null peso tarifável (g) e maior lado (cm) por produto */
    private ?array $pacotes = null;

    private ?array $destino = null;

    /** @var array<int, string>|null categoria de frete por produto (pais das variações) */
    private ?array $categorias = null;

    private const CACHE_CATEGORIAS = 'frete-embutido:categorias';

    /** @var list<array>|null regras de frete que valem para o destino atual */
    private ?array $regras = null;

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
        $this->regras = null;
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
     * Estado da região em que o cliente navega (CEP digitado antes do checkout
     * ou IP). Chamar ANTES de usarCep(): o CEP da entrega vale acima dos dois.
     */
    public function ufDaNavegacao(): ?string
    {
        $destino = $this->destino();

        return $destino['uf'] ?? CepUf::porCep($destino['cep'] ?? null);
    }

    /**
     * Estado pelo IP: cabeçalhos "visitor location" da Cloudflare (precisa da
     * regra "Add visitor location headers" ligada no painel). Fora do
     * kazakora.com, ?simular_uf=CE na URL testa sem Cloudflare (o homolog
     * roda com APP_ENV=production, então a regra é pelo domínio).
     */
    private function ufPeloIp(): ?string
    {
        if (parse_url((string) config('app.url'), PHP_URL_HOST) !== 'kazakora.com') {
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
     * @return array{embutido: float, servico: string, prazo: ?int, pac: float, sedex: float, prazo_pac: ?int, prazo_sedex: ?int, estimado: bool, correios?: float, regra?: string}
     */
    public function paraProduto(Product $product): array
    {
        $frete = $this->freteDoProduto($product);

        return $this->semFreteEmbutido($product) ? [...$frete, 'correios' => $frete['correios'] ?? $frete['embutido'], 'embutido' => 0.0] : $this->fixoNaNavegacao($product, $frete);
    }

    /**
     * Preço fixo na navegação (pedido do Lira em 2026-10-25): o frete somado
     * ao produto na primeira tela em que o visitante o viu (pela região dele:
     * CEP digitado ou estado pelo IP) fica guardado na sessão e vale na
     * vitrine, no produto, no carrinho, no checkout e no pagamento, mesmo que
     * o CEP mude depois. O custo real da caixa (correios) continua o do
     * destino atual: a loja paga a diferença.
     */
    private function fixoNaNavegacao(Product $product, array $frete): array
    {
        if (! $this->ligado || ! config('frete_embutido.fixo_na_navegacao', true)
            || $this->site() !== SiteAtual::KAZAKORA || ! $this->request->hasSession()) {
            return $frete;
        }

        $chave = 'frete_embutido.fixo.'.$product->id;
        $fixo = $this->request->session()->get($chave);
        if ($fixo === null) {
            $this->request->session()->put($chave, $frete['embutido']);

            return $frete;
        }

        if (abs((float) $fixo - $frete['embutido']) < 0.005) {
            return $frete;
        }

        return [...$frete, 'correios' => $frete['correios'] ?? $frete['embutido'], 'embutido' => (float) $fixo, 'fixo' => true];
    }

    /**
     * Produto com preço fixo na tela do KazaKora (frete_embutido.produtos_sem_frete,
     * pedido 2026-10-25): nada somado ao preço; a loja paga o frete da caixa.
     */
    public function semFreteEmbutido(Product $product): bool
    {
        $ids = array_map('intval', (array) config('frete_embutido.produtos_sem_frete', []));
        if ($ids === [] || $this->site() !== SiteAtual::KAZAKORA) {
            return false;
        }

        return in_array((int) $product->id, $ids, true)
            || ($product->parent_product_id && in_array((int) $product->parent_product_id, $ids, true));
    }

    private function freteDoProduto(Product $product): array
    {
        $peso = $this->pesoTarifavel($product);
        $categoria = $this->categoria($product);

        if ($salvo = $this->daTabelaDeLocalidades($product)) {
            return $this->comTrava($product, $this->comRegra($salvo, $peso, $categoria));
        }

        $tabela = $this->tabela($this->destino()['cep']);

        $faixa = $this->faixa($tabela, $peso);

        if (! $faixa) {
            $pac = (float) config('frete_embutido.fallback_pac', 30);
            $sedex = (float) config('frete_embutido.fallback_sedex', 60);

            return $this->comTrava($product, $this->comRegra(['embutido' => $pac, 'servico' => 'pac', 'prazo' => null, 'pac' => $pac, 'sedex' => $sedex, 'prazo_pac' => null, 'prazo_sedex' => null, 'estimado' => true], $peso, $categoria));
        }

        // O mais barato dos dois vai embutido (em SP o SEDEX do contrato sai mais em conta que o PAC).
        $sedexMaisBarato = $faixa['sedex'] <= $faixa['pac'];

        return $this->comTrava($product, $this->comRegra([
            'embutido' => $sedexMaisBarato ? $faixa['sedex'] : $faixa['pac'],
            'servico' => $sedexMaisBarato ? 'sedex' : 'pac',
            'prazo' => $sedexMaisBarato ? $faixa['prazo_sedex'] : $faixa['prazo_pac'],
            'pac' => $faixa['pac'],
            'sedex' => $faixa['sedex'],
            'prazo_pac' => $faixa['prazo_pac'],
            'prazo_sedex' => $faixa['prazo_sedex'],
            'estimado' => false,
        ], $peso, $categoria));
    }

    /**
     * Frete salvo na tabela por localidade (pedido 2026-10-25,
     * TabelaFreteLocalidades): produto que o Lira classificou numa categoria
     * de tamanho e destino já cotado usam o PAC/SEDEX gravado, sem chamar os
     * Correios. Sem classificação ou sem cotação para o CEP, null: segue a
     * cotação por peso.
     */
    private function daTabelaDeLocalidades(Product $product): ?array
    {
        $categoria = $this->categoriaFrete($product);
        $linha = $categoria ? app(TabelaFreteLocalidades::class)->linha($this->destino()['cep'], $categoria) : null;
        if (! $linha) {
            return null;
        }

        // Localidade sem um dos serviços: vale o que tem.
        $pac = $linha['pac'] ?? $linha['sedex'];
        $sedex = $linha['sedex'] ?? $linha['pac'];
        $prazoPac = $linha['pac'] !== null ? $linha['prazo_pac'] : $linha['prazo_sedex'];
        $prazoSedex = $linha['sedex'] !== null ? $linha['prazo_sedex'] : $linha['prazo_pac'];
        $sedexMaisBarato = $sedex <= $pac;

        return [
            'embutido' => $sedexMaisBarato ? $sedex : $pac,
            'servico' => $sedexMaisBarato ? 'sedex' : 'pac',
            'prazo' => $sedexMaisBarato ? $prazoSedex : $prazoPac,
            'pac' => $pac,
            'sedex' => $sedex,
            'prazo_pac' => $prazoPac,
            'prazo_sedex' => $prazoSedex,
            'estimado' => false,
            'tabela' => $categoria,
        ];
    }

    /** Categoria escolhida no produto (a do pai, na variação sem a própria); null = não classificado. */
    public function categoriaFrete(Product $product): ?string
    {
        if ($product->categoria_frete) {
            return $product->categoria_frete;
        }
        if (! $product->parent_product_id) {
            return null;
        }

        $this->categorias ??= Cache::remember(self::CACHE_CATEGORIAS, now()->addMinutes(10), fn () => DB::table('products')
            ->whereNotNull('categoria_frete')->pluck('categoria_frete', 'id')->all());

        return $this->categorias[$product->parent_product_id] ?? null;
    }

    /** Limpa o mapa de categorias depois de classificar produtos. */
    public static function esquecerCategorias(): void
    {
        Cache::forget(self::CACHE_CATEGORIAS);
    }

    /**
     * Aplica a regra de frete do destino (se houver) ao frete de 1 unidade:
     * embutido passa a ser o da regra e correios guarda a cotação original.
     */
    private function comRegra(array $frete, int $peso, ?string $categoria): array
    {
        $regra = RegrasDeFrete::paraPacote($this->regras(), $peso, $categoria);
        if (! $regra) {
            return $frete;
        }

        return [
            ...$frete,
            'correios' => $frete['embutido'],
            'embutido' => RegrasDeFrete::aplicar($regra, $frete['embutido']),
            'prazo' => $regra['prazo_dias'] ?? $frete['prazo'],
            'regra' => $regra['nome'],
        ];
    }

    /**
     * Trava do visitante para o produto e o CEP (TravaDeFrete): o frete nunca
     * sobe acima do que ele já viu. Só com CEP digitado e na loja.
     */
    private function comTrava(Product $product, array $frete): array
    {
        $cep = $this->cepDigitado();
        if (! $cep) {
            return $frete;
        }

        $trava = app(TravaDeFrete::class)->doProduto($this->site(), $cep, (int) $product->id, $product->parent_product_id ? (int) $product->parent_product_id : null);
        if (! $trava || $trava->embutido >= $frete['embutido']) {
            return $frete;
        }

        return [
            ...$frete,
            'correios' => $frete['correios'] ?? $frete['embutido'],
            'embutido' => $trava->embutido,
            'prazo' => $trava->prazo_dias ?? $frete['prazo'],
            'travado' => true,
        ];
    }

    /** CEP que o visitante digitou (produto ou checkout), na loja; null fora dela ou pelo IP. */
    private function cepDigitado(): ?string
    {
        $destino = $this->destino();

        return $this->ligado && $destino['origem'] === 'cep' ? $destino['cep'] : null;
    }

    private function site(): string
    {
        return app(SiteAtual::class)->atual() ?? SiteAtual::KAZAKORA;
    }

    /** Regras de frete do site atual que valem para o destino (memória da requisição). */
    public function regras(): array
    {
        if ($this->regras !== null) {
            return $this->regras;
        }

        $destino = $this->destino();
        // Destino pelo IP é o CEP da capital: só a regra da UF vale, não a da cidade.
        $cep = $destino['origem'] === 'cep' ? $destino['cep'] : null;
        if (! $cep && ! $destino['uf']) {
            return $this->regras = [];
        }

        return $this->regras = app(RegrasDeFrete::class)->paraDestino(
            $this->site(),
            $cep,
            $destino['uf'],
        );
    }

    /**
     * Entrega do carrinho para o destino atual.
     *
     * - prazo da entrega grátis;
     * - desconto_plataforma: com mais de uma unidade, os itens vão numa caixa
     *   só e o frete real (peso somado) sai menor que o frete embutido item a
     *   item; a diferença volta como desconto (pedido 2026-10-12);
     * - turbo: SEDEX da caixa inteira pela diferença, quando chega antes;
     * - servico, custo e peso_g: serviço e frete da caixa que o preço já paga
     *   e o peso tarifável somado (base das outras transportadoras).
     *
     * @param  Collection<int, array{product: Product, quantity: int}>  $itens
     * @return array{prazo: ?int, turbo: ?array{preco: float, prazo: ?int, dias_a_menos: ?int}, estimado: bool, desconto_plataforma: float, servico?: string, custo?: float, peso_g?: int}
     */
    public function entregaDoCarrinho(Collection $itens): array
    {
        $embutido = 0.0;
        $extraItemAItem = 0.0;
        $peso = 0;
        $lado = 0.0;
        $unidades = 0;
        $estimado = false;
        $prazosTravados = [];
        $cepDigitado = $this->cepDigitado();
        $fretes = [];

        foreach ($itens as $item) {
            $qtd = (int) $item['quantity'];
            $f = $this->paraProduto($item['product']);
            $fretes[] = [$f, $qtd];
            $estimado = $estimado || $f['estimado'];
            $embutido += $f['embutido'] * $qtd;
            $extraItemAItem += max(0, $f['sedex'] - ($f['correios'] ?? $f['embutido'])) * $qtd;
            $peso += $this->pesoTarifavel($item['product']) * $qtd;
            $lado = max($lado, $this->maiorLado($item['product']));
            $unidades += $qtd;
            if (! empty($f['travado'])) {
                $prazosTravados[] = $f['prazo'];
            }
            // Frete calculado pelo CEP do visitante: fica travado para ele.
            if ($cepDigitado) {
                app(TravaDeFrete::class)->registrar($this->site(), $cepDigitado, (int) $item['product']->id, $f);
            }
        }

        if ($itens->isEmpty()) {
            return ['prazo' => null, 'turbo' => null, 'estimado' => false, 'desconto_plataforma' => 0.0, 'servico' => 'pac', 'custo' => 0.0, 'peso_g' => 0];
        }

        if (collect($fretes)->every(fn ($par) => isset($par[0]['tabela']))) {
            return $this->entregaPelaTabela($fretes, $peso);
        }

        // A caixa inteira (peso somado) na tabela do destino.
        $caixa = $estimado ? null : $this->faixa($this->tabela($this->destino()['cep']), $peso);

        if (! $caixa) {
            // Sem cotação (ou acima de 30 kg): fica o frete item a item, sem
            // desconto. O TURBO continua aparecendo, pelo valor de segurança.
            return [
                'prazo' => null,
                'turbo' => self::turboAteOTeto($extraItemAItem > 0 ? ['preco' => round($extraItemAItem, 2), 'prazo' => null, 'dias_a_menos' => null] : null),
                'estimado' => true,
                'desconto_plataforma' => 0.0,
                'servico' => 'pac',
                'custo' => round($embutido, 2),
                'peso_g' => $peso,
            ];
        }

        $sedexMaisBarato = $caixa['sedex'] <= $caixa['pac'];
        $real = $sedexMaisBarato ? $caixa['sedex'] : $caixa['pac'];
        $prazo = $sedexMaisBarato ? $caixa['prazo_sedex'] : $caixa['prazo_pac'];

        // Regra da cidade/UF: o cliente paga o frete da regra pela caixa inteira
        // (categoria da caixa: peso somado e o maior lado dos itens).
        $regra = RegrasDeFrete::paraPacote($this->regras(), $peso, FreteCategoriaPacote::classificar($peso, $lado));
        $doCliente = $regra ? RegrasDeFrete::aplicar($regra, $real) : $real;
        $prazo = $regra['prazo_dias'] ?? $prazo;
        // Todos os itens travados: vale o prazo que o visitante já viu.
        if ($prazosTravados !== [] && count($prazosTravados) === $itens->count() && ! in_array(null, $prazosTravados, true)) {
            $prazo = max($prazosTravados);
        }

        $turbo = null;
        if (! $sedexMaisBarato && $caixa['prazo_pac'] && $caixa['prazo_sedex'] && $caixa['prazo_sedex'] < $caixa['prazo_pac']) {
            $turbo = [
                'preco' => round($caixa['sedex'] - $caixa['pac'], 2),
                'prazo' => $caixa['prazo_sedex'],
                'dias_a_menos' => $caixa['prazo_pac'] - $caixa['prazo_sedex'],
            ];
        }

        return [
            'prazo' => $prazo,
            'turbo' => self::turboAteOTeto($turbo),
            'estimado' => false,
            'desconto_plataforma' => $unidades > 1 ? round(max(0, $embutido - $doCliente), 2) : 0.0,
            // Frete da caixa que o preço já paga (o desconto acima devolve o excesso).
            'servico' => $sedexMaisBarato ? 'sedex' : 'pac',
            'custo' => round($real, 2),
            'peso_g' => $peso,
            'regra' => $regra['nome'] ?? null,
        ];
    }

    /**
     * Carrinho todo com frete da tabela por localidade (pedido 2026-10-25):
     * cada item vai na caixa da categoria dele, então o frete é o somado item
     * a item, sem desconto de caixa única e sem chamar os Correios. Prazo = o
     * do item mais demorado; TURBO = SEDEX de todos pela diferença, quando
     * chega antes.
     *
     * @param  list<array{0: array, 1: int}>  $fretes  frete de 1 unidade e quantidade
     */
    private function entregaPelaTabela(array $fretes, int $peso): array
    {
        $custo = 0.0;
        $extra = 0.0;
        $prazo = null;
        $prazoBase = null;
        $prazoSedex = null;
        $todosSedex = true;

        foreach ($fretes as [$f, $qtd]) {
            $base = $f['correios'] ?? $f['embutido'];
            $custo += $base * $qtd;
            $extra += max(0, $f['sedex'] - $base) * $qtd;
            $prazo = max($prazo, $f['prazo']);
            $prazoBase = max($prazoBase, $f['servico'] === 'sedex' ? $f['prazo_sedex'] : $f['prazo_pac']);
            $prazoSedex = max($prazoSedex, $f['prazo_sedex']);
            $todosSedex = $todosSedex && $f['servico'] === 'sedex';
        }

        $turbo = $extra > 0 && $prazoSedex && $prazoBase && $prazoSedex < $prazoBase
            ? ['preco' => round($extra, 2), 'prazo' => $prazoSedex, 'dias_a_menos' => $prazoBase - $prazoSedex]
            : null;

        return [
            'prazo' => $prazo,
            'turbo' => self::turboAteOTeto($turbo),
            'estimado' => false,
            'desconto_plataforma' => 0.0,
            'servico' => $todosSedex ? 'sedex' : 'pac',
            'custo' => round($custo, 2),
            'peso_g' => $peso,
            'regra' => collect($fretes)->map(fn ($par) => $par[0]['regra'] ?? null)->filter()->first(),
        ];
    }

    /**
     * TURBO na área FULL (pedido 2026-10-23): mesmo preço (SEDEX pela
     * diferença), sem o "dias antes", que compara com o PAC.
     *
     * @param  array{preco: float, prazo: ?int, dias_a_menos: ?int}|null  $turbo
     */
    public static function turboNoFull(?array $turbo): ?array
    {
        return $turbo ? [...$turbo, 'dias_a_menos' => null] : null;
    }

    /** TURBO acima de frete_embutido.turbo_max some do checkout (pedido 2026-10-12). */
    private static function turboAteOTeto(?array $turbo): ?array
    {
        return $turbo && $turbo['preco'] <= (float) config('frete_embutido.turbo_max', 60) ? $turbo : null;
    }

    /** Linha da tabela que comporta o peso (null = sem tabela ou acima da última faixa). */
    private function faixa(?array $tabela, int $peso): ?array
    {
        foreach ($tabela ?? [] as $limite => $linha) {
            if ($peso <= $limite) {
                return $linha['pac'] !== null && $linha['sedex'] !== null ? $linha : null;
            }
        }

        return null;
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
     * quando ele passa de 5 kg (regra do PAC/SEDEX).
     */
    public function pesoTarifavel(Product $product): int
    {
        return $this->pacoteDoProduto($product)[0] ?? (int) config('frete_embutido.peso_padrao_g', 1000);
    }

    /** Maior lado da caixa do produto (cm); sem medidas = 0. */
    public function maiorLado(Product $product): float
    {
        return $this->pacoteDoProduto($product)[1] ?? 0.0;
    }

    /**
     * Categoria de tamanho do pacote de 1 unidade (FreteCategoriaPacote): a
     * que o Lira escolheu no produto (2026-10-25) ou, sem ela, pelo peso
     * tarifável e pelo maior lado.
     */
    public function categoria(Product $product): ?string
    {
        return $this->categoriaFrete($product)
            ?? FreteCategoriaPacote::classificar($this->pesoTarifavel($product), $this->maiorLado($product));
    }

    /**
     * Peso tarifável e maior lado do produto (ou do pai). Mapa de todos os
     * produtos em cache por 1 h (uma consulta só por requisição).
     *
     * @return array{0: int, 1: float}|null
     */
    private function pacoteDoProduto(Product $product): ?array
    {
        if ($this->pacotes === null) {
            $this->pacotes = Cache::remember('frete-embutido:pacotes', now()->addHour(), fn () => DB::table('product_fiscal_data')
                ->whereNotNull('peso_bruto')->where('peso_bruto', '>', 0)
                ->get(['product_id', 'peso_bruto', 'altura_cm', 'largura_cm', 'profundidade_cm'])
                ->mapWithKeys(function ($f) {
                    $real = (int) round((float) $f->peso_bruto * 1000);
                    $cubico = (int) round((float) $f->altura_cm * (float) $f->largura_cm * (float) $f->profundidade_cm / 6);

                    return [(int) $f->product_id => [
                        $cubico > 5000 ? max($real, $cubico) : $real,
                        (float) max((float) $f->altura_cm, (float) $f->largura_cm, (float) $f->profundidade_cm),
                    ]];
                })->all());
        }

        return $this->pacotes[$product->id]
            ?? ($product->parent_product_id ? ($this->pacotes[$product->parent_product_id] ?? null) : null);
    }

    private static function digitos(mixed $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor) ?? '';
    }
}
