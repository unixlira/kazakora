<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Fiscal\Models\ProductFiscalData;

/**
 * Ficha técnica que o cliente vê na página do produto (pedido 2026-10-24):
 * marca, modelo, cor, voltagem, dimensões e peso. SKU, categoria e variação
 * (rótulos internos) NÃO entram. Serve a loja KazaKora e a API dos sites
 * FerraKora e Beauty Kora, para os três mostrarem a mesma ficha.
 *
 * Dimensões e peso vêm do cadastro fiscal (medidas da embalagem, usadas no
 * frete): na variação sem medida própria vale a do produto pai. Voltagem não
 * tem coluna: sai de "110V", "127V", "220V" ou "bivolt" escrito na variação,
 * cor ou modelo; produto sem isso não mostra a linha.
 */
class FichaTecnica
{
    /** @return array<int, array{0: string, 1: string}> pares [rótulo, valor] */
    public static function linhas(Product $produto): array
    {
        $linhas = [];

        foreach ([['Marca', $produto->brand], ['Modelo', $produto->model], ['Cor', $produto->color]] as [$rotulo, $valor]) {
            if (filled($valor)) {
                $linhas[] = [$rotulo, trim((string) $valor)];
            }
        }

        if ($voltagem = self::voltagem($produto)) {
            $linhas[] = ['Voltagem', $voltagem];
        }

        $fiscal = self::medidas($produto);

        $comprimento = (float) $fiscal?->profundidade_cm;
        $largura = (float) $fiscal?->largura_cm;
        $altura = (float) $fiscal?->altura_cm;

        if ($comprimento > 0 && $largura > 0 && $altura > 0) {
            $linhas[] = [
                'Dimensões da embalagem (C x L x A)',
                self::numero($comprimento).' x '.self::numero($largura).' x '.self::numero($altura).' cm',
            ];
        }

        $peso = (float) $fiscal?->peso_bruto;

        if ($peso > 0) {
            $linhas[] = ['Peso com embalagem', $peso < 1 ? self::numero($peso * 1000).' g' : self::numero($peso).' kg'];
        }

        return $linhas;
    }

    /**
     * Medidas da embalagem para o fim da descrição (pedido 2026-10-25, no
     * lugar da tabela Informação adicional): "28C x 8L x 23A CM".
     */
    public static function dimensoesEmbalagem(Product $produto): ?string
    {
        $fiscal = self::medidas($produto);

        $comprimento = (float) $fiscal?->profundidade_cm;
        $largura = (float) $fiscal?->largura_cm;
        $altura = (float) $fiscal?->altura_cm;

        if ($comprimento <= 0 || $largura <= 0 || $altura <= 0) {
            return null;
        }

        return self::numero($comprimento).'C x '.self::numero($largura).'L x '.self::numero($altura).'A CM';
    }

    public static function voltagem(Product $produto): ?string
    {
        foreach ([$produto->variation, $produto->color, $produto->model] as $texto) {
            if (! filled($texto)) {
                continue;
            }

            if (preg_match('/\bbi-?volt\b/i', (string) $texto)) {
                return 'Bivolt';
            }

            if (preg_match('/(?<![\d,.])(110|127|220)\s*v(?:olts?)?\b/i', (string) $texto, $achado)) {
                return $achado[1].'V';
            }
        }

        return null;
    }

    private static function medidas(Product $produto): ?ProductFiscalData
    {
        $proprio = $produto->fiscalData;

        if ($proprio && ($proprio->peso_bruto > 0 || $proprio->altura_cm > 0)) {
            return $proprio;
        }

        return $produto->parent_product_id ? $produto->parent?->fiscalData ?? $proprio : $proprio;
    }

    private static function numero(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, ',', ''), '0'), ',');
    }
}
