<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;

/**
 * Opções (variações) de um anúncio em cartões, no formato dos kits do Livro Falante
 * (pedido do Lira 26/11/2026, Compressor 4 em 1 nas duas lojas): cada opção vira um cartão
 * com selo, nome, o que vem, vantagens, preço de/por e "Economize". Liga por produto:
 * products.cartoes_opcoes no pai (vazio = seletor de variação de sempre).
 *
 * Formato:
 *   titulo   título acima dos cartões (padrão "Escolha a sua opção")
 *   itens    [{id, nome, itens, vantagens[], destaque, selo}]
 *     id         id do produto (o pai ou uma variação dele)
 *     nome       nome curto do cartão ("Kit Estrada Segura")
 *     itens      o que vem, numa linha
 *     vantagens  até 4 frases curtas, só fatos do produto
 *     destaque   true no recomendado (selo "Recomendado" e borda forte)
 *     selo       texto do selo (opcional; sem ele, o mais barato é "Para começar" e o mais caro "Mais completo")
 *
 * Preço, estoque e foto nunca vêm do JSON: a página usa os da própria opção (a mesma
 * fonte do bloco de preço e do checkout).
 */
class CartoesDeOpcoes
{
    /** Palavras que não entram nos cartões (frete e Pix só no checkout e no bloco de pagamento). */
    public const PROIBIDAS = ['grátis', 'gratis', 'frete', 'pdf', 'brinde'];

    public const MAX_VANTAGENS = 4;

    /** JSON do produto (ou do pai, numa variação); null sem cartões. */
    public static function doProduto(Product $product): ?array
    {
        $cartoes = $product->cartoes_opcoes ?: $product->parent?->cartoes_opcoes;

        return is_array($cartoes) && count($cartoes['itens'] ?? []) >= 2 ? $cartoes : null;
    }

    /**
     * Cartões para a página: só as opções que a página tem (o pai e as variações ativas), na
     * ordem do JSON, com o id no formato pedido ($prefixo, "kzv-" na FerraKora). Sobrando menos
     * de 2, null (a página volta ao seletor de sempre).
     *
     * @param  list<int>  $idsDaPagina
     * @return array{titulo: string, itens: list<array<string, mixed>>}|null
     */
    public static function paraPagina(Product $product, array $idsDaPagina, string $prefixo = ''): ?array
    {
        $cartoes = self::doProduto($product);
        if ($cartoes === null) {
            return null;
        }

        $ids = array_map('intval', $idsDaPagina);
        $itens = [];
        foreach ((array) $cartoes['itens'] as $item) {
            $id = (int) ($item['id'] ?? 0);
            if (! in_array($id, $ids, true) || trim((string) ($item['nome'] ?? '')) === '') {
                continue;
            }
            $itens[] = [
                'id' => $prefixo === '' ? $id : $prefixo.$id,
                'nome' => trim((string) $item['nome']),
                'itens' => filled($item['itens'] ?? null) ? trim((string) $item['itens']) : null,
                'vantagens' => array_slice(array_values(array_filter(array_map(
                    fn ($vantagem) => trim((string) $vantagem),
                    (array) ($item['vantagens'] ?? []),
                ))), 0, self::MAX_VANTAGENS),
                'destaque' => (bool) ($item['destaque'] ?? false),
                'selo' => filled($item['selo'] ?? null) ? trim((string) $item['selo']) : null,
            ];
        }

        return count($itens) >= 2 ? [
            'titulo' => filled($cartoes['titulo'] ?? null) ? (string) $cartoes['titulo'] : 'Escolha a sua opção',
            'itens' => $itens,
        ] : null;
    }

    /**
     * Problemas do JSON antes de gravar. Lista vazia = pode gravar.
     *
     * @return list<string>
     */
    public static function problemas(array $cartoes): array
    {
        $problemas = [];
        $itens = (array) ($cartoes['itens'] ?? []);
        if (count($itens) < 2) {
            $problemas[] = 'precisa de pelo menos 2 opções';
        }
        $texto = mb_strtolower(json_encode($cartoes, JSON_UNESCAPED_UNICODE));
        foreach (self::PROIBIDAS as $palavra) {
            if (str_contains($texto, $palavra)) {
                $problemas[] = "palavra proibida: {$palavra}";
            }
        }
        foreach ($itens as $i => $item) {
            if ((int) ($item['id'] ?? 0) < 1) {
                $problemas[] = "opção {$i} sem id";
            }
            if (trim((string) ($item['nome'] ?? '')) === '') {
                $problemas[] = "opção {$i} sem nome";
            }
            if (count((array) ($item['vantagens'] ?? [])) > self::MAX_VANTAGENS) {
                $problemas[] = "opção {$i} com mais de ".self::MAX_VANTAGENS.' vantagens';
            }
        }
        if (collect($itens)->where('destaque', true)->count() > 1) {
            $problemas[] = 'mais de uma opção em destaque';
        }

        return $problemas;
    }
}
