<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;

/**
 * Conteúdo da página de brinquedo de cada produto (products.pagina_brinquedo,
 * pedido do Lira 25/11/2026: os 38 brinquedos com a página completa no formato
 * do Livro Falante, cada um com o próprio texto, ícones e kits).
 *
 * Formato (tudo opcional; o que faltar some da página ou cai no conteúdo do anúncio):
 *   titulo            título persuasivo do topo (no lugar do nome do produto)
 *   sem_telas         true mostra os ícones de "longe das telas" na história (falso em videogame)
 *   chips             [[titulo, texto] x4] abaixo das estrelas
 *   letreiro          [texto curto, ...] faixa que corre
 *   historia          {titulo, subtitulo, texto, imagem}
 *   porque            {titulo, texto, imagem, chamadas[2], cards[{titulo, texto, icone}] x4}
 *   ensina            {titulo, texto, imagem, chamadas[<=4], habilidades[{texto, icone}] x6}
 *   detalhes          {titulo, texto, imagem, cards[{titulo, texto, icone}] x4}
 *   comparativo       {titulo, intro, rotulos[comum, nosso], linhas[{texto, comum}]}
 *   familia           {titulo, texto, imagem, citacao}
 *   kits              {titulo, texto, itens[{id, nome, itens, unidades, partes[{texto, icone}]}]} do mais barato ao mais caro
 *   ctas              {historia, porque, ensina, detalhes, comparativo, familia, passos, garantia, final} texto dos botões
 *   passos            [{titulo, texto, icone}] x4
 *   garantia          [texto] x4
 *   duvidas           [[pergunta, resposta]]
 *   final             {titulo, texto}
 * "imagem" é o índice da foto do produto (0 = principal) ou o caminho de uma foto no storage
 * (products/<id>/arquivo.jpg) que ilustra a página sem estar na galeria. Ícones: nomes de icones.js (Lucide).
 */
class PaginaBrinquedo
{
    /** Palavras que não entram na página de produto (PDFs e brindes só no checkout; sem liquidação). */
    public const PROIBIDAS = ['grátis', 'gratis', 'brinde', 'pdf', 'frete', '% off', 'promoção', 'liquidação', 'desconto', 'fa-bolt'];

    /** "imagem" em texto: caminho de uma foto no storage público (products/<id>/arquivo.jpg). */
    public const CAMINHO_FOTO = '#^(?!.*\.\.)products/[A-Za-z0-9_./-]+\\.(jpe?g|png|webp)$#';

    /**
     * Fotos do storage que a página usa fora da galeria ("imagem" em texto e historia.capa).
     *
     * @return list<string>
     */
    public static function fotosDoStorage(array $pagina): array
    {
        $caminhos = [];
        array_walk_recursive($pagina, function ($valor, $chave) use (&$caminhos) {
            if (in_array($chave, ['imagem', 'capa'], true) && is_string($valor) && ! preg_match('#^(https?:)?//#', $valor)) {
                $caminhos[] = ltrim($valor, '/');
            }
        });

        return array_values(array_unique($caminhos));
    }

    public static function paraPagina(Product $product): ?array
    {
        $pagina = $product->pagina_brinquedo ?: $product->parent?->pagina_brinquedo;

        return is_array($pagina) && $pagina !== [] ? $pagina : null;
    }

    /**
     * Problemas do conteúdo (para o comando que importa): palavra proibida, ícone
     * que não existe, foto fora da galeria. Lista vazia = pode gravar.
     *
     * @param  list<string>  $icones
     * @return list<string>
     */
    public static function problemas(array $pagina, array $icones, int $fotos): array
    {
        $problemas = [];
        $texto = mb_strtolower(json_encode($pagina, JSON_UNESCAPED_UNICODE));
        foreach (self::PROIBIDAS as $palavra) {
            if (str_contains($texto, $palavra)) {
                $problemas[] = "palavra proibida: {$palavra}";
            }
        }

        array_walk_recursive($pagina, function ($valor, $chave) use (&$problemas, $icones, $fotos) {
            if ($chave === 'icone' && ! in_array($valor, $icones, true)) {
                $problemas[] = "ícone inexistente: {$valor}";
            }
            if ($chave === 'imagem' && is_string($valor) && ! preg_match(self::CAMINHO_FOTO, $valor)) {
                $problemas[] = "foto com caminho inválido: {$valor}";
            } elseif ($chave === 'imagem' && is_int($valor) && ($valor < 0 || $valor >= $fotos)) {
                $problemas[] = "foto {$valor} fora da galeria ({$fotos} fotos)";
            }
        });

        if (isset($pagina['titulo']) && mb_strlen($pagina['titulo']) > 80) {
            $problemas[] = 'título com mais de 80 caracteres';
        }

        return $problemas;
    }
}
