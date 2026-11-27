<?php

namespace App\Modules\Checkout\Support;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Http\Controllers\CheckoutController;
use App\Modules\Checkout\Models\Coupon;
use App\Support\DescontoPix;
use App\Support\SiteAtual;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Black Friday automática da KazaKora (pedido do Lira em 24/11/2026): o cupom
 * da faixa (config loja.cupom_faixa, o BLACKFRIDAY de 5%) entra sozinho no
 * carrinho e no checkout até config loja.blackfriday_ate (env
 * LOJA_BLACKFRIDAY_ATE, horário de Brasília). Passou a data, desliga tudo
 * sozinho: aplicação, faixas e riscados.
 *
 * Preço com o cupom num lugar só (precos()): a página do produto, as vitrines
 * e o checkout usam as mesmas peças (subtotal do CartManager, base do cupom
 * Coupon::baseDosItens, Coupon::discountFor, desconto da caixa única do
 * FreteEmbutido e DescontoPix::descontoSobreCupom), na mesma ordem do
 * CheckoutController::storePayment:
 *
 *   subtotal  = preço da unidade (já com o frete embutido e o desconto do kit) x quantidade
 *   cupom     = 5% da base do cupom (produto fora de Oferta do Dia/Relâmpago), no centavo
 *   por       = subtotal - cupom - desconto da caixa única (kits)
 *   pix       = por - % do Pix sobre (subtotal - caixa única), sem passar do que sobrou
 *
 * Cupom único (regra que já existia): o carrinho tem um cupom só. Se o cliente
 * tira o BLACKFRIDAY, ele não volta sozinho (fica marcado no rascunho) e a
 * faixa do checkout mostra o botão para aplicar de novo; para usar outro
 * cupom, o cliente tira o BLACKFRIDAY e digita o outro, que fica no lugar
 * (não acumula).
 */
class BlackFriday
{
    /** Marca no rascunho do checkout: o cupom entrou sozinho (sai sozinho quando a BF acaba). */
    public const AUTO = 'coupon_auto';

    /** Marca no rascunho do checkout: o cliente tirou o BLACKFRIDAY; não reaplicar sozinho. */
    public const REMOVIDO = 'blackfriday_removido';

    private const MEMORIA = 'blackfriday.cupom';

    /** Fim da Black Friday automática (horário de Brasília); null = desligada. */
    public static function ate(): ?Carbon
    {
        $ate = trim((string) config('loja.blackfriday_ate'));

        return $ate === '' ? null : rescue(fn () => Carbon::parse($ate, 'America/Sao_Paulo'), null, false);
    }

    /** A data fim existe e já passou: faixas, cards e riscados da BF somem. */
    public static function encerrada(): bool
    {
        $ate = self::ate();

        return $ate !== null && now()->greaterThan($ate);
    }

    /** "30/11" para os textos. */
    public static function ateTexto(): ?string
    {
        return self::ate()?->copy()->setTimezone('America/Sao_Paulo')->format('d/m');
    }

    /**
     * Cupom da BF valendo agora para qualquer cliente: dentro da data, ativo,
     * no prazo do cadastro, sem pedido mínimo e sem dono. Null fora disso.
     */
    public static function cupom(): ?Coupon
    {
        $ate = self::ate();
        if ($ate === null || now()->greaterThan($ate)) {
            return null;
        }

        $codigo = Coupon::normalizar((string) config('loja.cupom_faixa'));
        if ($codigo === '') {
            return null;
        }

        // Uma leitura do cupom por requisição (vitrine com dezenas de cards).
        $memoria = app()->bound(self::MEMORIA) ? app(self::MEMORIA) : null;
        if (! is_array($memoria) || $memoria['codigo'] !== $codigo) {
            $memoria = ['codigo' => $codigo, 'cupom' => Coupon::buscar($codigo)];
            app()->instance(self::MEMORIA, $memoria);
        }
        $cupom = $memoria['cupom'];

        if (! $cupom || $cupom->user_id || $cupom->min_order_value || $cupom->motivoInvalido(PHP_FLOAT_MAX) !== null) {
            return null;
        }

        return $cupom;
    }

    /** Cupom mudou no admin: a próxima leitura busca de novo. */
    public static function esquecer(): void
    {
        app()->forgetInstance(self::MEMORIA);
    }

    public static function noAr(): bool
    {
        return self::cupom() !== null;
    }

    /** "5% OFF" (ou "R$ 10,00 OFF") do cupom. */
    public static function rotulo(Coupon $cupom): string
    {
        return str_replace(' de desconto', ' OFF', $cupom->descricaoDesconto());
    }

    /** Dados da faixa do topo do site; null fora da BF. */
    public static function faixa(): ?array
    {
        $cupom = self::cupom();

        return $cupom ? ['code' => $cupom->code, 'rotulo' => self::rotulo($cupom), 'ate' => self::ateTexto()] : null;
    }

    /** Só na loja KazaKora (FerraKora, Beauty Kora, admin e filas: nada). */
    private static function naKazaKora(): bool
    {
        return app(SiteAtual::class)->atual() === SiteAtual::KAZAKORA;
    }

    /**
     * Preço de $quantidade unidades com o cupom da BF, pela conta do checkout.
     * Null fora da BF, fora da KazaKora ou quando o cupom não vale para o
     * produto (Oferta do Dia/Relâmpago, cupom de outros produtos, preço zero:
     * brinde e infoproduto grátis não recebem desconto).
     *
     * @return array{code: string, rotulo: string, ate: ?string, quantidade: int, de: float, cupom: float, plataforma: float, por: float, pix: ?float}|null
     */
    public static function precos(Product $produto, int $quantidade = 1, bool $comCaixaUnica = true, bool $outroSite = false): ?array
    {
        // FerraKora (preço único, 26/11/2026): o checkout de lá também aplica o BLACKFRIDAY
        // sozinho, então o card mostra a mesma conta, se o cupom não for só da KazaKora.
        if ((! self::naKazaKora() && ! $outroSite) || ! ($cupom = self::cupom()) || ($outroSite && $cupom->so_kazakora)) {
            return null;
        }

        $quantidade = max(1, $quantidade);
        // Igual ao CartManager::items(). Sem as faixas de kit carregadas (cards da vitrine), 1 unidade é o final_price.
        $unitario = $quantidade === 1 && ! $produto->relationLoaded('quantityDiscounts')
            ? (float) $produto->final_price
            : $produto->unitPriceForQuantity($quantidade);
        $subtotal = round($unitario * $quantidade, 2);
        $itens = collect([['product' => $produto, 'quantity' => $quantidade, 'subtotal' => $subtotal]]);

        $base = Coupon::baseDosItens($itens, $cupom);
        if ($subtotal <= 0 || $base <= 0) {
            return null;
        }

        $desconto = $cupom->discountFor($base);
        $plataforma = $comCaixaUnica && $quantidade > 1 ? self::caixaUnica($itens) : 0.0;
        $por = round($subtotal - $desconto - $plataforma, 2);
        $pct = DescontoPix::percentualDosItens($itens);
        $pix = $pct > 0 ? round($por - DescontoPix::descontoSobreCupom($subtotal - $plataforma, $desconto, $pct), 2) : null;

        // Preço grande = o de venda (config loja.blackfriday_preco_principal_produtos): riscado o
        // "de" do cadastro, o com cupom vira linha de baixo. Senão (padrão): grande = com cupom.
        $principalVenda = in_array((int) ($produto->parent_product_id ?: $produto->id), array_map('intval', (array) config('loja.blackfriday_preco_principal_produtos', [])), true);
        $antes = $principalVenda && $produto->preco_de ? round((float) $produto->preco_de * $quantidade, 2) : null;

        return [
            'code' => $cupom->code,
            'rotulo' => self::rotulo($cupom),
            'ate' => self::ateTexto(),
            'quantidade' => $quantidade,
            'principal' => $principalVenda ? 'venda' : 'cupom',
            'antes' => $antes,
            'de' => $subtotal,
            'cupom' => $desconto,
            'plataforma' => $plataforma,
            'por' => $por,
            'pix' => $pix,
        ];
    }

    /**
     * Tabela da página do produto: 1 unidade até o maior kit (no máximo 10 e o
     * estoque), mais cada kit cadastrado. Chave = quantidade.
     *
     * @return array<int, array>|null
     */
    public static function porQuantidade(Product $produto): ?array
    {
        if (! self::precos($produto, 1, false)) {
            return null;
        }

        $kits = $produto->quantityDiscounts->pluck('min_quantity')->map(fn ($q) => (int) $q);
        $limite = max(1, (int) $produto->stock);
        $quantidades = collect(range(1, max(1, min(10, $limite, (int) ($kits->max() ?? 1)))))
            ->concat($kits->filter(fn ($q) => $q >= 1 && $q <= $limite))
            ->unique()->sort()->values();

        return $quantidades->mapWithKeys(fn (int $q) => [$q => self::precos($produto, $q)])->filter()->all() ?: null;
    }

    /** Desconto da caixa única (2+ unidades), o mesmo que o checkout dá. */
    private static function caixaUnica(Collection $itens): float
    {
        $frete = app(FreteEmbutido::class);

        return $frete->ligado() ? (float) rescue(fn () => $frete->entregaDoCarrinho($itens)['desconto_plataforma'] ?? 0, 0.0, false) : 0.0;
    }

    /**
     * Põe o BLACKFRIDAY sozinho no rascunho do checkout (produto entrando no
     * carrinho, carrinho recuperado, link direto do checkout). Não mexe quando
     * já há um cupom (cupom único) ou quando o cliente tirou o BLACKFRIDAY.
     * Fora da BF, tira o que tinha entrado sozinho.
     *
     * @param  Collection<int, array{product: Product, quantity: int, subtotal: float}>  $itens
     */
    public static function aplicarNoRascunho(Session $sessao, Collection $itens, ?User $user): void
    {
        $chave = CheckoutController::SESSION_KEY;
        $rascunho = (array) ($sessao->get($chave) ?? []);
        $cupom = self::naKazaKora() ? self::cupom() : null;

        if (! $cupom) {
            if (! empty($rascunho[self::AUTO])) {
                unset($rascunho['coupon_code'], $rascunho[self::AUTO]);
                $sessao->put($chave, $rascunho);
            }

            return;
        }

        if (! empty($rascunho['coupon_code']) || ! empty($rascunho[self::REMOVIDO]) || $itens->isEmpty()) {
            return;
        }

        $subtotal = round($itens->sum('subtotal'), 2);
        if ($cupom->motivoInvalido($subtotal, $user) !== null || Coupon::baseDosItens($itens, $cupom) <= 0) {
            return;
        }

        $rascunho['coupon_code'] = $cupom->code;
        $rascunho[self::AUTO] = true;
        $sessao->put($chave, $rascunho);
    }

    /** O cupom do rascunho entrou sozinho e a BF já acabou: tem que sair. */
    public static function venceuNoRascunho(array $rascunho): bool
    {
        return ! empty($rascunho[self::AUTO]) && ! self::noAr();
    }
}
