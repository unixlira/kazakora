<?php

namespace App\Modules\App\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Address;
use App\Modules\Checkout\Services\CheckoutOutroSite;
use App\Support\SiteAtual;

/**
 * Checkout do app nativo da KazaKora (2026-10-18). Mesmas regras do checkout
 * do site (CheckoutController::storePayment): preço com o frete embutido do
 * CEP de entrega e a Oferta Relâmpago da KazaKora, entrega FULL (Grande SP)
 * ou Correios grátis com a opção TURBO, cupom, desconto da caixa única e o
 * Pix só sobre os produtos (nunca sobre o frete). Sem as transportadoras do
 * Melhor Envio, que o checkout da KazaKora não oferece.
 *
 * O pedido é venda do site KazaKora: origin "loja", site_origem nulo, ligado
 * à conta do token e ao endereço salvo dela (address_id, como no site). O
 * app fica marcado só em orders.app_plataforma, que nenhuma conta lê.
 */
class CheckoutApp extends CheckoutOutroSite
{
    /** SiteAtual da requisição: a Oferta Relâmpago que vale é a da loja KazaKora. */
    public function site(): string
    {
        return SiteAtual::KAZAKORA;
    }

    protected function siteOrigemDoPedido(): ?string
    {
        return null;
    }

    protected function modeloPublicado(): string
    {
        return Product::class;
    }

    /** A loja inteira: todo produto pai ativo está à venda no app. */
    protected function paisPublicados(array $paiIds): array
    {
        return Product::query()
            ->whereIn('id', $paiIds)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function nomeDoSite(): string
    {
        return 'app da KazaKora';
    }

    protected function comOutrasTransportadoras(): bool
    {
        return false;
    }

    /** Como no site: desconto do Pix sobre os produtos (já com cupom e caixa única), fora o frete. */
    protected function baseDoDescontoPix(float $total, float $frete): float
    {
        return max(0, $total - $frete);
    }

    protected function contaDoPedido(array $dados): ?User
    {
        return $dados['conta'];
    }

    protected function enderecoDaConta(User $user, array $entrega, array $dados): Address
    {
        return $dados['endereco_salvo'];
    }

    protected function colunasDoPedido(?User $user, object $address, array $dados): array
    {
        return [
            // Trava a exclusão do endereço em "Meu Perfil", como no site.
            'address_id' => $address instanceof Address ? $address->id : null,
            'app_plataforma' => $dados['app_plataforma'] ?? null,
        ];
    }
}
