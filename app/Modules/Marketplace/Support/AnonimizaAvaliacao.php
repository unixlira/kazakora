<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Support\NomeBrasileiro;

/**
 * Pedido do Lira 2026-10-22: avaliação importada da Shopee ou do Mercado
 * Livre (compras reais da própria loja nesses canais) entra no site com
 * nome sorteado no lugar do apelido do comprador, sem repetir nome na mesma
 * página de produto (produto + variações, como a página soma), e sem citar
 * marketplace ou loja concorrente no texto.
 *
 * O original fica no banco (reviews.nome_original e
 * reviews.comentario_original) para conferência no admin.
 */
class AnonimizaAvaliacao
{
    /**
     * Marketplaces e lojas que não aparecem no texto público. A frase que
     * cita um deles sai inteira: tirar só a palavra deixa frase quebrada
     * ("comprei na  e chegou rápido"). "ML" sozinho fica de fora (é
     * mililitro em "garrafa de 500 ml"); marca de produto também (Kicool é
     * marca do que a loja vende).
     */
    public const LOJAS = [
        'shopee', 'mercado\s*livre', 'mercadolivre', 'mercado-livre', '(?:no|do|pelo)\s+ml',
        'amazon', 'aliexpress', 'ali\s*express', 'magalu', 'magazine\s*luiza', 'americanas',
        'casas\s*bahia', 'shein', 'temu', 'kabum', 'tiktok', 'kwai', 'olx', 'carrefour',
        'submarino', 'netshoes', 'leroy\s*merlin', 'madeira\s*madeira',
    ];

    /** @var array<int, array<string, true>> nomes já usados por grupo de produto */
    private array $usados = [];

    /**
     * Nome sorteado que ainda não aparece nas avaliações do grupo do produto.
     */
    public function nomeUnico(int $productId): string
    {
        $grupo = $this->grupo($productId);

        $this->usados[$grupo] ??= Review::query()
            ->whereIn('product_id', $this->produtosDoGrupo($grupo))
            ->whereNotNull('reviewer_name')
            ->pluck('reviewer_name')
            ->flip()
            ->map(fn () => true)
            ->all();

        // 80 nomes x 50 sobrenomes = 4.000 combinações; a maior página tem
        // teto de 738 avaliações, então 500 tentativas sobram.
        for ($tentativa = 0; $tentativa < 500; $tentativa++) {
            $nome = NomeBrasileiro::gerar();

            if (! isset($this->usados[$grupo][$nome])) {
                break;
            }
        }

        $this->usados[$grupo][$nome] = true;

        return $nome;
    }

    /**
     * Texto sem as frases que citam marketplace ou loja. Sem nada sobrando,
     * null (avaliação só com nota e mídia).
     */
    public static function limparTexto(?string $texto): ?string
    {
        if ($texto === null || trim($texto) === '') {
            return null;
        }

        if (! self::citaLoja($texto)) {
            return trim($texto);
        }

        $frases = preg_split('/(?<=[.!?…])\s+|\R+/u', trim($texto)) ?: [];
        $limpo = trim(implode(' ', array_filter($frases, fn ($frase) => ! self::citaLoja($frase))));

        return $limpo !== '' ? $limpo : null;
    }

    public static function citaLoja(string $texto): bool
    {
        return (bool) preg_match('/\b('.implode('|', self::LOJAS).')\b/iu', $texto);
    }

    private function grupo(int $productId): int
    {
        return (int) (Product::query()->whereKey($productId)->value('parent_product_id') ?: $productId);
    }

    /**
     * @return array<int, int>
     */
    private function produtosDoGrupo(int $grupo): array
    {
        return Product::query()
            ->where('id', $grupo)
            ->orWhere('parent_product_id', $grupo)
            ->pluck('id')
            ->all();
    }
}
