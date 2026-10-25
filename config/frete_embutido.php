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

    // Trava do frete (pedido do Lira em 2026-10-24): depois que o visitante
    // calcula o frete de um produto (CEP na página do produto ou no checkout),
    // o valor fica guardado pelo IP e pela hash do aparelho. Voltando ao site
    // dentro deste prazo, o frete daquele produto para aquele CEP não sobe
    // (se cair, ele paga o menor). 0 desliga.
    'trava_dias' => (int) env('FRETE_TRAVA_DIAS', 30),
    'trava_cookie' => 'kz_aparelho',

    // Tabela de entrega da página do produto (pedido do Lira em 2026-10-23):
    // "em até (prazo dos Correios + folga) dias úteis (mas geralmente chega
    // no prazo dos Correios)". Sem prazo dos Correios, vale o padrão.
    'folga_dias' => 2,
    'prazo_padrao_dias' => 7,

    // Checkout dos outros sites (Beauty Kora, pedido do Lira em 2026-10-14):
    // além dos Correios do contrato, as opções destas transportadoras no
    // Melhor Envio, cobrando só o que passar do frete já embutido no preço.
    'transportadoras_melhor_envio' => ['Loggi'],

    // Caixa cotada no Melhor Envio (C x L x A, cm); o peso é o tarifável somado.
    'caixa_cm' => [16, 11, 5],

    // Produto sem peso no cadastro fiscal.
    'peso_padrao_g' => 1000,

    // CEP escolhido pelo cliente (cookie necessário: serve ao cálculo do
    // frete que ele pediu; ver docs/privacidade-e-cookies.md).
    'cookie' => 'kazakora_cep',

    // Produtos com preço fixo na tela (pedido do Lira em 2026-10-25): o card,
    // a página, o carrinho e o checkout mostram o preço do cadastro, sem frete
    // somado, para qualquer CEP. A loja paga o frete da caixa. 262 =
    // parafusadeira leve 18V (riscado 179,34, venda 143,47).
    'produtos_sem_frete' => [262],
];
