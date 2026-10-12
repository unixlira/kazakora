/**
 * Menu do admin (reformulação aprovada pelo Lira em 2026-10-11, Entrega 2
 * de Documents/2026-10-11-admin-cores-e-menu.md): 9 grupos por tarefa do
 * dia, os mesmos 49 itens, com o mesmo href, permission e badgeKey de antes.
 * Nenhuma rota nova e nenhuma permissão mudou.
 *
 * Sem campo de cor por item: o ícone fica numa cor neutra só e a cor vem
 * do estado ativo (ver AdminLayout.vue). Cada item tem ícone próprio, sem
 * repetir, para dar para achar pelo ícone com o menu recolhido.
 *
 * `permission: null` = qualquer pessoa da equipe vê.
 * `badgeKey` = contador de pendências (page.props.sidebarBadges).
 */
import { RADAR_NOME } from '@/Shared/radarNome';

export const sidebarSections = [
    {
        heading: 'Início',
        items: [
            { label: 'Início', href: '/admin', icon: 'fas fa-gauge-high', permission: null },
            { label: 'Indicadores', href: '/admin/indicadores', icon: 'fas fa-gauge', permission: 'relatorios.view' },
        ],
    },
    {
        heading: 'Vender',
        items: [
            { label: 'Produtos', href: '/admin/produtos', icon: 'fas fa-boxes-stacked', permission: 'cadastros.view' },
            { label: 'Categorias', href: '/admin/categorias', icon: 'fas fa-folder-tree', permission: 'cadastros.view' },
            { label: 'Calculadora de preço', href: '/admin/precificacao', icon: 'fas fa-calculator', permission: 'cadastros.view' },
            { label: 'Concorrentes', href: '/admin/concorrencia', icon: 'fas fa-magnifying-glass-chart', permission: 'cadastros.view' },
            { label: 'Fotos de anúncio', href: '/admin/marketplaces/fotos-anuncio', icon: 'fas fa-camera', permission: 'relatorios.view' },
            { label: 'Avaliações', href: '/admin/avaliacoes', icon: 'fas fa-star', permission: 'cadastros.view' },
        ],
    },
    {
        // Oportunidades (pedido do Lira 2026-10-12): o Radar de Oportunidades
        // (uma tela só, sem separar por marketplace; nome em radarNome.js) e
        // os mais vendidos de TERCEIROS em cada marketplace (correção do
        // Lira 2026-10-12: não são as vendas da loja).
        heading: 'Oportunidades',
        items: [
            { label: RADAR_NOME, href: '/admin/radar', icon: 'fas fa-satellite-dish', permission: 'relatorios.view' },
            { label: 'Mercado Livre', href: '/admin/oportunidades/mercado-livre', icon: 'fas fa-chart-simple', permission: 'relatorios.view' },
            { label: 'Shopee', href: '/admin/oportunidades/shopee', icon: 'fas fa-arrow-trend-up', permission: 'relatorios.view' },
        ],
    },
    {
        // Estatísticas (pedido do Lira 2026-10-12): curva ABC com lucro de
        // cada marketplace, que saiu de Oportunidades. Mostra lucro, por
        // isso financeiro.view.
        heading: 'Estatísticas',
        items: [
            { label: 'Curva ABC Mercado Livre', href: '/admin/estatisticas/curva-abc/mercado-livre', icon: 'fas fa-chart-column', permission: 'financeiro.view' },
            { label: 'Curva ABC Shopee', href: '/admin/estatisticas/curva-abc/shopee', icon: 'fas fa-chart-bar', permission: 'financeiro.view' },
        ],
    },
    {
        heading: 'Pedidos e Expedição',
        items: [
            { label: 'Pedidos', href: '/admin/pedidos', icon: 'fas fa-receipt', permission: 'pedidos.view' },
            { label: 'Importar pedido', href: '/admin/importar-pedido', icon: 'fas fa-file-import', permission: 'configuracoes.integracoes' },
            { label: 'Pré-postagem Correios', href: '/admin/correios', icon: 'fas fa-qrcode', permission: 'operacional.view' },
            { label: 'Etiqueta avulsa', href: '/admin/etiquetas-manuais/nova', icon: 'fas fa-tag', permission: 'configuracoes.integracoes' },
            { label: 'Fila de impressão', href: '/admin/impressoes', icon: 'fas fa-print', permission: 'configuracoes.integracoes' },
            // Envios Flex (2026-09-11): o número é quantos envios têm
            // pendência aberta (devolução, venda cancelada com o produto fora,
            // entregador que não iniciou a rota).
            { label: 'Envios Flex', href: '/admin/envios-flex', icon: 'fas fa-motorcycle', permission: 'operacional.view', badgeKey: 'flexAlertas' },
            // Devoluções e reclamações de todas as plataformas (2026-10-06).
            { label: 'Devoluções', href: '/admin/devolucoes', icon: 'fas fa-rotate-left', permission: 'operacional.view', badgeKey: 'devolucoes' },
        ],
    },
    {
        heading: 'Estoque e Compras',
        items: [
            { label: 'Estoque', href: '/admin/estoque', icon: 'fas fa-warehouse', permission: 'operacional.view' },
            { label: 'Compras de fornecedor', href: '/admin/pedidos-de-compra', icon: 'fas fa-cart-arrow-down', permission: 'operacional.view' },
            { label: 'Fornecedores', href: '/admin/fornecedores', icon: 'fas fa-truck-field', permission: 'cadastros.view' },
            { label: 'Ordens de serviço', href: '/admin/ordens-de-servico', icon: 'fas fa-screwdriver-wrench', permission: 'operacional.view' },
        ],
    },
    {
        heading: 'Financeiro',
        items: [
            { label: 'Resultado e margem', href: '/admin/dashboard-financeiro', icon: 'fas fa-chart-line', permission: 'financeiro.view' },
            { label: 'Fluxo de caixa', href: '/admin/fluxo-de-caixa', icon: 'fas fa-money-bill-transfer', permission: 'financeiro.view' },
            { label: 'Notas fiscais', href: '/admin/notas-fiscais', icon: 'fas fa-file-invoice', permission: 'pedidos.view', badgeKey: 'fiscalDuplicidades' },
            { label: 'Fechamento fiscal', href: '/admin/notas-fiscais/fechamento', icon: 'fas fa-calendar-check', permission: 'pedidos.view' },
            { label: 'Custo do Flex', href: '/admin/integracoes/mercado-livre/flex', icon: 'fas fa-file-invoice-dollar', permission: 'configuracoes.integracoes' },
            { label: 'Extratos do TikTok', href: '/admin/arquivos-tiktok', icon: 'fab fa-tiktok', permission: 'financeiro.view' },
            { label: 'Centros de custo', href: '/admin/centros-de-custo', icon: 'fas fa-sitemap', permission: 'cadastros.view' },
            { label: 'Relatórios', href: '/admin/relatorios', icon: 'fas fa-chart-pie', permission: 'relatorios.view' },
        ],
    },
    {
        heading: 'Marketing',
        items: [
            { label: 'Cupons', href: '/admin/cupons', icon: 'fas fa-ticket', permission: 'cadastros.view' },
            { label: 'Banners do site', href: '/admin/banners', icon: 'fas fa-images', permission: 'cadastros.view' },
            { label: 'Notificações promocionais', href: '/admin/notificacoes-promocionais', icon: 'fas fa-bullhorn', permission: 'cadastros.view' },
            { label: 'Disparos de WhatsApp', href: '/admin/whatsapp/disparos', icon: 'fas fa-paper-plane', permission: 'configuracoes.integracoes' },
            { label: 'Oportunidades em PDF', href: '/admin/pdf-oportunidades', icon: 'fas fa-file-pdf', permission: 'relatorios.view' },
            { label: 'Baixar vídeos', href: '/admin/download-videos', icon: 'fas fa-download', permission: 'operacional.create' },
        ],
    },
    {
        // Publicidade (pedido do Lira em 2026-10-12): junta o que é de ADS,
        // que antes ficava espalhado entre Financeiro e Marketing.
        heading: 'Publicidade',
        items: [
            { label: 'Visão geral de ADS', href: '/admin/publicidade', icon: 'fas fa-rectangle-ad', permission: 'financeiro.view' },
            // Meta ADS e Google ADS (2026-10-12): integração ainda não
            // conectada, a tela mostra o estado vazio (PlataformaDeAnuncio).
            { label: 'Meta ADS', href: '/admin/publicidade/meta-ads', icon: 'fab fa-meta', permission: 'financeiro.view' },
            { label: 'Google ADS', href: '/admin/publicidade/google-ads', icon: 'fab fa-google', permission: 'financeiro.view' },
            { label: 'Recargas de ADS', href: '/admin/anuncios/recargas', icon: 'fas fa-coins', permission: 'financeiro.view' },
            { label: 'Radar de criativos', href: '/admin/mkt-digital', icon: 'fas fa-globe', permission: 'relatorios.view' },
            { label: 'Páginas de conversão', href: '/admin/paginas-de-conversao', icon: 'fas fa-bullseye', permission: 'relatorios.view' },
        ],
    },
    {
        heading: 'Atendimento',
        items: [
            { label: 'WhatsApp', href: '/admin/whatsapp/conversas', icon: 'fas fa-comments', permission: 'pedidos.view', badgeKey: 'whatsappNaoLidas' },
            { label: 'E-mails do site', href: '/admin/mensagens-site', icon: 'fas fa-envelope', permission: 'pedidos.view', badgeKey: 'emailsSiteNaoLidos' },
            { label: 'Chamados', href: '/admin/suporte', icon: 'fas fa-headset', permission: 'pedidos.view', badgeKey: 'suporteAbertos' },
            { label: 'Clientes', href: '/admin/clientes', icon: 'fas fa-users', permission: 'pedidos.view' },
        ],
    },
    {
        heading: 'Configurações',
        items: [
            { label: 'Empresa', href: '/admin/empresa', icon: 'fas fa-building', permission: null },
            { label: 'Pagamentos do site', href: '/admin/pagamentos', icon: 'fas fa-credit-card', permission: null },
            { label: 'Fretes do site', href: '/admin/logistica', icon: 'fas fa-truck', permission: 'operacional.view' },
            { label: 'Integrações', href: '/admin/integracoes', icon: 'fas fa-plug', permission: 'configuracoes.integracoes' },
            { label: 'Conexão do WhatsApp', href: '/admin/whatsapp', icon: 'fab fa-whatsapp', permission: 'configuracoes.integracoes' },
            { label: 'Usuários e permissões', href: '/admin/usuarios-permissoes', icon: 'fas fa-user-shield', permission: 'configuracoes.usuarios' },
            { label: 'API de parceiros', href: '/admin/api-parceiros', icon: 'fas fa-key', permission: 'configuracoes.usuarios' },
        ],
    },
    {
        heading: 'Sistema',
        items: [
            // Logs (2026-09-14): tudo de storage/logs numa lista, só admin.
            { label: 'Logs', href: '/admin/logs', icon: 'fas fa-terminal', permission: 'configuracoes.auditoria' },
            { label: 'Auditoria', href: '/admin/auditoria', icon: 'fas fa-clipboard-list', permission: 'configuracoes.auditoria' },
        ],
    },
];
