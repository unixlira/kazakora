<?php

namespace App\Modules\Marketplace\Support\Sugestoes;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\Sugestao;
use App\Modules\Marketplace\Support\Oportunidades\InteligenciaMercadoLivre;

/**
 * Preço do site x concorrentes (Sugestões do dia, 2026-10-20). Só sugere:
 * quem muda o preço é o Lira.
 *
 * - Preço de referência mais de 5% abaixo: baixar para logo abaixo dele, se
 *   não furar o piso de 15% de margem (regra do Rafael: custo ÷ 0,56). Se
 *   furar, avisa para não acompanhar. Referência (referencia()) e não o
 *   menor de todos: a busca por nome às vezes traz um modelo genérico mais
 *   barato (homolog 2026-10-20: pistola de água de R$ 380 x outra de
 *   R$ 208). Vale o menor preço confirmado por outra oferta até 10% acima
 *   dele, ou de uma oferta muito parecida (catálogo do ML ou semelhança alta).
 * - Todo mundo mais de 10% acima (com pelo menos 2 ofertas): dá para subir
 *   até um pouco abaixo do mais barato de todos (aqui o cuidado é o
 *   contrário: qualquer oferta mais barata segura a subida).
 * - Site mais caro que a nossa própria Shopee: o cliente que compara vai
 *   para lá.
 *
 * Preço sugerido termina em ,90.
 */
class RegrasDePreco
{
    public const BAIXAR_SE_ABAIXO = 0.95;

    public const SUBIR_SE_ACIMA = 1.10;

    public const SUBIR_ATE = 0.97;

    /** Diferença que torna a baixa urgente. */
    public const DIFERENCA_URGENTE = 0.15;

    /** Duas ofertas até 10% uma da outra confirmam o preço. */
    public const CONFIRMA_ATE = 1.10;

    /** Semelhança de nome que basta sozinha (1,0 = mesmo produto de catálogo do ML). */
    public const SEMELHANCA_FORTE = 0.6;

    /**
     * Menor preço confiável entre as ofertas (do mais barato para o mais caro).
     *
     * @param  list<array<string, mixed>>  $ofertas
     * @return array<string, mixed>|null
     */
    public static function referencia(array $ofertas): ?array
    {
        foreach ($ofertas as $i => $o) {
            $proxima = $ofertas[$i + 1] ?? null;
            if ((float) ($o['similaridade'] ?? 0) >= self::SEMELHANCA_FORTE
                || ($proxima !== null && $proxima['preco'] <= $o['preco'] * self::CONFIRMA_ATE)) {
                return $o;
            }
        }

        return null;
    }

    /**
     * @param  list<array{fonte: string, loja: string, titulo: string, preco: float, link: ?string}>  $ofertas  do mais barato para o mais caro
     * @return list<array<string, mixed>>
     */
    public function avaliar(Product $produto, array $ofertas, ?float $precoShopee = null): array
    {
        $nosso = $produto->precoSemOferta();
        if ($nosso <= 0) {
            return [];
        }

        $custo = $produto->cost_price !== null && (float) $produto->cost_price > 0 ? (float) $produto->cost_price : null;
        $piso = $custo !== null ? round($custo / InteligenciaMercadoLivre::FATOR_MARGEM_15, 2) : null;
        $id = (int) $produto->id;
        $concorrentes = array_slice(array_map(fn ($o) => [
            'loja' => $o['loja'], 'preco' => $o['preco'], 'link' => $o['link'], 'titulo' => $o['titulo'], 'fonte' => $o['fonte'],
        ], $ofertas), 0, 8);
        $dados = [
            'metricas' => array_filter([
                'Seu preço' => Texto::reais($nosso),
                'Custo' => $custo !== null ? Texto::reais($custo) : 'sem custo cadastrado',
                'Piso (15% de margem)' => $piso !== null ? Texto::reais($piso) : null,
                'Concorrentes achados' => (string) count($ofertas),
            ]),
            'concorrentes' => $concorrentes,
        ];
        $base = fn (array $s) => $s + ['area' => Sugestao::AREA_PRECO, 'titulo' => (string) $produto->name, 'product_id' => $id, 'meta_ad_id' => null, 'dados' => $dados];
        $sugestoes = [];

        $menor = $ofertas[0] ?? null;
        $referencia = self::referencia($ofertas);
        if ($referencia !== null && $referencia['preco'] < $nosso * self::BAIXAR_SE_ABAIXO) {
            $menor = $referencia;
            $alvo = self::terminaEm90($menor['preco']);
            if ($piso !== null && $alvo < $piso) {
                $sugestoes[] = $base([
                    'chave' => 'preco:piso:'.$id,
                    'tipo' => 'abaixo_do_piso',
                    'prioridade' => 3,
                    'acao' => 'Não acompanhe '.$menor['loja'].' ('.Texto::reais($menor['preco']).'): fica abaixo do seu piso de '.Texto::reais($piso).'. Destaque frete, garantia e avaliações.',
                    'motivo' => 'Você vende a '.Texto::reais($nosso).'. Baixar até o concorrente deixa menos de 15% de margem.',
                ]);
            } else {
                $diferenca = 1 - $menor['preco'] / $nosso;
                $sugestoes[] = $base([
                    'chave' => 'preco:baixar:'.$id,
                    'tipo' => 'baixar',
                    'prioridade' => $diferenca >= self::DIFERENCA_URGENTE ? 1 : 2,
                    'acao' => 'Baixe para '.Texto::reais($alvo).': '.$menor['loja'].' vende por '.Texto::reais($menor['preco']).' e você por '.Texto::reais($nosso).'.',
                    'motivo' => 'Concorrente '.Texto::pct($diferenca * 100).' mais barato.'.($custo === null ? ' Sem custo cadastrado: confira a margem antes.' : ''),
                    'valor' => $alvo,
                ]);
            }
        } elseif ($menor !== null && count($ofertas) >= 2 && $menor['preco'] > $nosso * self::SUBIR_SE_ACIMA) {
            $alvo = self::terminaEm90($menor['preco'] * self::SUBIR_ATE);
            if ($alvo > $nosso) {
                $sugestoes[] = $base([
                    'chave' => 'preco:subir:'.$id,
                    'tipo' => 'subir',
                    'prioridade' => 2,
                    'acao' => 'Dá para subir para '.Texto::reais($alvo).': o mais barato ('.$menor['loja'].') cobra '.Texto::reais($menor['preco']).'.',
                    'motivo' => 'Você está '.Texto::reais($menor['preco'] - $nosso).' abaixo de todos os '.count($ofertas).' concorrentes achados. Continua o mais barato e ganha margem.',
                    'valor' => $alvo,
                ]);
            }
        }

        if ($precoShopee !== null && $precoShopee > 0 && $precoShopee < $nosso - 0.01) {
            $sugestoes[] = $base([
                'chave' => 'preco:shopee:'.$id,
                'tipo' => 'site_mais_caro_que_shopee',
                'prioridade' => 2,
                'acao' => 'O site está mais caro que a sua Shopee ('.Texto::reais($nosso).' x '.Texto::reais($precoShopee).'). Iguale para o cliente não ir comprar lá.',
                'motivo' => 'No site a loja não paga a comissão da Shopee: ele deveria ser o mais barato.',
            ]);
        }

        return $sugestoes;
    }

    /** Maior preço terminado em ,90 abaixo do valor (R$ 489,00 vira R$ 488,90). */
    public static function terminaEm90(float $valor): float
    {
        $alvo = floor($valor) - 0.10;
        if ($alvo + 1 < $valor) {
            $alvo += 1;
        }

        return $alvo > 0 ? round($alvo, 2) : round($valor, 2);
    }
}
