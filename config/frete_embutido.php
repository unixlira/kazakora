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

    // Frete por cidade/região (pedido do Lira em 2026-10-24): regras nas
    // tabelas frete_regioes/frete_regiao_locais mexem na cotação dos Correios
    // do CEP (valor fixo, acréscimo, desconto ou teto). Sem regra cadastrada
    // nada muda; false desliga todas de uma vez.
    'regioes_ativo' => env('FRETE_REGIOES_ATIVO', true),

    // TURBO acima disto não aparece no checkout (pedido do Lira em 2026-10-12).
    'turbo_max' => 60.0,

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
];
