<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * SEO da loja no HTML que o servidor entrega (pedido do Lira 10/11, tarefa 4037).
 *
 * - Descrição do produto sem emoji e sem o título em MAIÚSCULAS que abre a
 *   descrição do cadastro ("✨ EXTENSÃO ELÉTRICA ... Organize sua...").
 * - Conteúdo inicial dentro do <div id="app">: título principal (h1), texto e
 *   links para departamentos e produtos. O Google lê isso sem rodar o
 *   JavaScript; quando a loja monta, o Vue troca tudo pela página de sempre
 *   (a tela de abertura cobre esse instante, o visitante não vê diferença).
 */
class SeoDaLoja
{
    public const DESCRICAO_PADRAO = 'KazaKora: eletrônicos, gadgets e utilidades para a casa com frete rápido, compra segura e 5% de desconto no Pix.';

    /** Texto limpo: sem HTML, sem emoji/símbolo e com espaços normais. */
    public static function textoLimpo(?string $texto): string
    {
        $texto = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], ' ', (string) $texto)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Emoji, pictogramas, setas e marcadores (✨ ✅ ➡ • ★ etc.).
        $texto = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{2460}-\x{27BF}\x{2900}-\x{297F}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{2022}]/u', ' ', $texto) ?? $texto;

        return trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
    }

    /**
     * Descrição do produto para o Google (até 155 caracteres). O SEO preenchido
     * no admin vale como está; sem ele, o texto da descrição sem o título em
     * maiúsculas do começo.
     */
    public static function descricaoDoProduto(array $produto): string
    {
        if (filled($produto['seo_description'] ?? null)) {
            return trim($produto['seo_description']);
        }

        $texto = self::textoLimpo($produto['description'] ?? '');
        if ($texto === '') {
            return self::DESCRICAO_PADRAO;
        }

        // Tira o título em MAIÚSCULAS do começo (3 palavras ou mais sem letra minúscula).
        $palavras = explode(' ', $texto);
        $emCaixaAlta = 0;
        foreach ($palavras as $palavra) {
            if (preg_match('/\p{Ll}/u', $palavra)) {
                break;
            }
            $emCaixaAlta++;
        }
        if ($emCaixaAlta >= 3 && $emCaixaAlta < count($palavras)) {
            $texto = implode(' ', array_slice($palavras, $emCaixaAlta));
        }

        return Str::limit($texto, 155, '...');
    }

    /** Título do produto: o nome vem antes da marca da loja. */
    public static function tituloDoProduto(array $produto): string
    {
        return (filled($produto['seo_title'] ?? null) ? $produto['seo_title'] : $produto['name']).' | KazaKora';
    }

    /**
     * Produtos da vitrine (ativos, com estoque, sem variações), com o departamento.
     *
     * @return list<array{name: string, slug: string, category: ?string}>
     */
    public static function produtosDaVitrine(): array
    {
        return Cache::remember('seo:produtos-da-vitrine', now()->addMinutes(10), fn () => Product::query()
            ->naKazaKora()
            ->comEstoque()
            ->whereNull('parent_product_id')
            ->whereNotNull('slug')
            ->with('category:id,slug')
            ->orderByDesc('id')
            ->get(['id', 'name', 'slug', 'category_id'])
            ->map(fn (Product $p) => ['name' => (string) $p->name, 'slug' => (string) $p->slug, 'category' => $p->category?->slug])
            ->all());
    }

    /** Departamento pelo slug (só os que estão no menu da loja). */
    public static function departamento(?string $slug): ?array
    {
        if (blank($slug)) {
            return null;
        }

        return collect(MenuDaLoja::departamentos())->firstWhere('slug', $slug);
    }
}
