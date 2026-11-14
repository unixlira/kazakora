<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Coupon;
use App\Support\DescontoPix;

/**
 * Card de Black Friday acima do preço em TODOS os produtos (pedido do Lira em
 * 14/11/2026: "essa parte de black friday tem que aparecer para todos
 * produtos"; "se não tiver algo grátis, algo positivo, vantajoso").
 *
 * O produto com oferta própria no cadastro (comparativo.oferta do conteúdo do
 * anúncio, como a bateria da Parafusadeira 48V) continua mostrando a dele. Os
 * outros recebem este card padrão, que só afirma vantagem que o checkout dá
 * de verdade para AQUELE produto, nesta ordem:
 *
 *  1. cupom da faixa do checkout (config loja.cupom_faixa, o BLACKFRIDAY de
 *     5%), se está ativo, no prazo e vale para o produto. Produto em Oferta do
 *     Dia ou Relâmpago fica fora: o checkout não aceita cupom nele;
 *  2. livros digitais de brinde, quando a página do produto mostra o quadro;
 *  3. desconto no Pix do cadastro do produto.
 *
 * Sem nenhuma das três, não tem card (nada é inventado). O card some sozinho
 * quando o cupom vence.
 */
class OfertaBlackFriday
{
    public const SELO = 'Black Friday';

    /**
     * @param  int  $livros  quantos livros digitais a página deste produto mostra
     * @param  bool  $outroSite  true na FerraKora (cupom só da KazaKora não vale lá)
     * @return array{tipo: string, selo: string, titulo: string, texto: string, ate: ?string}|null
     */
    public static function padrao(Product $produto, int $livros = 0, bool $outroSite = false): ?array
    {
        $cupom = self::cupom($produto, $outroSite);

        if ($cupom) {
            $ate = $cupom->expires_at;

            return [
                'tipo' => 'cupom',
                'selo' => self::SELO,
                'titulo' => $cupom->descricaoDesconto().' extra',
                'texto' => 'No checkout, toque em Aplicar na faixa da Black Friday e o desconto entra na hora.'
                    .($ate ? ' Vale até '.$ate->format('d/m').'.' : ''),
                'ate' => $ate?->format('Y-m-d'),
            ];
        }

        if ($livros > 0) {
            return [
                'tipo' => 'livros',
                'selo' => self::SELO,
                'titulo' => $livros === 1 ? '1 livro digital de brinde' : $livros.' livros digitais de brinde',
                'texto' => 'Comprou, ganhou: '.($livros === 1 ? 'o livro chega' : 'os livros chegam').' no seu e-mail depois do pagamento.',
                'ate' => null,
            ];
        }

        $pix = self::descontoPix($produto);

        if ($pix !== null) {
            return [
                'tipo' => 'pix',
                'selo' => self::SELO,
                'titulo' => $pix.' de desconto no Pix',
                'texto' => 'Escolha Pix no checkout: o desconto entra na hora e o pagamento é aprovado em segundos.',
                'ate' => null,
            ];
        }

        return null;
    }

    /** Cupom da faixa do checkout, se vale hoje para este produto. */
    private static function cupom(Product $produto, bool $outroSite): ?Coupon
    {
        $codigo = Coupon::normalizar((string) config('loja.cupom_faixa'));
        $cupom = $codigo !== '' ? Coupon::buscar($codigo) : null;

        if (! $cupom || ($outroSite && $cupom->so_kazakora) || $cupom->user_id || $cupom->min_order_value) {
            return null;
        }
        // Subtotal alto de propósito: o pedido mínimo já foi barrado acima; aqui valem ativo, prazo e limite de usos.
        if ($cupom->motivoInvalido(PHP_FLOAT_MAX) !== null) {
            return null;
        }
        if (! $cupom->valeParaProduto($produto) || $produto->emOferta()) {
            return null;
        }

        return $cupom;
    }

    /** "2%" ou "R$ 7,00": o desconto no Pix do cadastro, do jeito que o checkout aplica. */
    private static function descontoPix(Product $produto): ?string
    {
        if (DescontoPix::precoPix($produto) === null) {
            return null;
        }

        $pct = (float) ($produto->checkout_discount_percentage ?? 0);
        if ($pct > 0) {
            return rtrim(rtrim(number_format($pct, 2, ',', '.'), '0'), ',').'%';
        }

        $valor = (float) ($produto->checkout_discount_amount ?? 0);

        return $valor > 0 ? 'R$ '.number_format($valor, 2, ',', '.') : null;
    }
}
