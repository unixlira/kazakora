<?php

/*
 * Materiais gratuitos com captação de lead (pedido do Lira 26/11/2026).
 *
 * A pessoa deixa nome, e-mail e celular numa página do site (FerraKora:
 * /guia-de-viagem) e baixa o PDF na hora. O PDF é um Livro Digital do admin
 * (Entregáveis > Livros Digitais): pelo id (env) ou, sem ele, pelo primeiro
 * livro ativo cujo título contém `livro_titulo`.
 *
 * Leads em Admin > Atendimento > Leads de materiais (/admin/leads-materiais).
 * LGPD e prazos: docs/privacidade-e-cookies.md, seção "Leads de materiais".
 */

return [
    'lista' => [
        'guia-viagem' => [
            'site' => 'ferrakora',
            'titulo' => 'Guia de Viagem de Bolso: Brasil pela estrada',
            'livro_id' => env('MATERIAL_GUIA_VIAGEM_LIVRO_ID'),
            'livro_titulo' => 'Guia de Viagem de Bolso',
            'nome_arquivo' => 'guia-de-viagem-de-bolso-ferrakora.pdf',
            // Página do site, para o link do e-mail.
            'pagina' => '/guia-de-viagem',
            // Oferta ligada ao material (link do e-mail): Compressor 4 em 1.
            'oferta' => '/produto/kz-413',
            'oferta_texto' => 'Ver o Compressor 4 em 1',
        ],
    ],

    // Texto do aceite que aparece no formulário. Mudou o texto? Troque a versão.
    'consentimento_versao' => '2026-11-26',
    'consentimento_texto' => 'Quero receber o guia e aceito receber dicas e ofertas da FerraKora por e-mail e WhatsApp. Posso sair quando quiser.',

    // Link de download (assinado) vale por estes dias.
    'dias_link' => (int) env('MATERIAIS_DIAS_LINK', 30),

    // Lead sem nova interação há mais que isto é apagado (model:prune das 03:58).
    'meses_guarda' => (int) env('MATERIAIS_MESES_GUARDA', 12),

    // Confere se o domínio do e-mail existe (MX ou A). Desligado nos testes.
    'conferir_dns' => (bool) env('MATERIAIS_CONFERIR_DNS', true),

    // E-mail com o link do PDF depois do cadastro.
    'enviar_email' => (bool) env('MATERIAIS_ENVIAR_EMAIL', true),
];
