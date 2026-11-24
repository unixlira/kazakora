<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Support\BlackFriday;
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

    /** Só apresentação: não altera conteúdo cadastrado nem cálculo comercial.
     * Converte economia monetária apenas quando coincide com um desconto real
     * e sua base de preço "de" é inequívoca, incluindo frete embutido.
     */
    public static function comparativoPercentual(?array $comparativo, iterable $produtos): ?array
    {
        if (empty($comparativo['oferta']['faixas']) || strcasecmp($comparativo['oferta']['selo'] ?? '', self::SELO) !== 0) {
            return $comparativo;
        }
        $percentuais = [];
        foreach ($produtos as $produto) {
            $base = (float) $produto->preco_de;
            $economia = round($base - (float) $produto->final_price, 2);
            if ($base <= 0 || $economia <= 0 || $produto->emOferta()) {
                continue;
            }
            $chave = (int) round($economia * 100);
            // Mantém a precisão anterior para detectar bases ambíguas; só a exibição perde as casas.
            $pct = round($economia / $base * 100, 2);
            $percentuais[$chave][(string) $pct] = (int) floor($pct).'%';
        }
        foreach ($comparativo['oferta']['faixas'] as &$faixa) {
            $faixa['valor'] = preg_replace_callback('/R\$\s*([\d.]+,\d{2})(?=\s+(?:de desconto|off)\b)/iu', function (array $match) use ($percentuais): string {
                $centavos = (int) round((float) str_replace([ '.', ',' ], [ '', '.' ], $match[1]) * 100);
                $opcoes = array_values($percentuais[$centavos] ?? []);

                return count($opcoes) === 1 ? $opcoes[0] : $match[0];
            }, $faixa['valor'] ?? '');
        }
        unset($faixa);

        return $comparativo;
    }

    /**
     * @param  int  $livros  quantos livros digitais a página deste produto mostra
     * @param  bool  $outroSite  true na FerraKora (cupom só da KazaKora não vale lá)
     * @return array{tipo: string, selo: string, titulo: string, texto: string, ate: ?string}|null
     */
    public static function padrao(Product $produto, int $livros = 0, bool $outroSite = false): ?array
    {
        $cupom = self::cupom($produto, $outroSite);

        // Black Friday automática (2026-11-24): o cupom já entra sozinho no pedido da KazaKora.
        if ($cupom && ! $outroSite && BlackFriday::cupom()?->code === $cupom->code) {
            return [
                'tipo' => 'cupom',
                'selo' => self::SELO,
                'titulo' => $cupom->descricaoDesconto().' extra',
                'texto' => 'Cupom '.$cupom->code.' já aplicado no seu pedido. Válido até '.BlackFriday::ateTexto().'.',
                'ate' => BlackFriday::ate()?->format('Y-m-d'),
            ];
        }

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
        // Passou a data da Black Friday automática (loja.blackfriday_ate): o card some junto.
        if (BlackFriday::encerrada()) {
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
