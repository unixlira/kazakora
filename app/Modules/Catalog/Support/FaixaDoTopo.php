<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\ProductAdContent;
use Illuminate\Support\Str;

/**
 * Frases da faixa rolante do topo da loja (pedido do Lira 2026-11-24).
 *
 * - Home, listagens e demais páginas: as frases gerais abaixo.
 * - Página de produto: frases curtas de benefício daquele produto, tiradas do
 *   conteúdo do anúncio (ProductAdContent: destaques e títulos dos
 *   benefícios), como "Bonecos inclusos | Clique magnético | 6 ímãs em cada bloco".
 *   Produto sem conteúdo cai nas gerais; com poucas frases, as gerais completam.
 * - Black Friday: a frase dela entra antes de tudo no AppLayout.vue
 *   ($page.props.blackfriday), sem tirar as outras.
 *
 * Cada frase das gerais foi conferida no código antes de afirmar:
 * - Frete grátis: entrega grátis em todos os produtos, mas sem "para todo o
 *   Brasil": desde 2026-11-24 o CEP que pede mais frete que o embutido paga a
 *   diferença no checkout (FreteEmbutido::diferencaDoCarrinho).
 * - Pix com aprovação na hora: o checkout aceita Pix ("Aprovação imediata").
 * - Até 12x no cartão: ParcelasCartao::MAXIMO e maxInstallments do checkout.
 * - 7 dias para trocar ou devolver: página Trocas (art. 49 do CDC).
 * Nada de prazo de entrega, PDF grátis nem ícone de raio (fa-bolt).
 */
class FaixaDoTopo
{
    /** Tamanho máximo de cada frase do produto, para a faixa ficar leve. */
    public const MAX_CARACTERES = 40;

    /** Frases do produto na faixa. */
    public const MAX_FRASES = 7;

    /** Abaixo disso as frases gerais completam a faixa do produto. */
    private const MIN_FRASES = 3;

    /** @return list<array{texto: string, icone: ?string}> */
    public static function geral(): array
    {
        return [
            ['texto' => 'Frete grátis', 'icone' => 'fa-solid fa-truck-fast'],
            ['texto' => 'Compra 100% segura', 'icone' => 'fa-solid fa-lock'],
            ['texto' => 'Pix com aprovação na hora', 'icone' => 'fa-brands fa-pix'],
            ['texto' => 'Parcele em até 12x no cartão', 'icone' => 'fa-solid fa-credit-card'],
            ['texto' => '7 dias para trocar ou devolver', 'icone' => 'fa-solid fa-rotate-left'],
        ];
    }

    /**
     * Faixa da página de produto: frases do produto ou, sem conteúdo, as gerais.
     *
     * @return list<array{texto: string, icone: ?string}>
     */
    public static function paraProduto(?ProductAdContent $conteudo): array
    {
        $frases = array_map(
            fn (string $texto) => ['texto' => $texto, 'icone' => 'fa-solid fa-circle-check'],
            self::frasesDoProduto($conteudo),
        );

        if ($frases === []) {
            return self::geral();
        }

        if (count($frases) < self::MIN_FRASES) {
            $frases = [...$frases, ...array_slice(self::geral(), 0, self::MIN_FRASES + 1 - count($frases))];
        }

        return $frases;
    }

    /**
     * Destaques e títulos dos benefícios, curtos e sem repetir.
     *
     * @return list<string>
     */
    public static function frasesDoProduto(?ProductAdContent $conteudo): array
    {
        if (! $conteudo) {
            return [];
        }

        $candidatas = [
            ...self::textos($conteudo->destaques),
            ...array_map(
                fn ($beneficio) => is_array($beneficio) ? ($beneficio[0] ?? $beneficio['titulo'] ?? '') : $beneficio,
                is_array($conteudo->beneficios) ? $conteudo->beneficios : [],
            ),
        ];

        $frases = [];
        $chaves = [];
        foreach ($candidatas as $candidata) {
            $frase = self::encurtar(is_string($candidata) ? $candidata : '');
            if ($frase === null) {
                continue;
            }

            $chave = self::chave($frase);
            $repetida = collect($chaves)->contains(fn (string $outra) => str_contains($outra, $chave) || str_contains($chave, $outra));
            if ($chave === '' || $repetida) {
                continue;
            }

            $frases[] = $frase;
            $chaves[] = $chave;
            if (count($frases) >= self::MAX_FRASES) {
                break;
            }
        }

        return $frases;
    }

    /** @return list<string> */
    private static function textos(mixed $lista): array
    {
        return is_array($lista) ? array_values(array_filter($lista, 'is_string')) : [];
    }

    /**
     * Tira emoji e pontuação final; frase longa vira o trecho antes de ":",
     * " - ", "," ou " + " ("2ª bateria grátis: trabalhe o dobro..." vira
     * "2ª bateria grátis"). Se nem assim couber, fica de fora.
     */
    private static function encurtar(string $texto): ?string
    {
        $limpo = self::limpar($texto);

        if (mb_strlen($limpo) > self::MAX_CARACTERES) {
            $partes = preg_split('/\s*(?::|\s[-–—]\s|,|\s\+\s|\.\s|;)\s*/u', $limpo) ?: [];
            $limpo = self::limpar((string) ($partes[0] ?? ''));
        }

        $tamanho = mb_strlen($limpo);

        return $tamanho >= 3 && $tamanho <= self::MAX_CARACTERES ? $limpo : null;
    }

    private static function limpar(string $texto): string
    {
        $sem = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{FE0F}\x{200D}\x{20E3}]/u', '', $texto) ?? $texto;
        $sem = preg_replace('/\s+/u', ' ', $sem) ?? $sem;

        // Sem trim() com "–" e "—" na lista: o trim corta byte a byte e arrancava o 1º byte de
        // emoji como o ⏱ (E2 8F B1), o texto virava UTF-8 inválido e a página dava 500 (27/11/2026).
        return preg_replace('/^[\s.!;:,\-–—|]+|[\s.!;:,\-–—|]+$/u', '', $sem) ?? trim($sem);
    }

    /** Para comparar sem acento, caixa e pontuação. */
    private static function chave(string $frase): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii($frase))) ?? '';
    }
}
