/**
 * Menu do admin (reformulação aprovada pelo Lira em 2026-10-11, Entrega 2
 * de Documents/2026-10-11-admin-cores-e-menu.md): 10 grupos por tarefa do
 * dia, os mesmos 49 itens, com o mesmo href, permission e badgeKey de antes.
 * Nenhuma rota nova e nenhuma permissão mudou.
 *
 * Todo ícone tem cor (Lira, 2026-10-14), ativo ou não: `marca` pinta com a
 * cor principal da marca (app.css, .icone-marca) e `cor` com a cor do
 * assunto (app.css, .icone-cor: verde dinheiro, laranja pedido e envio,
 * vermelho devolução e alerta, violeta marketing, azul cadastro e painel...).
 * Cada item tem ícone próprio, sem repetir, para dar para achar pelo ícone
 * com o menu recolhido.
 *
 * `direto: true` = seção sem cabeçalho de dropdown: os itens ficam sempre
 * à vista, como links normais.
 * `permission: null` = qualquer pessoa da equipe vê.
 * `badgeKey` = contador de pendências (page.props.sidebarBadges).
 */
export const sidebarSections = [
    {
        // Link direto, sem dropdown (Lira, 2026-10-14): o Gerenciador volta a
        // ser item normal do menu, como era antes do menu em grupos.
        heading: 'Gerenciador',
        direto: true,
        items: [
            { label: 'Gerenciador', href: '/admin', icon: 'fas fa-gauge-high', cor: 'azul', permission: null },
            { label: 'Indicadores', href: '/admin/indicadores', icon: 'fas fa-square-poll-vertical', cor: 'indigo', permission: 'relatorios.view' },
        ],
    },
    {
        heading: 'Vender',
        items: [
            { label: 'Produtos', href: '/admin/produtos', icon: 'fas fa-boxes-stacked', cor: 'laranja', permission: 'cadastros.view' },
            { label: 'Categorias', href: '/admin/categorias', icon: 'fas fa-folder-tree', cor: 'ambar', permission: 'cadastros.view' },
            { label: 'Calculadora de preço', href: '/admin/precificacao', icon: 'fas fa-calculator', cor: 'verde', permission: 'cadastros.view' },
            { label: 'Concorrentes', href: '/admin/concorrencia', icon: 'fas fa-magnifying-glass-chart', cor: 'violeta', permission: 'cadastros.view' },
            { label: 'Fotos de anúncio', href: '/admin/marketplaces/fotos-anuncio', icon: 'fas fa-camera', cor: 'rosa', permission: 'relatorios.view' },
            { label: 'Avaliações', href: '/admin/avaliacoes', icon: 'fas fa-star', cor: 'ambar', permission: 'cadastros.view' },
        ],
    },
    {
        // Oportunidades (pedido do Lira 2026-10-12): Google e TikTok (antes
        // submenu do Radar) e os mais vendidos de TERCEIROS em cada
        // marketplace (correção do Lira 2026-10-12: não são as vendas da
        // loja). Sem item "Radar" (Lira, 2026-10-12): a tabela do Radar do
        // ML fica só embaixo dos mais vendidos em Oportunidades › Mercado Livre.
        heading: 'Oportunidades',
        items: [
            { label: 'Google', href: '/admin/radar/google', icon: 'fab fa-google', marca: 'google', permission: 'relatorios.view' },
            { label: 'TikTok', href: '/admin/radar/tiktok', icon: 'fab fa-tiktok', marca: 'tiktok', permission: 'relatorios.view' },
            { label: 'Mercado Livre', href: '/admin/oportunidades/mercado-livre', icon: 'fas fa-chart-simple', marca: 'mercado-livre', permission: 'relatorios.view' },
            { label: 'Shopee', href: '/admin/oportunidades/shopee', icon: 'fas fa-arrow-trend-up', marca: 'shopee', permission: 'relatorios.view' },
        ],
    },
    {
        // Estatísticas (pedido do Lira 2026-10-12): curva ABC com lucro de
        // cada marketplace, que saiu de Oportunidades. Mostra lucro, por
        // isso financeiro.view.
        heading: 'Estatísticas',
        items: [
            { label: 'Curva ABC Mercado Livre', href: '/admin/estatisticas/curva-abc/mercado-livre', icon: 'fas fa-chart-column', marca: 'mercado-livre', permission: 'financeiro.view' },
            { label: 'Curva ABC Shopee', href: '/admin/estatisticas/curva-abc/shopee', icon: 'fas fa-chart-bar', marca: 'shopee', permission: 'financeiro.view' },
        ],
    },
    {
        // Pedidos x Logística (pedido do Lira 2026-10-12): Pedidos é o que
        // diz respeito ao pedido em si (ver, importar, devolução/reclamação);
        // Logística é o que move a caixa (etiqueta, pré-postagem, fila de
        // impressão, Flex). Mesmos hrefs e permissões de antes.
        heading: 'Pedidos',
        items: [
            { label: 'Pedidos', href: '/admin/pedidos', icon: 'fas fa-receipt', cor: 'laranja', permission: 'pedidos.view' },
            // Pedidos do site Beauty Kora (2026-10-12), fora dos pedidos da loja.
            { label: 'Pedidos Beauty Kora', href: '/admin/pedidos-beautykora', icon: 'fas fa-spa', marca: 'beautykora', permission: 'pedidos.view' },
            // Vídeos da embalagem gravados no KoraSync (2026-10-13).
            { label: 'Vídeos da embalagem', href: '/admin/videos-embalagem', icon: 'fas fa-video', cor: 'vermelho', permission: 'pedidos.view' },
            { label: 'Importar pedido', href: '/admin/importar-pedido', icon: 'fas fa-file-import', cor: 'azul', permission: 'configuracoes.integracoes' },
            // Devoluções e reclamações de todas as plataformas (2026-10-06).
            { label: 'Devoluções', href: '/admin/devolucoes', icon: 'fas fa-rotate-left', cor: 'vermelho', permission: 'operacional.view', badgeKey: 'devolucoes' },
        ],
    },
    {
        heading: 'Logística',
        items: [
            { label: 'Pré-postagem Correios', href: '/admin/correios', icon: 'fas fa-qrcode', marca: 'correios', permission: 'operacional.view' },
            { label: 'Etiqueta avulsa', href: '/admin/etiquetas-manuais/nova', icon: 'fas fa-tag', cor: 'laranja', permission: 'configuracoes.integracoes' },
            { label: 'Fila de impressão', href: '/admin/impressoes', icon: 'fas fa-print', cor: 'cinza-azul', permission: 'configuracoes.integracoes' },
            // Envios Flex (2026-09-11): o número é quantos envios têm
            // pendência aberta (devolução, venda cancelada com o produto fora,
            // entregador que não iniciou a rota).
            { label: 'Envios Flex', href: '/admin/envios-flex', icon: 'fas fa-motorcycle', marca: 'mercado-livre', permission: 'operacional.view', badgeKey: 'flexAlertas' },
            // PAC e SEDEX para as 27 capitais, preço do contrato (2026-10-12).
            { label: 'Fretes por estado', href: '/admin/logistica/fretes-por-estado', icon: 'fas fa-map-location-dot', cor: 'verde', permission: 'operacional.view' },
        ],
    },
    {
        heading: 'Estoque e Compras',
        items: [
            { label: 'Estoque', href: '/admin/estoque', icon: 'fas fa-warehouse', cor: 'terra', permission: 'operacional.view' },
            { label: 'Compras de fornecedor', href: '/admin/pedidos-de-compra', icon: 'fas fa-cart-arrow-down', cor: 'verde', permission: 'operacional.view' },
            // Calculadora de importação (2026-10-12): lugar provisório, o Lira
            // ainda vai decidir onde fica no menu.
            { label: 'Calculadora de importação', href: '/admin/calculadora-importacao', icon: 'fas fa-ship', cor: 'ciano', permission: 'cadastros.view' },
            { label: 'Fornecedores', href: '/admin/fornecedores', icon: 'fas fa-truck-field', cor: 'azul', permission: 'cadastros.view' },
            { label: 'Ordens de serviço', href: '/admin/ordens-de-servico', icon: 'fas fa-screwdriver-wrench', cor: 'laranja', permission: 'operacional.view' },
        ],
    },
    {
        heading: 'Financeiro',
        items: [
            { label: 'Resultado e margem', href: '/admin/dashboard-financeiro', icon: 'fas fa-chart-line', cor: 'verde', permission: 'financeiro.view' },
            { label: 'Fluxo de caixa', href: '/admin/fluxo-de-caixa', icon: 'fas fa-money-bill-transfer', cor: 'verde', permission: 'financeiro.view' },
            { label: 'Notas fiscais', href: '/admin/notas-fiscais', icon: 'fas fa-file-invoice', cor: 'azul', permission: 'pedidos.view', badgeKey: 'fiscalDuplicidades' },
            { label: 'Fechamento fiscal', href: '/admin/notas-fiscais/fechamento', icon: 'fas fa-calendar-check', cor: 'ciano', permission: 'pedidos.view' },
            { label: 'Custo do Flex', href: '/admin/integracoes/mercado-livre/flex', icon: 'fas fa-file-invoice-dollar', marca: 'mercado-livre', permission: 'configuracoes.integracoes' },
            { label: 'Extratos do TikTok', href: '/admin/arquivos-tiktok', icon: 'fab fa-tiktok', marca: 'tiktok', permission: 'financeiro.view' },
            { label: 'Centros de custo', href: '/admin/centros-de-custo', icon: 'fas fa-sitemap', cor: 'violeta', permission: 'cadastros.view' },
            { label: 'Relatórios', href: '/admin/relatorios', icon: 'fas fa-chart-pie', cor: 'indigo', permission: 'relatorios.view' },
        ],
    },
    {
        heading: 'Marketing',
        items: [
            { label: 'Cupons', href: '/admin/cupons', icon: 'fas fa-ticket', cor: 'rosa', permission: 'cadastros.view' },
            { label: 'Banners do site', href: '/admin/banners', icon: 'fas fa-images', cor: 'violeta', permission: 'cadastros.view' },
            { label: 'Notificações promocionais', href: '/admin/notificacoes-promocionais', icon: 'fas fa-bullhorn', cor: 'laranja', permission: 'cadastros.view' },
            { label: 'Disparos de WhatsApp', href: '/admin/whatsapp/disparos', icon: 'fas fa-paper-plane', cor: 'ciano', permission: 'configuracoes.integracoes' },
            { label: 'Oportunidades em PDF', href: '/admin/pdf-oportunidades', icon: 'fas fa-file-pdf', cor: 'vermelho', permission: 'relatorios.view' },
            { label: 'Baixar vídeos', href: '/admin/download-videos', icon: 'fas fa-download', cor: 'azul', permission: 'operacional.create' },
        ],
    },
    {
        // Publicidade (pedido do Lira em 2026-10-12): junta o que é de ADS,
        // que antes ficava espalhado entre Financeiro e Marketing.
        heading: 'Publicidade',
        items: [
            { label: 'Visão geral de ADS', href: '/admin/publicidade', icon: 'fas fa-rectangle-ad', cor: 'violeta', permission: 'financeiro.view' },
            // Meta (pedido do Lira 2026-10-13): um subitem só, com o símbolo
            // da Meta, abrindo o gerenciamento de campanhas. O resumo de gasto
            // (/admin/publicidade/meta-ads) fica no botão "Gasto e resultados".
            { label: 'Meta', href: '/admin/publicidade/meta-ads/campanhas', icon: 'fab fa-meta', marca: 'meta', permission: 'financeiro.view' },
            // Google ADS (2026-10-12): integração ainda não conectada, a tela
            // mostra o estado vazio (PlataformaDeAnuncio).
            { label: 'Google ADS', href: '/admin/publicidade/google-ads', icon: 'fab fa-google', marca: 'google', permission: 'financeiro.view' },
            // Estratégia Shopee (2026-10-14): campanhas de ADS com prejuízo para decidir.
            { label: 'Estratégia Shopee', href: '/admin/publicidade/shopee', icon: 'fas fa-chess-knight', marca: 'shopee', permission: 'financeiro.view' },
            { label: 'Recargas de ADS', href: '/admin/anuncios/recargas', icon: 'fas fa-coins', cor: 'ambar', permission: 'financeiro.view' },
            { label: 'Radar de criativos', href: '/admin/mkt-digital', icon: 'fas fa-globe', cor: 'ciano', permission: 'relatorios.view' },
            { label: 'Páginas de conversão', href: '/admin/paginas-de-conversao', icon: 'fas fa-bullseye', cor: 'vermelho', permission: 'relatorios.view' },
        ],
    },
    {
        heading: 'Atendimento',
        items: [
            { label: 'WhatsApp', href: '/admin/whatsapp/conversas', icon: 'fas fa-comments', marca: 'whatsapp', permission: 'pedidos.view', badgeKey: 'whatsappNaoLidas' },
            { label: 'E-mails do site', href: '/admin/mensagens-site', icon: 'fas fa-envelope', cor: 'azul', permission: 'pedidos.view', badgeKey: 'emailsSiteNaoLidos' },
            { label: 'Chamados', href: '/admin/suporte', icon: 'fas fa-headset', cor: 'violeta', permission: 'pedidos.view', badgeKey: 'suporteAbertos' },
            { label: 'Clientes', href: '/admin/clientes', icon: 'fas fa-users', cor: 'ciano', permission: 'pedidos.view' },
        ],
    },
    {
        heading: 'Configurações',
        items: [
            { label: 'Empresa', href: '/admin/empresa', icon: 'fas fa-building', cor: 'azul', permission: null },
            { label: 'Pagamentos do site', href: '/admin/pagamentos', icon: 'fas fa-credit-card', cor: 'verde', permission: null },
            { label: 'Fretes do site', href: '/admin/logistica', icon: 'fas fa-truck', cor: 'laranja', permission: 'operacional.view' },
            { label: 'Integrações', href: '/admin/integracoes', icon: 'fas fa-plug', cor: 'violeta', permission: 'configuracoes.integracoes' },
            { label: 'Conexão do WhatsApp', href: '/admin/whatsapp', icon: 'fab fa-whatsapp', marca: 'whatsapp', permission: 'configuracoes.integracoes' },
            { label: 'Usuários e permissões', href: '/admin/usuarios-permissoes', icon: 'fas fa-user-shield', cor: 'indigo', permission: 'configuracoes.usuarios' },
            { label: 'API de parceiros', href: '/admin/api-parceiros', icon: 'fas fa-key', cor: 'ambar', permission: 'configuracoes.usuarios' },
        ],
    },
    {
        heading: 'Sistema',
        items: [
            // Logs (2026-09-14): tudo de storage/logs numa lista, só admin.
            { label: 'Logs', href: '/admin/logs', icon: 'fas fa-terminal', cor: 'cinza-azul', permission: 'configuracoes.auditoria' },
            { label: 'Auditoria', href: '/admin/auditoria', icon: 'fas fa-clipboard-list', cor: 'indigo', permission: 'configuracoes.auditoria' },
            // Visitas por site (2026-10-12), só admin igual a Logs. Nomes e ordem
            // definidos pelo Lira em 12/10: BeautyKora, Ferrakora, Kazakora.
            { label: 'Produtos Ferrakora', href: '/admin/produtos-ferrakora', icon: 'fas fa-screwdriver-wrench', marca: 'ferrakora', permission: 'configuracoes.auditoria' },
            { label: 'Visitas BeautyKora', href: '/admin/visitas-beautykora', icon: 'fas fa-shoe-prints', marca: 'beautykora', permission: 'configuracoes.auditoria' },
            { label: 'Visitas Ferrakora', href: '/admin/visitas-ferrakora', icon: 'fas fa-shoe-prints', marca: 'ferrakora', permission: 'configuracoes.auditoria' },
            { label: 'Visitas Kazakora', href: '/admin/visitas-site', icon: 'fas fa-shoe-prints', marca: 'kazakora', permission: 'configuracoes.auditoria' },
        ],
    },
];
