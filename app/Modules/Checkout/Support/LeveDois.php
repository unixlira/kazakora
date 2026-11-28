<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Support\DescontoPix;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Compre 1, o 2º sai por R$ X" (pedido do Lira em 27/11/2026: "o segundo
 * calcula o frete e adiciona 20 reais, com certeza o segundo sai mais
 * barato, isso em todos produtos, e todo dia tem cupom para isso acontecer
 * no checkout e isso tem que ter registro no pedido").
 *
 * Preço da 2ª unidade (precoSegunda), no pior caso entre cartão e Pix, com as
 * mesmas peças da conta de lucro (ContributionMargin::aliquotaSimples() e as
 * taxas de config/ofertas.php):
 *
 *   recebido  = min(X × (1 − cartão), X × (1 − Pix do produto) × (1 − taxa Pix))
 *   lucro     = recebido − X × imposto − custo − frete incremental  >= R$ 20
 *
 * Frete incremental: a 2ª unidade vai na mesma caixa; estimativa
 * conservadora de config leve_dois.frete_por_kg (padrão ofertas.frete_estimado,
 * R$ 25) por kg tarifável começado. Insumos ficam de fora (mesma caixa) e o
 * anúncio também (a venda da 2ª unidade não custa clique a mais).
 *
 * No checkout (conta()), com o cupom do dia e 2+ unidades do mesmo produto,
 * cada par (2ª, 4ª...) sai por X. Não acumula com Oferta do Dia/Relâmpago
 * nem com o desconto por quantidade (kit): vale o que for mais barato para o
 * cliente. O desconto da caixa única (FreteEmbutido) desses itens sai do
 * cupom, porque o X já paga o frete da 2ª unidade: sem isso o mesmo frete
 * seria devolvido duas vezes. Resultado: o par custa exatamente
 * "1 unidade + X". O Pix desses pedidos vale sobre o que sobra depois do
 * cupom (DescontoPix::descontoSobreCupom com $depoisDoCupom).
 */
class LeveDois
{
    private const MEMORIA = 'leve2.memoria';

    public static function ativo(): bool
    {
        return (bool) config('leve_dois.ativo', true);
    }

    /** Código do cupom de um dia (horário de São Paulo): LEVE2 + DDMM. */
    public static function codigoDoDia(?Carbon $dia = null): string
    {
        $dia = ($dia ?? now())->copy()->setTimezone('America/Sao_Paulo');

        return Coupon::normalizar((string) config('leve_dois.prefixo', 'LEVE2')).$dia->format('dm');
    }

    /** Cupom de hoje valendo agora (ativo, no dia, sem limite estourado); null sem ele. */
    public static function cupomDoDia(): ?Coupon
    {
        if (! self::ativo()) {
            return null;
        }

        $codigo = self::codigoDoDia();
        $memoria = self::memoria();
        if (! array_key_exists('cupom:'.$codigo, $memoria)) {
            $cupom = Coupon::buscar($codigo);
            $memoria['cupom:'.$codigo] = $cupom && $cupom->segunda_unidade && $cupom->motivoInvalido(PHP_FLOAT_MAX) === null ? $cupom : null;
            app()->instance(self::MEMORIA, $memoria);
        }

        return $memoria['cupom:'.$codigo];
    }

    public static function esquecer(): void
    {
        app()->forgetInstance(self::MEMORIA);
    }

    /** Custo do produto (o da variação; sem ele, o do pai). */
    public static function custo(Product $produto): float
    {
        $custo = (float) (array_key_exists('cost_price', $produto->getAttributes())
            ? $produto->cost_price
            : DB::table('products')->where('id', $produto->id)->value('cost_price'));

        if ($custo <= 0 && $produto->parent_product_id) {
            $custo = (float) DB::table('products')->where('id', $produto->parent_product_id)->value('cost_price');
        }

        return $custo;
    }

    /** Frete incremental estimado da 2ª unidade (R$ por kg tarifável começado, mínimo 1 kg). */
    public static function freteIncremental(Product $produto): float
    {
        $porKg = config('leve_dois.frete_por_kg');
        $porKg = $porKg === null ? (float) config('ofertas.frete_estimado', 25) : (float) $porKg;
        $kg = max(1, (int) ceil(app(FreteEmbutido::class)->pesoTarifavel($produto) / 1000));

        return round($porKg * $kg, 2);
    }

    /**
     * Preço da 2ª unidade (sem frete embutido: é o preço final dela) ou null
     * quando o produto fica fora: sem custo, preço zero ou X que não fica
     * abaixo do preço normal (preço de cadastro com o desconto dele, sem o
     * frete somado na loja — a comparação mais dura).
     */
    public static function precoSegunda(Product $produto): ?float
    {
        if (! $produto->id) {
            return null;
        }

        $memoria = self::memoria();
        $chave = 'preco:'.$produto->id;
        if (array_key_exists($chave, $memoria)) {
            return $memoria[$chave];
        }

        $preco = self::calcular($produto);
        $memoria[$chave] = $preco;
        app()->instance(self::MEMORIA, $memoria);

        return $preco;
    }

    /** @return array{preco: float, custo: float, frete: float, normal: float}|null a conta, para o relatório e os testes */
    public static function detalhe(Product $produto): ?array
    {
        $preco = self::precoSegunda($produto);

        return $preco === null ? null : [
            'preco' => $preco,
            'custo' => self::custo($produto),
            'frete' => self::freteIncremental($produto),
            'normal' => $produto->precoSemOferta(),
        ];
    }

    private static function calcular(Product $produto): ?float
    {
        $custo = self::custo($produto);
        $normal = $produto->precoSemOferta();
        if ($custo <= 0 || $normal <= 0) {
            return null;
        }

        $gastos = $custo + self::freteIncremental($produto) + (float) config('leve_dois.lucro', 20);
        $imposto = ContributionMargin::aliquotaSimples() / 100;
        $cartao = (float) config('ofertas.taxa_cartao', 4.98) / 100;
        $taxaPix = (float) config('ofertas.taxa_pix', 0.99) / 100;
        // Desconto do Pix em R$ fixo vira % sobre o preço normal sem frete (o maior % possível).
        $pix = DescontoPix::percentualDo($produto, $normal) / 100;

        $fatias = array_filter([1 - $cartao - $imposto, (1 - $pix) * (1 - $taxaPix) - $imposto], fn ($f) => $f > 0);
        if (count($fatias) < 2) {
            return null;
        }

        $preco = self::arredondar($gastos / min($fatias));

        return $preco < $normal ? $preco : null;
    }

    /** Para cima, terminando em ,90 ou ,99 (107,23 → 107,90; 107,95 → 107,99; 107,995 → 108,90). */
    public static function arredondar(float $valor): float
    {
        $centavos = (int) ceil(round($valor * 100, 4));
        $inteiro = intdiv($centavos, 100);
        $resto = $centavos % 100;

        return match (true) {
            $resto <= 90 => $inteiro + 0.90,
            $resto <= 99 => $inteiro + 0.99,
        };
    }

    /**
     * Desconto do cupom no carrinho. Itens: [product, quantity, subtotal].
     *
     * @return array{total: float, itens: array<int, array{unidades: int, preco_normal: float, preco: float, desconto: float}>}
     */
    public static function conta(iterable $itens): array
    {
        $porItem = [];
        $elegiveis = collect();

        foreach ($itens as $item) {
            $produto = data_get($item, 'product');
            $quantidade = (int) data_get($item, 'quantity', 0);
            if (! $produto instanceof Product || $quantidade < 2 || $produto->emOferta()) {
                continue;
            }

            $x = self::precoSegunda($produto);
            if ($x === null) {
                continue;
            }

            // Preço normal da unidade na loja (com o frete embutido, sem o desconto do kit).
            $normal = (float) $produto->final_price;
            $pares = intdiv($quantidade, 2);
            $comPromocao = round(($quantidade - $pares) * $normal + $pares * $x, 2);
            $desconto = round((float) data_get($item, 'subtotal', 0) - $comPromocao, 2);
            if ($desconto <= 0) {
                continue;
            }

            $porItem[$produto->id] = ['unidades' => $pares, 'preco_normal' => $normal, 'preco' => $x, 'desconto' => $desconto];
            $elegiveis->push($item);
        }

        $bruto = round(array_sum(array_column($porItem, 'desconto')), 2);
        if ($bruto <= 0) {
            return ['total' => 0.0, 'itens' => []];
        }

        // Caixa única desses itens: o X já paga o frete da 2ª unidade.
        $frete = app(FreteEmbutido::class);
        $caixaUnica = $frete->ligado() ? (float) ($frete->entregaDoCarrinho($elegiveis)['desconto_plataforma'] ?? 0) : 0.0;
        $total = round(max(0.0, $bruto - $caixaUnica), 2);

        // Reparte o desconto final pelos itens (proporcional), sobra de centavo no último.
        $restante = $total;
        $ids = array_keys($porItem);
        foreach ($ids as $i => $id) {
            $parte = $i === count($ids) - 1 ? $restante : round($total * $porItem[$id]['desconto'] / $bruto, 2);
            $porItem[$id]['desconto'] = round($parte, 2);
            $restante = round($restante - $parte, 2);
        }

        return ['total' => $total, 'itens' => $total > 0 ? $porItem : []];
    }

    /**
     * Produtos da promoção hoje, para a página do produto e o JSON público.
     *
     * @return Collection<int, array{produto: Product, preco: float}>
     */
    public static function produtos(): Collection
    {
        return Product::query()
            ->naKazaKora()
            ->where('stock', '>', 0)
            ->whereDoesntHave('children', fn ($q) => $q->where('is_active', true))
            ->with(['images' => fn ($q) => $q->reorder()->orderByDesc('is_primary')->orderBy('position')->limit(1), 'parent:id,slug,name,is_active'])
            ->orderBy('name')
            ->get()
            ->reject(fn (Product $p) => $p->emOferta())
            ->map(fn (Product $p) => ['produto' => $p, 'preco' => self::precoSegunda($p)])
            ->filter(fn (array $linha) => $linha['preco'] !== null)
            ->values();
    }

    /**
     * Página do produto: código do dia e o X de cada opção (o produto e as
     * variações) que está na promoção. Null sem cupom hoje ou sem opção.
     *
     * @param  iterable<Product>  $variacoes
     * @return array{code: string, precos: array<int, float>}|null
     */
    public static function paraPagina(Product $produto, iterable $variacoes = []): ?array
    {
        $cupom = self::cupomDoDia();
        if (! $cupom) {
            return null;
        }

        $precos = [];
        foreach ([$produto, ...$variacoes] as $opcao) {
            if ($opcao instanceof Product && ! $opcao->emOferta() && ($x = self::precoSegunda($opcao)) !== null) {
                $precos[$opcao->id] = $x;
            }
        }

        return $precos === [] ? null : ['code' => $cupom->code, 'precos' => $precos];
    }

    private static function memoria(): array
    {
        return app()->bound(self::MEMORIA) ? (array) app(self::MEMORIA) : [];
    }
}
