<?php

namespace App\Modules\Catalog\Support;

/**
 * Taxas do resumo financeiro do cadastro do produto (pedido 2026-10-18):
 * imposto do Simples e taxa do pagamento, as mesmas da conta da oferta do
 * dia (config/ofertas.php). Sem Lucro Mínimo: o Lira pediu para tirar.
 */
class LucroProduto
{
    /** Imposto padrão, taxa do Pix e do cartão, em %. */
    public static function taxas(): array
    {
        return [
            'imposto' => (float) config('loja.simples.aliquota', 4.5),
            'taxa_pix' => (float) config('ofertas.taxa_pix', 0.99),
            'taxa_cartao' => (float) config('ofertas.taxa_cartao', 4.98),
        ];
    }
}
