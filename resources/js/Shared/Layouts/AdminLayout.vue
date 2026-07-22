<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { notifyError, notifySuccess, notifyWarning } from '@/Shared/notify';
import { useClickOutside } from '@/Shared/useClickOutside';
import { useDarkMode } from '@/Shared/useDarkMode';
import { usePermissions } from '@/Shared/usePermissions';
import { sidebarSections } from '@/Shared/adminSidebarItems';

const page = usePage();
const user = computed(() => page.props.auth?.user);

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
const sidebarCollapsed = ref(false);

useClickOutside(userMenuRef, () => (userMenuOpen.value = false));

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

                <div class="h-auto flex-1 items-center overflow-x-hidden overflow-y-auto rounded md:relative md:mt-4 md:flex md:flex-col md:items-stretch md:opacity-100 md:shadow-none"
                    :class="collapseShow">
                    <div v-for="section in visibleSections" :key="section.heading" class="mb-4">
                        <h6 v-if="!sidebarCollapsed" class="mb-2 block pt-1 text-xs font-bold uppercase tracking-wide text-slate-400">
                            {{ section.heading }}
                        </h6>

                        <ul class="flex list-none flex-col md:min-w-full md:flex-col">
                            <li v-for="item in section.items" :key="item.href">
                                <Link :href="item.href" :title="item.label"
                                    class="flex items-center rounded-lg border-l-4 py-2.5 text-sm font-medium transition-colors"
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
                            <Link href="/" class="flex items-center py-2.5 text-sm font-medium text-[var(--sidebar-foreground)] hover:text-primary" title="Ver loja">
                                <i class="fas fa-store w-8 text-center text-sm text-slate-400"></i>
                                <span v-if="!sidebarCollapsed">Ver loja</span>
                            </Link>
                        </li>
                        <li>
                            <button type="button" class="flex w-full items-center py-2.5 text-left text-sm font-medium text-[var(--sidebar-foreground)] hover:text-error" title="Sair" @click="logout">
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
                    <!-- Breadcrumb -->
                    <div class="hidden items-center gap-2 text-sm lg:flex">
                        <span class="text-slate-400">Painel</span>
                        <i v-if="activeItem" class="fas fa-chevron-right text-[10px] text-slate-300"></i>
                        <span v-if="activeItem" class="font-semibold">{{ activeItem.label }}</span>
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

                        <!-- Notifications (placeholder) -->
                        <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-[var(--surface-muted)] hover:text-primary"
                            title="Notificações">
                            <i class="fas fa-bell"></i>
                        </button>

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

            <div class="mx-auto mt-4 w-full px-4 pb-16 md:px-10">
                <slot />
            </div>

            <footer class="fixed inset-x-0 bottom-0 z-10 border-t border-[var(--surface-border)] bg-[var(--surface)] transition-all"
                :class="sidebarCollapsed ? 'md:ml-20' : 'md:ml-64'">
                <div class="px-4 py-3 text-center text-sm text-slate-500 md:px-10">
                    © 2026 KazaKora · CNPJ: 65.604.590/0001-07
                </div>
            </footer>
        </div>
    </div>
</template>
