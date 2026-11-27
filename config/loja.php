<?php

return [
    /*
    | Desconto no Pix: desde 2026-10-18 é por produto ("Desconto Checkout (%)"
    | no cadastro, products.checkout_discount_percentage), sem % fixo aqui.
    | O preço da loja (products.price) é o valor digitado no cadastro.
    |
    | Desde 2026-10-24 (pedido do Lira) os 5% do Pix viraram 3% + 2%: o cupom
    | automático (abaixo) dá 3% em qualquer forma de pagamento e o "Desconto
    | Checkout" de cada produto passou de 5% para 2%, só no Pix, calculado
    | sobre o valor antes do cupom (3% + 2% = 5% no Pix).
    */

    /*
    | Cupom de boas-vindas (pedido do Lira 2026-10-24): código de um cupom JÁ
    | cadastrado em Admin > Cupons (a migração 2026_10_24_150000 cria o
    | BEMVINDO de 3%). Não entra sozinho: o pop-up do checkout (KazaKora,
    | FerraKora e Beauty Kora) oferece no 1º acesso e o cliente escolhe
    | "Aplicar cupom" ou fecha. Outro cupom digitado troca ele (não somam).
    | Vazio desliga.
    */
    'cupom_automatico' => env('LOJA_CUPOM_AUTOMATICO', 'BEMVINDO'),

    /*
    | Selo "Verificada por Reclame AQUI" (2026-10-11). Verificação aprovada:
    | desde 2026-10-14 o selo OFICIAL (componente SeloReclameAqui.vue) aparece
    | sempre no rodapé da loja, no checkout e no menu do admin, sem depender
    | da chave LOJA_SELO_RA_VERIFICADA (mantida só por compatibilidade).
    | reclame_aqui_url é o link do selo (página RA Verificada da loja).
    */
    /*
    | Checkout no subdomínio seguro (pedido 2026-10-23): lojas cujo botão de
    | compra leva para https://seguro.<loja>/pagamento. Separadas por vírgula
    | ("kazakora.com" em produção, "kazakora.devlira.com.br" no homolog).
    | Só ligar depois que o DNS e o SSL do seguro.* estiverem no ar.
    */
    'checkout_seguro_hosts' => array_filter(array_map('trim', explode(',', (string) env('CHECKOUT_SEGURO_HOSTS', '')))),

    'selo_ra_verificada' => (bool) env('LOJA_SELO_RA_VERIFICADA', false),
    'reclame_aqui_url' => env('LOJA_RECLAME_AQUI_URL', 'https://www.reclameaqui.com.br/rav/F68R'),

    /*
    | Dados da empresa nos e-mails (rodapé) — os mesmos de
    | resources/js/Shared/company.js.
    */
    'empresa' => [
        'nome' => 'Kazakora | Grupo AlphaKora',
        'cnpj' => '65.604.590/0001-07',
        'whatsapp_exibicao' => '(11) 96572-3990',
        'whatsapp_link' => 'https://wa.me/5511965723990',
        'endereco' => 'Rua Mogi Mirim, 20, Mooca, São Paulo/SP',
        // Completo, como no rodapé do checkout (cabeçalho do boleto, 2026-10-24).
        'endereco_completo' => 'Rua Mogi Mirim, 20, Vila Bertioga, Mooca, São Paulo/SP, CEP 03187-040',
    ],

    /*
    | Para onde vão as mensagens do formulário "Fale conosco" (pedido
    | 2026-10-10) — o e-mail do dono da loja.
    */
    'email_contato' => env('LOJA_EMAIL_CONTATO', 'korashopecom@gmail.com'),

    /*
    | E-mail do pagador que vai para o Mercado Pago no Pix (pedido
    | 2026-10-11): um endereço NOSSO por pedido, para o Mercado Pago não
    | mandar e-mail de cobrança ao cliente — quem avisa é a KazaKora
    | (PixAguardandoPagamento). {pedido} vira o número do pedido. Cartão
    | continua com o e-mail real (entra na análise antifraude).
    */
    'email_pagador_pix' => env('MERCADOPAGO_EMAIL_PAGADOR_PIX', 'pedido-{pedido}@kazakora.com'),

    /*
    | Boleto pelo Mercado Pago (pedido 2026-10-14). Vence em N dias corridos
    | (às 23:59 de São Paulo); sem desconto do Pix e sem dividir com cartão.
    | O mesmo e-mail nosso do Pix vai como pagador, para o Mercado Pago não
    | escrever ao cliente (quem manda o boleto é a KazaKora).
    | boleto_cancelar_apos_dias: se o Mercado Pago ainda disser "pendente"
    | esse tanto de dias DEPOIS do vencimento (compensação leva até 3 dias
    | úteis), a loja cancela o boleto e o pedido e devolve o estoque.
    */
    /*
    | Pop-up de cupom no checkout (pedido 2026-10-23, modelo Yampi). Código de
    | um cupom JÁ cadastrado em Admin > Cupons; vazio usa o cupom_automatico
    | (BEMVINDO, 2026-10-24). Inexistente, inativo ou que não vale para o
    | carrinho: o pop-up não aparece. Aparece toda vez que o checkout abre sem
    | cupom (desde 2026-10-25; antes era uma vez por navegador).
    */
    'cupom_primeira_compra' => env('LOJA_CUPOM_PRIMEIRA_COMPRA'),

    /*
    | Faixa do cupom no checkout da KazaKora (pedido 2026-11-06): no lugar do
    | pop-up acima, uma faixa com o código e o botão "Aplicar" (só no clique), sem
    | aplicar sozinho. Código de um cupom JÁ cadastrado (a migração
    | 2026_11_06_100000 cria o BLACKFRIDAY de 5%, só KazaKora). Inexistente,
    | inativo ou que não vale para o carrinho: a faixa não aparece. Vazio desliga.
    */
    'cupom_faixa' => env('LOJA_CUPOM_FAIXA', 'BLACKFRIDAY'),

    /*
    | Black Friday automática (pedido do Lira 2026-11-24, BlackFriday.php): até
    | esta data e hora (horário de Brasília) o cupom da faixa acima entra sozinho
    | no carrinho e no checkout, e a loja mostra "De / Por com cupom / No Pix"
    | em todo produto e a faixa do topo da Black Friday. Passou, desliga tudo
    | sozinho (aplicação, faixas e riscados). Vazio desliga a automática.
    */
    'blackfriday_ate' => env('LOJA_BLACKFRIDAY_ATE', '2026-11-30 23:59:59'),

    /*
    | Produtos em que o preço grande da Black Friday é o de venda, não o com cupom
    | (pedido do Lira 26/11/2026, Compressor 4 em 1): "de R$ 435,52" riscado e
    | "por R$ 297,00" grande; o com cupom e o Pix ficam nas linhas de baixo.
    */
    'blackfriday_preco_principal_produtos' => [413],

    'boleto_dias_vencimento' => (int) env('LOJA_BOLETO_DIAS_VENCIMENTO', 3),
    'boleto_cancelar_apos_dias' => (int) env('LOJA_BOLETO_CANCELAR_APOS_DIAS', 7),

    /*
    | Relatórios internos por e-mail (resultado do dia, pedidos cancelados
    | depois de separados). Vários endereços separados por vírgula.
    */
    'email_relatorios' => env('LOJA_EMAIL_RELATORIOS', env('LOJA_EMAIL_CONTATO', 'korashopecom@gmail.com')),

    /*
    | Vigia de pedido pago sem NF-e e sem etiqueta (loja:nota-etiqueta-pendentes,
    | de hora em hora): destinatário próprio (Lira 2026-10-19, unixlira@gmail.com).
    | Vazio, usa o dos relatórios.
    */
    'email_nota_etiqueta' => env('LOJA_EMAIL_NOTA_ETIQUETA') ?: env('LOJA_EMAIL_RELATORIOS', env('LOJA_EMAIL_CONTATO', 'korashopecom@gmail.com')),

    /*
    | Simples Nacional (pedido 2026-10-10): alíquota efetiva informada pelo
    | contador, em %, e a data em que a empresa entrou no Simples (antes era
    | MEI). Usada para estimar o DAS do mês no e-mail de resultado.
    */
    'simples' => [
        'aliquota' => (float) env('LOJA_SIMPLES_ALIQUOTA', 4.5),
        'desde' => env('LOJA_SIMPLES_DESDE', '2026-09-01'),
    ],

    /*
    | Regras da planilha da Zaio (agência que gere Shopee, Mercado Livre e
    | TikTok Shop; pedido do Lira em 27/11/2026: "usar tudo da Zaio"). Aba
    | Base da planilha: comissão Zaio de 2% da venda nesses três canais, que
    | entra como custo e é o valor a pagar à agência. O imposto é o Simples
    | (4,5%) em todos os canais (Lira, 27/11/2026: "nosso imposto é 4,5%").
    | Custo do produto continua o do cadastro; tarifas, ADS e devoluções
    | continuam as reais (APIs e demonstrativo do TikTok). Amazon e sites
    | próprios ficam fora. Só pedidos a partir de 'desde'.
    */
    'zaio' => [
        'canais' => ['shopee', 'mercado_livre', 'tiktok_shop'],
        'comissao' => (float) env('LOJA_ZAIO_COMISSAO', 2.0),
        'desde' => env('LOJA_ZAIO_DESDE', '2026-11-01'),
    ],

    /*
    | Insumos de embalagem (caixa/envelope, fita, etiqueta) por pedido, em R$
    | (pedido do Lira em 12/11/2026, regra oficial do dono: R$ 2,00). Sai da
    | margem de toda venda efetiva que a loja embala (ContributionMargin,
    | linha "insumos"); o Full do ML fica de fora, quem embala é o ML. É o
    | mesmo valor do teto_insumos da conta de preço (config/frete_embutido).
    | 0 desliga.
    */
    'insumos_por_pedido' => (float) env('LOJA_INSUMOS_POR_PEDIDO', 2.0),

    /*
    | Selo do cliente no Meu Perfil (pedido 2026-10-14): todo cliente começa
    | como "Super Cliente"; com MAIS de `pedidos` pedidos finalizados
    | (entregues) vira "5 ★". Só contam pedidos feitos a partir de `desde`
    | (os anteriores são desconsiderados).
    */
    'selo_cliente' => [
        'pedidos' => (int) env('LOJA_SELO_CLIENTE_PEDIDOS', 10),
        'desde' => env('LOJA_SELO_CLIENTE_DESDE', '2026-10-15'),
    ],

    /*
    | Alertas do Fluxo de Caixa (2026-10-19, AlertasFinanceiros): ADS do
    | canal acima de X% do faturamento do canal (meta do Lira 3 a 4%) e
    | receita Y% abaixo do período de comparação.
    */
    'ads_alerta_percentual' => (float) env('LOJA_ADS_ALERTA_PERCENTUAL', 6),
    'ads_meta_percentual' => (float) env('LOJA_ADS_META_PERCENTUAL', 4),
    'queda_vendas_percentual' => (float) env('LOJA_QUEDA_VENDAS_PERCENTUAL', 30),

    /*
    | Carrinho abandonado (lead_checkout, 2026-10-24): prazo de guarda decidido
    | pelo Lira, 3 meses desde a última interação (updated_at). Depois disso o
    | lead sai do banco, aberto ou convertido (leads:limpar, todo dia).
    */
    'lead_checkout_dias_guarda' => (int) env('LOJA_LEAD_CHECKOUT_DIAS_GUARDA', 90),

    /*
    | Sequência de carrinho abandonado (pedido do Lira 21/11/2026, comando
    | loja:carrinho-abandonado-sequencia): no máximo tantos carrinhos por
    | rodada de 10 minutos, para não estourar a cota do WhatsApp/e-mail.
    */
    'carrinho_sequencia_limite' => (int) env('CARRINHO_SEQUENCIA_LIMITE', 50),
    // Envio ligado só no kazakora.com (mesmo padrão do alerta_coleta em
    // config/services.php): o homolog também roda com APP_ENV=production e o
    // mesmo WhatsApp, e não pode mandar mensagem a cliente. Desligado, o
    // comando importa e para normalmente, mas não manda nada.
    'carrinho_sequencia_ativa' => (bool) env('CARRINHO_SEQUENCIA_ATIVA', in_array(parse_url((string) env('APP_URL'), PHP_URL_HOST), ['kazakora.com', 'www.kazakora.com'], true)),

    /*
    | Pedido de avaliação depois da entrega (pedido do Lira 23/11/2026, comando
    | loja:pedir-avaliacao, de hora em hora das 9h às 20h): pedido do site
    | (KazaKora e FerraKora) entregue há `dias` ou mais (e no máximo há
    | `max_dias`) recebe por e-mail, e por WhatsApp se houver modelo aprovado,
    | o link assinado para avaliar cada produto. O link vale `validade_dias`.
    | Ligado só no kazakora.com, como a sequência do carrinho.
    */
    'pedido_avaliacao' => [
        'ativo' => (bool) env('PEDIDO_AVALIACAO_ATIVO', in_array(parse_url((string) env('APP_URL'), PHP_URL_HOST), ['kazakora.com', 'www.kazakora.com'], true)),
        'dias' => (int) env('PEDIDO_AVALIACAO_DIAS', 5),
        'max_dias' => (int) env('PEDIDO_AVALIACAO_MAX_DIAS', 60),
        'validade_dias' => 30,
        'limite' => (int) env('PEDIDO_AVALIACAO_LIMITE', 50),
    ],

    /*
    | Promoções na sequência de carrinho (pedido do Lira 22/11/2026). Exceção à
    | regra "sem desconto" da sequência: enquanto vale, o texto abaixo entra no
    | lugar da novidade do Gemini nos disparos 2 a 6 (D+3, D+5, D+7...) do
    | carrinho cujo produto (ou produto pai) tem o slug com `slug_contem`; o
    | calendário de dias não muda. Só afirmar o que o checkout dá de verdade:
    | o Pix do Livro Falante foi para 5% (2% + 3%) na migração 2026_11_22_130000.
    */
    'carrinho_promocoes' => [
        [
            'slug_contem' => 'livro-falante-infantil-bilingue',
            'ate' => '2026-11-30 23:59:59',
            'texto' => 'É Black Friday KazaKora: até 30/11 a Lousa Mágica LED vai de graça junto com o seu Livro Falante. '
                .'E para fechar agora, pagando no Pix você ganha mais 3% de desconto. '
                .'A promoção vale até 30/11 ou enquanto durar o estoque, aproveite. Seu carrinho continua salvo, é só finalizar pelo link.',
        ],
    ],
];
