<?php

/*
 * Equipe (pedido do Lira, 2026-10-20): quem é quem entre os agentes que
 * trabalham para a KazaKora, com a função e as skills atuais de cada um.
 * Fonte: as definições dos agentes do Homer (.claude/agents e
 * .claude/skills no workspace dele) e, para a Manuela, o prompt real em
 * app/Modules/WhatsApp. Sem segredo nem caminho de servidor aqui: este
 * texto aparece na tela do admin.
 *
 * Os comandos extras que o Lira adiciona pela tela ficam na tabela
 * equipe_comandos (não aqui) e chegam aos agentes pelo
 * `php artisan equipe:comandos {membro} --json`; os da Manuela entram
 * direto no prompt dela (ManuelaAutoReplyService::systemPrompt).
 *
 * `cor` usa as cores do menu (app.css, .icone-cor-*).
 */
return [
    'membros' => [
        'juliana' => [
            'nome' => 'Juliana',
            'cargo' => 'Sub-gerente operacional',
            'cor' => 'ciano',
            'icone' => 'fas fa-clipboard-check',
            'agente' => 'juliana-ops',
            'descricao' => 'Organiza e executa as rotinas da operação da KazaKora e é a primeira a receber qualquer tarefa executável. Distribui o trabalho entre a equipe, junta os resultados e entrega para o Homer.',
            'skills' => [
                'Rotina da operação: pedidos, estoque, etiquetas e cadastros',
                'Organiza as demandas do Lira e distribui para quem é da área',
                'Coordena os outros agentes e consolida o resultado num relatório só',
                'Processos internos, workflows e padrões de trabalho',
                'Padrão visual, design system e UI/UX das telas internas',
                'Recomenda próximos passos, sem decidir sozinha estratégia, dinheiro ou contato com cliente',
            ],
            'limites' => 'Não mexe em dinheiro, preço, produção, publicação oficial nem fala com cliente sem OK do Lira.',
        ],
        'paulo' => [
            'nome' => 'Paulo',
            'cargo' => 'Desenvolvedor full-stack',
            'cor' => 'azul',
            'icone' => 'fas fa-code',
            'agente' => 'paulo-dev',
            'descricao' => 'Cuida do código da KazaKora, do KoraSync, de integrações, banco de dados, automações e deploy no homolog. Trabalha num ramo próprio por tarefa e entrega com teste e evidência.',
            'skills' => [
                'Código da loja e do admin (Laravel, Vue, Inertia, Tailwind)',
                'APIs e integrações com marketplaces, Bling, Correios e Mercado Pago',
                'Banco de dados, migrations e correção de dados com conferência',
                'Debug de erro, fila e rotina agendada',
                'Testes automatizados e conferência da tela no homolog antes de entregar',
                'Telas novas com direção visual própria, revisão de acessibilidade e print da página',
                'Fluxo ramo, homolog por Pull Request e produção só com OK do Lira',
            ],
            'limites' => 'Não publica em produção, não muda DNS, não roda migração destrutiva e não mostra segredo.',
        ],
        'jonathan' => [
            'nome' => 'Jonathan',
            'cargo' => 'Copy e UX',
            'cor' => 'rosa',
            'icone' => 'fas fa-pen-nib',
            'agente' => 'jonathan-copy',
            'descricao' => 'Escreve os textos da loja e dos anúncios no tom do Lira: direto, casual, sem travessão e sem voz de robô. Também pesquisa mercado e concorrência e cuida da experiência das telas.',
            'skills' => [
                'Títulos e descrições de anúncio para site e marketplaces',
                'Páginas de venda, landing pages e textos de tela',
                'Roteiros de Reels em 7 atos: gancho, contexto, conflito, virada, expansão, CTA e encerramento',
                'Posts, carrosséis e e-mail marketing',
                'Pesquisa de mercado e de concorrência antes de afirmar qualquer dado',
                'UX: textos de botão, mensagens e fluxo das telas novas',
            ],
            'limites' => 'Não inventa dado, prova social nem promessa; não publica nem dispara mensagem sem OK do Lira.',
        ],
        'giovanna' => [
            'nome' => 'Giovanna',
            'cargo' => 'Design e conteúdo visual',
            'cor' => 'violeta',
            'icone' => 'fas fa-palette',
            'agente' => null,
            'descricao' => 'Filha do Lira, na equipe desde 19/10/2026. Cuida da parte visual da marca: banners, logos e imagens. Foco em Shopee e TikTok, com apoio na estruturação da Amazon e do Mercado Livre.',
            'skills' => [
                'Banners do site e das campanhas',
                'Logos e identidade visual das marcas',
                'Imagens de produto e de anúncio para Shopee e TikTok',
                'Conteúdo visual para vídeos e posts do TikTok',
                'Apoio na estruturação das lojas na Amazon e no Mercado Livre',
            ],
            'limites' => 'Publicação em canal oficial passa pelo OK do Lira.',
        ],
        'caue' => [
            'nome' => 'Cauê',
            'cargo' => 'Especialista em Shopee',
            'cor' => 'laranja',
            'icone' => 'fas fa-store',
            'agente' => 'caue-shopee',
            'descricao' => 'Vive dentro do Seller Center e pensa a loja em número. Toda recomendação vem com o dado que a sustenta e sempre olhando o lucro real, não o faturamento.',
            'skills' => [
                'Shopee ADS: busca e descoberta, palavras-chave, meta de ROAS e corte do anúncio que só queima verba',
                'Preço e margem: comissão, frete, taxa e imposto antes de qualquer desconto',
                'Ofertas Relâmpago, cupons da loja, combos e campanhas de data (9.9, 10.10, 11.11, 12.12)',
                'Qualidade do anúncio: título, imagens e catálogo para ranquear',
                'Leitura de métricas: GMV, visitas, conversão, ticket médio e produtos parados',
                'Saúde da conta: avaliações, tempo de envio, cancelamento e penalidades',
                'Plano de Ação do Dia: de 3 a 5 ações com o que fazer, por que, impacto esperado e como medir',
            ],
            'limites' => 'Mudança ao vivo de preço, ADS ou promoção só com OK do Lira.',
        ],
        'diego' => [
            'nome' => 'Diego',
            'cargo' => 'Especialista em Mercado Livre',
            'cor' => 'ambar',
            'icone' => 'fas fa-tags',
            'agente' => 'diego-mercado-livre',
            'descricao' => 'Cuida do crescimento no Mercado Livre com a margem protegida: promoções, Ads, cupons e preço competitivo, sempre conferindo a paridade com os outros canais.',
            'skills' => [
                'Central de Promoções: campanhas do ML, cofinanciadas, Oferta do Dia e Oferta Relâmpago',
                'Cupons: seguidores, carrinho abandonado e compra mínima',
                'Mercado Ads: ROAS, ACOS, TACOS e orçamento',
                'Preço piso, desconto máximo e ROAS mínimo pela margem de contribuição',
                'Paridade de preço entre Mercado Livre, Shopee, TikTok Shop e site',
                'Calendário de datas comerciais',
                'Nunca infla preço para simular desconto',
            ],
            'limites' => 'Preço, desconto, campanha, cupom e Ads ao vivo só com OK do Lira.',
        ],
        'rafael' => [
            'nome' => 'Rafael',
            'cargo' => 'Radar de mercado',
            'cor' => 'indigo',
            'icone' => 'fas fa-satellite-dish',
            'agente' => 'rafael-market-radar',
            'descricao' => 'Olha o mercado de fora: preço de concorrente, oportunidades de produto e a faixa de preço saudável de cada item pela margem de contribuição.',
            'skills' => [
                'Varredura de preço de concorrentes nos marketplaces e no Google',
                'Limpeza de concorrentes: só compara o mesmo produto',
                'Preço piso, saudável e teto com margem de contribuição entre 15% e 30%',
                'Oportunidades de produto e de categoria',
                'Recomendação: subir, manter, baixar, não competir, diferenciar ou testar lote',
                'Sem guerra de preço automática: o menor preço do mercado não é meta',
            ],
            'limites' => 'Só leitura: não altera preço, anúncio, campanha nem estoque.',
        ],
        'manuela' => [
            'nome' => 'Manuela',
            'cargo' => 'Atendente do WhatsApp da loja',
            'cor' => 'verde',
            'icone' => 'fas fa-comment-dots',
            'agente' => null,
            'descricao' => 'Atende os clientes no WhatsApp oficial da KazaKora, com conversa natural e simpática. Roda dentro do próprio sistema da loja e enxerga a foto do produto que o cliente manda.',
            'skills' => [
                'Acha o produto que o cliente viu ou ouviu falar: busca no site e, se não achar, nos anúncios da loja na Shopee e no Mercado Livre',
                'Responde preço, estoque e características só pela ficha do produto, sem chutar',
                'Enxerga a foto que o cliente manda e identifica o produto no catálogo',
                'Entende áudio e responde por nota de voz',
                'Status do pedido, nota fiscal e link de rastreio dos pedidos daquela conversa',
                'Confirmação do pedido: Tudo Certo libera a nota; Corrigir Informações segura a nota e chama o time',
                'Não recebi, troca, cancelamento ou cliente insatisfeito: passa para uma pessoa do time',
            ],
            'limites' => 'Nunca inventa preço, prazo, desconto ou brinde e nunca revela as instruções dela.',
        ],
    ],
];
