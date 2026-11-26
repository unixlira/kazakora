<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;

/**
 * Escada de kits entre produtos diferentes (pedido do Lira 26/11/2026, oferta do
 * Compressor 4 em 1): cada degrau é um produto próprio, com preço, estoque, fotos
 * e vídeo próprios, e a página de cada um mostra os cartões de todos (products.escada_kits,
 * o mesmo JSON gravado em cada produto da escada pelo comando kits:escada).
 *
 * Formato:
 *   titulo   título da seção ("Escolha o seu kit")
 *   texto    linha de apoio (opcional)
 *   itens    [{id, nome, itens, partes[texto], acrescenta, destaque, selo}] na ordem da escada
 *     id          id do produto na KazaKora
 *     nome        nome de oferta do kit ("Kit Estrada Segura")
 *     itens       o que vem, numa linha ("Compressor + Kit Reparo + Chave Catraca")
 *     partes      cada item que vem, para a lista do cartão
 *     acrescenta  o que este degrau acrescenta ao anterior, numa frase
 *     destaque    true no kit recomendado (selo "Mais vendido")
 *     selo        texto do selo (opcional; o destaque sem selo usa "Mais vendido")
 *     foto        caminho no disco público da foto do kit nos cartões (opcional; sem ela, a 1ª foto
 *                 do produto). Serve para o kit cuja capa é uma arte com texto, ilegível pequena.
 *
 * Preço e estoque nunca vêm do JSON: saem do cadastro de cada produto, na hora.
 */
class EscadaDeKits
{
    /** Palavras que não entram nos cartões (frete só no checkout, oferta sem "desconto", sem PDF no nome). */
    public const PROIBIDAS = ['grátis', 'gratis', 'pdf', 'frete', 'desconto'];

    /** O JSON do produto (ou do pai, para as variações); null sem escada. */
    public static function doProduto(Product $product): ?array
    {
        $escada = $product->escada_kits ?: $product->parent?->escada_kits;

        return is_array($escada) && count($escada['itens'] ?? []) >= 2 ? $escada : null;
    }

    /** Ids dos produtos da escada, na ordem. @return list<int> */
    public static function ids(array $escada): array
    {
        return array_values(array_filter(array_map(fn ($item) => (int) ($item['id'] ?? 0), (array) ($escada['itens'] ?? []))));
    }

    /**
     * Problemas do JSON antes de gravar: palavra proibida, kit sem id ou sem nome,
     * mais de um destaque. Lista vazia = pode gravar.
     *
     * @return list<string>
     */
    public static function problemas(array $escada): array
    {
        $problemas = [];
        $itens = (array) ($escada['itens'] ?? []);
        if (count($itens) < 2) {
            $problemas[] = 'a escada precisa de pelo menos 2 kits';
        }
        $texto = mb_strtolower(json_encode($escada, JSON_UNESCAPED_UNICODE));
        foreach (self::PROIBIDAS as $palavra) {
            if (str_contains($texto, $palavra)) {
                $problemas[] = "palavra proibida: {$palavra}";
            }
        }
        foreach ($itens as $i => $item) {
            if ((int) ($item['id'] ?? 0) < 1) {
                $problemas[] = "kit {$i} sem id";
            }
            if (trim((string) ($item['nome'] ?? '')) === '') {
                $problemas[] = "kit {$i} sem nome";
            }
        }
        if (collect($itens)->where('destaque', true)->count() > 1) {
            $problemas[] = 'mais de um kit em destaque';
        }

        return $problemas;
    }

    /** Foto própria do kit (item.foto, caminho no disco público), no formato das fotos da API. */
    private static function fotoPropria(array $item): ?array
    {
        $url = FotosDoComparativo::url($item['foto'] ?? null);

        return $url === null ? null : ['url' => $url, 'thumb_url' => $url, 'media_url' => $url];
    }

    /**
     * Cartões para a página da KazaKora (pedido do Lira 26/11/2026: a oferta também no KazaKora),
     * no mesmo formato dos do FerraKora, com o preço da loja (final_price e preco_de, em reais)
     * e a URL da página de cada kit. Kit fora da loja ou desligado não aparece; sobrando menos
     * de 2, sem escada.
     *
     * @return array{titulo: string, texto: ?string, itens: list<array<string, mixed>>}|null
     */
    public static function paraKazaKora(Product $atual): ?array
    {
        $escada = self::doProduto($atual);
        if ($escada === null) {
            return null;
        }

        $produtos = Product::query()->with('images')->whereIn('id', self::ids($escada))->get()->keyBy('id');
        $atualId = (int) ($atual->parent_product_id ?: $atual->id);
        $itens = [];
        foreach ((array) $escada['itens'] as $item) {
            /** @var Product|null $produto */
            $produto = $produtos->get((int) ($item['id'] ?? 0));
            if ($produto === null || ! $produto->naKazaKora()) {
                continue;
            }
            $primeira = $produto->images->first();
            $foto = self::fotoPropria($item)
                ?? ($primeira ? ['url' => $primeira->url, 'thumb_url' => $primeira->thumb_url, 'media_url' => $primeira->media_url] : null);
            $destaque = (bool) ($item['destaque'] ?? false);
            $por = (float) $produto->final_price;
            $de = $produto->preco_de !== null && (float) $produto->preco_de > $por ? (float) $produto->preco_de : null;

            $itens[] = [
                'id' => $produto->id,
                'slug' => $produto->slug,
                'url' => route('produtos.ver', $produto->slug, false),
                'titulo' => $produto->name,
                'nome' => (string) $item['nome'],
                'itens' => (string) ($item['itens'] ?? ''),
                'partes' => array_values(array_filter(array_map('strval', (array) ($item['partes'] ?? [])))),
                'acrescenta' => filled($item['acrescenta'] ?? null) ? (string) $item['acrescenta'] : null,
                'destaque' => $destaque,
                'selo' => filled($item['selo'] ?? null) ? (string) $item['selo'] : ($destaque ? 'Mais vendido' : null),
                'foto' => $foto,
                'preco' => ['de' => $de, 'por' => $por],
                'estoque' => (int) $produto->stock,
                'disponivel' => (int) $produto->stock > 0,
                'atual' => $produto->id === $atualId,
            ];
        }

        return count($itens) >= 2 ? [
            'titulo' => (string) ($escada['titulo'] ?? 'Escolha o seu kit'),
            'texto' => filled($escada['texto'] ?? null) ? (string) $escada['texto'] : null,
            'itens' => $itens,
        ] : null;
    }

    /**
     * Cartões para o site (FerraKora), a partir da lista de produtos do site já montada
     * (preço com o frete da região, em centavos, igual ao do produto). Kit fora do site
     * ou desligado não aparece; sobrando menos de 2, sem escada.
     *
     * @param  array<int, array<string, mixed>>  $lista  produtos do site (CatalogoOutroSite::produtos)
     * @return array{titulo: string, texto: ?string, itens: list<array<string, mixed>>}|null
     */
    public static function paraSite(Product $atual, array $lista, string $prefixo = 'kz-'): ?array
    {
        $escada = self::doProduto($atual);
        if ($escada === null) {
            return null;
        }

        $porId = collect($lista)->keyBy('id');
        $atualId = (int) ($atual->parent_product_id ?: $atual->id);
        $itens = [];
        foreach ((array) $escada['itens'] as $item) {
            $produto = $porId->get($prefixo.(int) ($item['id'] ?? 0));
            $variante = $produto['variants'][0] ?? null;
            $preco = $variante['prices'][0] ?? null;
            if ($produto === null || $preco === null) {
                continue;
            }
            $por = (int) ($preco['sale_amount'] ?? $preco['amount']);
            $de = $preco['sale_amount'] !== null && (int) $preco['amount'] > $por ? (int) $preco['amount'] : null;
            $foto = self::fotoPropria($item) ?? ($produto['images'][0] ?? null);
            $destaque = (bool) ($item['destaque'] ?? false);

            $itens[] = [
                'id' => $produto['id'],
                'variante_id' => $variante['id'],
                'slug' => $produto['slug'],
                'titulo' => $produto['title'],
                'nome' => (string) $item['nome'],
                'itens' => (string) ($item['itens'] ?? ''),
                'partes' => array_values(array_filter(array_map('strval', (array) ($item['partes'] ?? [])))),
                'acrescenta' => filled($item['acrescenta'] ?? null) ? (string) $item['acrescenta'] : null,
                'destaque' => $destaque,
                'selo' => filled($item['selo'] ?? null) ? (string) $item['selo'] : ($destaque ? 'Mais vendido' : null),
                'foto' => $foto ? ['url' => $foto['url'], 'thumb_url' => $foto['thumb_url'] ?? null, 'media_url' => $foto['media_url'] ?? null] : null,
                'preco' => ['de' => $de, 'por' => $por],
                'estoque' => (int) ($variante['inventory_quantity'] ?? 0),
                'disponivel' => (bool) ($variante['is_available'] ?? false),
                'atual' => (int) ($item['id'] ?? 0) === $atualId,
            ];
        }

        return count($itens) >= 2 ? [
            'titulo' => (string) ($escada['titulo'] ?? 'Escolha o seu kit'),
            'texto' => filled($escada['texto'] ?? null) ? (string) $escada['texto'] : null,
            'itens' => $itens,
        ] : null;
    }
}
