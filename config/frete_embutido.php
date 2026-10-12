<?php

/*
| Frete embutido no preço (pedido do Lira em 2026-10-12): a loja dá frete
| grátis para todo o Brasil e o preço que o cliente vê já tem o frete mais
| barato dos Correios (contrato) para a região dele somado.
|
| Região do cliente, nesta ordem: CEP que ele digitou (endereço do checkout ou
| campo de CEP do produto) → estado pelo IP (cabeçalhos de localização da
| Cloudflare) → sem região: soma o valor de segurança abaixo.
|
| TURBO: fora da entrega FULL, quando o SEDEX chega antes do PAC, o checkout
| oferece a diferença como opção paga.
*/

return [
    'ativo' => env('FRETE_EMBUTIDO_ATIVO', true),

    // Sem região ou com a API dos Correios fora do ar.
    'fallback_pac' => 30.0,
    'fallback_sedex' => 60.0,

    // Faixas de peso tarifável (gramas) cotadas por destino. Cada produto usa
    // a primeira faixa que comporta o peso dele.
    'faixas_g' => [300, 500, 1000, 2000, 3000, 5000, 7000, 10000, 15000, 20000, 30000],

    // TURBO acima disto não aparece no checkout (pedido do Lira em 2026-10-12).
    'turbo_max' => 60.0,

    // Produto sem peso no cadastro fiscal.
    'peso_padrao_g' => 1000,

    // CEP escolhido pelo cliente (cookie necessário: serve ao cálculo do
    // frete que ele pediu; ver docs/privacidade-e-cookies.md).
    'cookie' => 'kazakora_cep',
];
