<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { notifyError, notifySuccess, notifyWarning } from '@/Shared/notify';
import { useClickOutside } from '@/Shared/useClickOutside';
import { useDarkMode } from '@/Shared/useDarkMode';
import { usePermissions } from '@/Shared/usePermissions';
import { sidebarSections } from '@/Shared/adminSidebarItems';
import LowStockAlertModal from '@/Shared/Components/LowStockAlertModal.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const notifications = computed(() => page.props.notifications?.items ?? []);
const unreadNotifications = computed(() => page.props.notifications?.unreadCount ?? 0);

const { isDark, toggle: toggleDarkMode } = useDarkMode();
const { can } = usePermissions();

const visibleSections = computed(() =>
    sidebarSections
        .map((section) => ({
            ...section,
            items: section.items.filter((item) => !item.permission || can(item.permission)),
        }))
        .filter((section) => section.items.length > 0),
);

const collapseShow = ref('hidden');
const userMenuOpen = ref(false);
const userMenuRef = ref(null);
const notifMenuOpen = ref(false);
const notifMenuRef = ref(null);
const sidebarCollapsed = ref(false);

useClickOutside(userMenuRef, () => (userMenuOpen.value = false));
useClickOutside(notifMenuRef, () => (notifMenuOpen.value = false));

// Pedido explícito 2026-08-16: clicar num aviso do dropdown marca como
// lida (some da sineta, ver notificationsFor() no backend — só mostra não
// lidas agora) E abre a tela com todas listadas, não só marca inline.
const openNotification = (item) => {
    notifMenuOpen.value = false;

    if (!item.read) {
        router.post(`/notificacoes/${item.id}/lida`, {}, { preserveScroll: true, preserveState: true });
    }

    router.visit('/notificacoes');
};

// Botão de excluir (pedido explícito 2026-08-16) — some de vez, não some
// só da sineta como o mark-read. @click.stop pro clique não borbulhar pro
// botão do item inteiro (que abriria a tela de notificações).
const removeNotification = (item) => {
    router.delete(`/notificacoes/${item.id}`, { preserveScroll: true, preserveState: true });
};

const markAllNotificationsRead = () => {
    router.post('/notificacoes/ler-todas', {}, { preserveScroll: true, preserveState: true });
};

onMounted(() => {
    sidebarCollapsed.value = localStorage.getItem('admin_sidebar_collapsed') === '1';
});

const toggleSidebar = () => {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    localStorage.setItem('admin_sidebar_collapsed', sidebarCollapsed.value ? '1' : '0');
};

const isActive = (href) => (href === '/admin' ? page.url === '/admin' : page.url.startsWith(href));

const activeItem = computed(() => {
    for (const section of visibleSections.value) {
        const match = section.items.find((item) => isActive(item.href));
        if (match) return match;
    }
    return null;
});

const logout = () => router.post('/sair');

// Pedido explícito 2026-08-15: botão de voltar visível em toda tela do
// admin, sem atrapalhar o layout de nenhuma página — colocado só uma vez
// aqui no layout compartilhado (não em cada tela) em vez de duplicado.
// history.back() de propósito, não um Link fixo pra "/admin" — Inertia usa
// pushState nos visits normais, então o histórico do navegador já reflete
// a navegação real dentro do admin; volta pra tela anterior de verdade,
// não sempre pro dashboard. Escondido na própria raiz (/admin) porque não
// há "tela anterior" que faça sentido voltar a partir dali.
const goBack = () => window.history.back();

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) notifySuccess(flash.success);
        if (flash?.error) notifyError(flash.error);
        if (flash?.warning) notifyWarning(flash.warning);
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div class="admin-shell min-h-screen bg-[var(--surface-muted)] text-[var(--surface-foreground)]">
        <!-- Sidebar -->
        <nav class="relative z-20 flex flex-wrap items-center justify-between border-r border-[var(--surface-border)] bg-[var(--surface)] px-6 py-4 shadow-sm transition-all md:fixed md:bottom-0 md:left-0 md:top-0 md:block md:flex-row md:flex-nowrap md:overflow-y-auto"
            :class="sidebarCollapsed ? 'md:w-20' : 'md:w-64'">
            <div class="mx-auto flex w-full flex-wrap items-center justify-between px-0 md:min-h-full md:flex-col md:flex-nowrap md:items-stretch">
                <button
                    class="cursor-pointer rounded border border-solid border-transparent bg-transparent px-3 py-1 text-xl leading-none opacity-50 md:hidden"
                    type="button" @click="collapseShow = collapseShow === 'hidden' ? 'block' : 'hidden'">
                    <i class="fas fa-bars"></i>
                </button>

                <div class="hidden w-full items-center justify-between md:flex">
                    <Link href="/admin" class="inline-block whitespace-nowrap p-4 px-0 text-left text-sm font-bold uppercase text-primary">
                        <i class="fas fa-leaf"></i>
                        <span v-if="!sidebarCollapsed" class="ml-2">KazaKora Admin</span>
                    </Link>
                    <button type="button" class="text-slate-400 hover:text-primary" @click="toggleSidebar">
                        <i :class="sidebarCollapsed ? 'fas fa-angles-right' : 'fas fa-angles-left'"></i>
                    </button>
                </div>

                <Link href="/admin" class="mr-0 inline-block whitespace-nowrap p-4 px-0 text-left text-sm font-bold uppercase text-primary md:hidden">
                    <i class="fas fa-leaf me-2"></i> KazaKora Admin
                </Link>

                <!-- Bug real reportado 2026-08-09: esse painel não tinha
                largura própria — virava mais um item dentro do
                "flex flex-wrap justify-between" do cabeçalho (linha acima,
                hambúrguer + logo) em vez de cair pra uma linha nova abaixo
                dele. w-full/basis-full força quebra de linha garantida no
                mobile (flex-wrap só quebra linha quando o item não cabe; um
                item de 100% de largura nunca cabe ao lado de outro). -->
                <div class="h-auto w-full basis-full items-center overflow-x-hidden overflow-y-auto rounded md:relative md:mt-4 md:flex md:w-auto md:flex-1 md:flex-col md:items-stretch md:opacity-100 md:shadow-none"
                    :class="collapseShow">
                    <div v-for="section in visibleSections" :key="section.heading" class="mb-4">
                        <h6 v-if="!sidebarCollapsed" class="mb-2 block pt-1 text-xs font-bold uppercase tracking-wide text-slate-400">
                            {{ section.heading }}
                        </h6>

                        <ul class="flex list-none flex-col md:min-w-full md:flex-col">
                            <li v-for="item in section.items" :key="item.href">
                                <Link :href="item.href" :title="item.label"
                                    class="flex items-center gap-2 rounded-lg border-l-4 py-2.5 text-sm font-medium transition-colors"
                                    :class="isActive(item.href)
                                        ? 'border-primary bg-[var(--surface-border)]/40 text-primary'
                                        : 'border-transparent text-[var(--sidebar-foreground)] hover:bg-[var(--surface-border)]/30'">
                                    <i class="w-8 text-center text-sm" :class="[item.icon, isActive(item.href) ? item.color : 'text-slate-400']"></i>
                                    <span v-if="!sidebarCollapsed" class="truncate">{{ item.label }}</span>
                                </Link>
                            </li>
                        </ul>
                    </div>

                    <hr class="my-4 border-[var(--surface-border)] md:min-w-full">

                    <ul class="mb-4 flex list-none flex-col md:min-w-full md:flex-col">
                        <li>
                            <Link href="/" class="flex items-center gap-2 py-2.5 text-sm font-medium text-[var(--sidebar-foreground)] hover:text-primary" title="Ver loja">
                                <i class="fas fa-store w-8 text-center text-sm text-slate-400"></i>
                                <span v-if="!sidebarCollapsed">Ver loja</span>
                            </Link>
                        </li>
                        <li>
                            <button type="button" class="flex w-full items-center gap-2 py-2.5 text-left text-sm font-medium text-[var(--sidebar-foreground)] hover:text-error" title="Sair" @click="logout">
                                <i class="fas fa-sign-out-alt w-8 text-center text-sm text-slate-400"></i>
                                <span v-if="!sidebarCollapsed">Sair</span>
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Sidebar footer: user profile -->
                <div v-if="!sidebarCollapsed" class="mt-auto hidden w-full items-center gap-3 border-t border-[var(--surface-border)] py-4 md:flex">
                    <img v-if="user?.avatar_url" :src="user.avatar_url" class="h-9 w-9 rounded-full object-cover" alt="">
                    <span v-else class="flex h-9 w-9 items-center justify-center rounded-full bg-lightprimary text-sm font-semibold text-primary">
                        {{ user?.initials }}
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold">{{ user?.name }}</p>
                        <p class="truncate text-xs capitalize text-slate-400">{{ user?.role }}</p>
                    </div>
                </div>
            </div>
        </nav>

        <div class="relative transition-all" :class="sidebarCollapsed ? 'md:ml-20' : 'md:ml-64'">
            <!-- Topbar -->
            <nav class="sticky top-0 z-10 flex items-center border-b border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm md:flex-row md:flex-nowrap md:justify-start">
                <div class="mx-auto flex w-full flex-wrap items-center justify-between gap-4 md:flex-nowrap md:px-4">
                    <div class="flex items-center gap-2">
                        <!-- Voltar (pedido explícito 2026-08-15) — sempre
                        visível (não escondido em telas pequenas, diferente
                        do breadcrumb ao lado), mesmo estilo circular dos
                        outros botões da topbar. Some só na raiz do admin,
                        onde "voltar" não teria destino nenhum. -->
                        <button v-if="page.url !== '/admin'" type="button"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-500 hover:bg-[var(--surface-muted)] hover:text-primary"
                            title="Voltar" @click="goBack">
                            <i class="fas fa-arrow-left"></i>
                        </button>

                        <!-- Breadcrumb -->
                        <div class="hidden items-center gap-2 text-sm lg:flex">
                            <span class="text-slate-400">Painel</span>
                            <i v-if="activeItem" class="fas fa-chevron-right text-[10px] text-slate-300"></i>
                            <span v-if="activeItem" class="font-semibold">{{ activeItem.label }}</span>
                        </div>
                    </div>

                    <!-- Search (visual only for now) -->
                    <div class="relative hidden w-full max-w-xs md:block">
                        <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                        <input type="text" placeholder="Buscar..." disabled
                            class="w-full cursor-not-allowed rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] py-2 pl-9 pr-3 text-sm text-slate-400 placeholder:text-slate-400">
                    </div>

                    <div class="ml-auto flex items-center gap-1">
                        <!-- Dark mode toggle -->
                        <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-[var(--surface-muted)] hover:text-primary"
                            title="Alternar tema" @click="toggleDarkMode">
                            <i :class="isDark ? 'fas fa-sun' : 'fas fa-moon'"></i>
                        </button>

                        <!-- Notifications -->
                        <div ref="notifMenuRef" class="relative">
                            <button type="button" class="relative flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-[var(--surface-muted)] hover:text-primary"
                                title="Notificações" @click="notifMenuOpen = !notifMenuOpen">
                                <i class="fas fa-bell"></i>
                                <span v-if="unreadNotifications > 0" class="absolute right-0.5 top-0.5 rounded-full bg-primary px-1 text-[0.6rem] font-semibold leading-tight text-white">{{ unreadNotifications }}</span>
                            </button>

                            <div v-if="notifMenuOpen" class="absolute right-0 z-50 mt-2 w-80 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] py-2 text-left shadow-md">
                                <div class="flex items-center justify-between px-4 py-2">
                                    <h5 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Notificações</h5>
                                    <button v-if="unreadNotifications > 0" type="button" class="text-xs font-medium text-primary hover:underline" @click="markAllNotificationsRead">
                                        Marcar todas como lidas
                                    </button>
                                </div>

                                <!-- Só mostra não lidas (ver notificationsFor() no backend) —
                                pedido explícito 2026-08-16: "quando visualizadas não aparecem
                                na barra". -->
                                <p v-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-slate-400">
                                    Nenhuma notificação nova.
                                </p>

                                <div v-for="item in notifications" :key="item.id"
                                    class="group flex w-full items-start gap-2 px-4 py-2.5 text-left text-sm hover:bg-[var(--surface-muted)]">
                                    <button type="button" class="flex min-w-0 flex-1 items-start gap-2 text-left" @click="openNotification(item)">
                                        <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"></span>
                                        <span class="min-w-0">
                                            <span class="block text-slate-700 dark:text-slate-200">{{ item.message }}</span>
                                            <span class="text-xs text-slate-400">{{ item.createdAt }}</span>
                                        </span>
                                    </button>
                                    <button type="button" title="Excluir"
                                        class="shrink-0 rounded-full p-1.5 text-slate-300 opacity-0 hover:bg-error/10 hover:text-error group-hover:opacity-100"
                                        @click.stop="removeNotification(item)">
                                        <i class="fas fa-xmark text-xs"></i>
                                    </button>
                                </div>

                                <Link href="/notificacoes" class="block px-4 py-2 text-center text-xs font-medium text-primary hover:underline"
                                    @click="notifMenuOpen = false">
                                    Ver todas
                                </Link>
                            </div>
                        </div>

                        <div ref="userMenuRef" class="relative ml-2">
                            <button type="button" class="flex items-center text-sm" @click="userMenuOpen = !userMenuOpen">
                                <img v-if="user?.avatar_url" :src="user.avatar_url" class="mr-2 h-9 w-9 rounded-full object-cover" alt="">
                                <span v-else class="mr-2 inline-flex h-9 w-9 items-center justify-center rounded-full bg-lightprimary text-sm font-semibold text-primary">
                                    {{ user?.initials }}
                                </span>
                                <span class="hidden md:inline">{{ user?.name }}</span>
                                <i class="fas fa-chevron-down ml-2 text-xs text-slate-400"></i>
                            </button>
                            <div v-if="userMenuOpen" class="absolute right-0 z-50 mt-2 min-w-48 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] py-2 text-left shadow-md">
                                <Link href="/perfil" class="block whitespace-nowrap px-4 py-2 text-sm hover:bg-[var(--surface-muted)]">
                                    <i class="fas fa-user mr-2 text-slate-400"></i> Meu Perfil
                                </Link>
                                <Link href="/configuracoes" class="block whitespace-nowrap px-4 py-2 text-sm hover:bg-[var(--surface-muted)]">
                                    <i class="fas fa-gear mr-2 text-slate-400"></i> Configurações
                                </Link>
                                <hr class="my-1 border-[var(--surface-border)]">
                                <button type="button" class="block w-full whitespace-nowrap bg-transparent px-4 py-2 text-left text-sm hover:bg-[var(--surface-muted)]" @click="logout">
                                    <i class="fas fa-arrow-right-from-bracket mr-2 text-slate-400"></i> Sair
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </nav>

            <div class="mx-auto mt-4 w-full max-w-[1600px] p-4 md:p-6">
                <slot />
            </div>

            <!-- Rodapé no fluxo normal da página (não mais fixed) — fixo
            cobria o final do conteúdo real em telas com bastante coisa
            (tabelas grandes, formulários longos), dando a impressão de que
            o scroll nunca "chegava no final". Bug real reportado pelo
            usuário 2026-08-05. -->
            <footer class="border-t border-[var(--surface-border)] bg-[var(--surface)]">
                <div class="px-4 py-3 text-center text-sm text-slate-500 md:px-10">
                    © 2026 KazaKora · CNPJ: 65.604.590/0001-07
                </div>
            </footer>
        </div>

        <LowStockAlertModal />
    </div>
</template>
