/**
 * Sidebar IA for the admin panel. Grouped by section like the reference
 * design (Gestão / Cadastros / Operacional / Configurações). New sections
 * are added here as their pages actually ship — no dead links in the menu.
 * `permission: null` means "any staff role can see it".
 */
export const sidebarSections = [
    {
        heading: 'Geral',
        items: [{ label: 'Dashboard', href: '/admin', icon: 'fas fa-gauge-high', color: 'text-primary', permission: null }],
    },
    {
        heading: 'Gestão',
        items: [
            { label: 'Financeiro', href: '/admin/dashboard-financeiro', icon: 'fas fa-chart-line', color: 'text-primary', permission: 'financeiro.view' },
            { label: 'Fluxo de Caixa', href: '/admin/fluxo-de-caixa', icon: 'fas fa-money-bill-transfer', color: 'text-success', permission: 'financeiro.view' },
            { label: 'Recargas de Anúncio', href: '/admin/anuncios/recargas', icon: 'fas fa-bullhorn', color: 'text-warning', permission: 'financeiro.view' },
            { label: 'Relatórios', href: '/admin/relatorios', icon: 'fas fa-file-lines', color: 'text-info', permission: 'relatorios.view' },
            { label: 'Indicadores', href: '/admin/indicadores', icon: 'fas fa-gauge', color: 'text-warning', permission: 'relatorios.view' },
        ],
    },
    {
        heading: 'Cadastros',
        items: [
            { label: 'Produtos', href: '/admin/produtos', icon: 'fas fa-boxes-stacked', color: 'text-info', permission: 'cadastros.view' },
            { label: 'Calculadora de Preços', href: '/admin/precificacao', icon: 'fas fa-calculator', color: 'text-success', permission: 'cadastros.view' },
            { label: 'Análise de Concorrentes', href: '/admin/concorrencia', icon: 'fas fa-magnifying-glass-chart', color: 'text-info', permission: 'cadastros.view' },
            { label: 'Categorias', href: '/admin/categorias', icon: 'fas fa-tags', color: 'text-secondary', permission: 'cadastros.view' },
            { label: 'Banners', href: '/admin/banners', icon: 'fas fa-images', color: 'text-info', permission: 'cadastros.view' },
            { label: 'Avaliações', href: '/admin/avaliacoes', icon: 'fas fa-star', color: 'text-warning', permission: 'cadastros.view' },
            { label: 'Notificações Promocionais', href: '/admin/notificacoes-promocionais', icon: 'fas fa-bullhorn', color: 'text-warning', permission: 'cadastros.view' },
            { label: 'Fornecedores', href: '/admin/fornecedores', icon: 'fas fa-truck-field', color: 'text-warning', permission: 'cadastros.view' },
            { label: 'Centro de Custos', href: '/admin/centros-de-custo', icon: 'fas fa-sitemap', color: 'text-success', permission: 'cadastros.view' },
        ],
    },
    {
        heading: 'Operacional',
        items: [
            { label: 'Pedidos', href: '/admin/pedidos', icon: 'fas fa-receipt', color: 'text-warning', permission: 'pedidos.view' },
            { label: 'Clientes', href: '/admin/clientes', icon: 'fas fa-users', color: 'text-primary', permission: 'pedidos.view' },
            { label: 'Notas Fiscais', href: '/admin/notas-fiscais', icon: 'fas fa-file-invoice', color: 'text-info', permission: 'pedidos.view' },
            { label: 'Pedidos de Compra', href: '/admin/pedidos-de-compra', icon: 'fas fa-cart-arrow-down', color: 'text-info', permission: 'operacional.view' },
            { label: 'Ordens de Serviço', href: '/admin/ordens-de-servico', icon: 'fas fa-screwdriver-wrench', color: 'text-secondary', permission: 'operacional.view' },
            { label: 'Estoque', href: '/admin/estoque', icon: 'fas fa-warehouse', color: 'text-success', permission: 'operacional.view' },
            { label: 'Logística', href: '/admin/logistica', icon: 'fas fa-truck', color: 'text-primary', permission: 'operacional.view' },
            { label: 'Correios', href: '/admin/correios', icon: 'fas fa-qrcode', color: 'text-secondary', permission: 'operacional.view' },
        ],
    },
    {
        // Espelho web do app desktop KoraSync (pedido explícito 2026-08-31:
        // "menu Korasync... dentro dele todos submenus das filas") — mesmas
        // 5 telas que o operador de separação já usa no app nativo, agora
        // acessíveis também pelo navegador. Ver KoraSyncController.
        heading: 'KoraSync',
        items: [
            { label: 'Fila normal', href: '/admin/korasync/fila', icon: 'fas fa-list-check', color: 'text-success', permission: 'operacional.view' },
            { label: 'Sem estoque', href: '/admin/korasync/sem-estoque', icon: 'fas fa-triangle-exclamation', color: 'text-warning', permission: 'operacional.view' },
            { label: 'Vendas futuras', href: '/admin/korasync/vendas-futuras', icon: 'fas fa-calendar-days', color: 'text-warning', permission: 'operacional.view' },
            { label: 'Separados', href: '/admin/korasync/separados', icon: 'fas fa-boxes-packing', color: 'text-success', permission: 'operacional.view' },
            { label: 'Cancelados', href: '/admin/korasync/cancelados', icon: 'fas fa-circle-xmark', color: 'text-error', permission: 'operacional.view' },
        ],
    },

    {
        heading: 'WhatsApp',
        items: [
            { label: 'Configurações', href: '/admin/whatsapp', icon: 'fab fa-whatsapp', color: 'text-success', permission: 'configuracoes.integracoes' },
            { label: 'Disparos', href: '/admin/whatsapp/disparos', icon: 'fas fa-paper-plane', color: 'text-primary', permission: 'configuracoes.integracoes' },
        ],
    },
    {
        heading: 'Configurações',
        items: [
            { label: 'Empresa', href: '/admin/empresa', icon: 'fas fa-building', color: 'text-success', permission: null },
            { label: 'Pagamentos', href: '/admin/pagamentos', icon: 'fas fa-credit-card', color: 'text-warning', permission: null },
            { label: 'Usuários e Permissões', href: '/admin/usuarios-permissoes', icon: 'fas fa-user-shield', color: 'text-error', permission: 'configuracoes.usuarios' },
            { label: 'API de Parceiros', href: '/admin/api-parceiros', icon: 'fas fa-key', color: 'text-error', permission: 'configuracoes.usuarios' },
            { label: 'Integrações', href: '/admin/integracoes', icon: 'fas fa-plug', color: 'text-secondary', permission: 'configuracoes.integracoes' },
            { label: 'Impressões', href: '/admin/impressoes', icon: 'fas fa-print', color: 'text-info', permission: 'configuracoes.integracoes' },
            { label: 'Etiquetas Manuais', href: '/admin/etiquetas-manuais/nova', icon: 'fas fa-tags', color: 'text-secondary', permission: 'configuracoes.integracoes' },
            { label: 'Importar Pedido', href: '/admin/importar-pedido', icon: 'fas fa-magnifying-glass', color: 'text-info', permission: 'configuracoes.integracoes' },
            { label: 'Auditoria', href: '/admin/auditoria', icon: 'fas fa-clipboard-list', color: 'text-primary', permission: 'configuracoes.auditoria' },
        ],
    },
];
