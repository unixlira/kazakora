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
        heading: 'Cadastros',
        items: [
            { label: 'Produtos', href: '/admin/produtos', icon: 'fas fa-boxes-stacked', color: 'text-info', permission: 'cadastros.view' },
            { label: 'Categorias', href: '/admin/categorias', icon: 'fas fa-tags', color: 'text-secondary', permission: 'cadastros.view' },
            { label: 'Fornecedores', href: '/admin/fornecedores', icon: 'fas fa-truck-field', color: 'text-warning', permission: 'cadastros.view' },
            { label: 'Centro de Custos', href: '/admin/centros-de-custo', icon: 'fas fa-sitemap', color: 'text-success', permission: 'cadastros.view' },
        ],
    },
    {
        heading: 'Operacional',
        items: [{ label: 'Pedidos', href: '/admin/pedidos', icon: 'fas fa-receipt', color: 'text-warning', permission: 'pedidos.view' }],
    },
    {
        heading: 'Configurações',
        items: [
            { label: 'Empresa', href: '/admin/empresa', icon: 'fas fa-building', color: 'text-success', permission: null },
            { label: 'Usuários e Permissões', href: '/admin/usuarios-permissoes', icon: 'fas fa-user-shield', color: 'text-error', permission: 'configuracoes.usuarios' },
            { label: 'Auditoria', href: '/admin/auditoria', icon: 'fas fa-clipboard-list', color: 'text-primary', permission: 'configuracoes.auditoria' },
        ],
    },
];
