<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { notifyError, notifySuccess, notifyWarning } from '@/Shared/notify';
import { useClickOutside } from '@/Shared/useClickOutside';

const page = usePage();
const user = computed(() => page.props.auth?.user);

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

const navItems = [
    { href: '/admin', label: 'Dashboard', icon: 'fas fa-tv' },
    { href: '/admin/produtos', label: 'Produtos', icon: 'fas fa-boxes-stacked' },
    { href: '/admin/categorias', label: 'Categorias', icon: 'fas fa-tags' },
    { href: '/admin/pedidos', label: 'Pedidos', icon: 'fas fa-receipt' },
    { href: '/admin/empresa', label: 'Empresa', icon: 'fas fa-building' },
];

const isActive = (href) => (href === '/admin' ? page.url === '/admin' : page.url.startsWith(href));

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
    <div class="min-h-screen bg-slate-100">
        <!-- Sidebar -->
        <nav class="relative z-10 flex flex-wrap items-center justify-between bg-white px-6 py-4 shadow-xl transition-all md:fixed md:bottom-0 md:left-0 md:top-0 md:block md:flex-row md:flex-nowrap md:overflow-y-auto"
            :class="sidebarCollapsed ? 'md:w-20' : 'md:w-64'">
            <div class="mx-auto flex w-full flex-wrap items-center justify-between px-0 md:min-h-full md:flex-col md:flex-nowrap md:items-stretch">
                <button
                    class="cursor-pointer rounded border border-solid border-transparent bg-transparent px-3 py-1 text-xl leading-none text-black opacity-50 md:hidden"
                    type="button" @click="collapseShow = collapseShow === 'hidden' ? 'block' : 'hidden'">
                    <i class="fas fa-bars"></i>
                </button>

                <div class="hidden w-full items-center justify-between md:flex">
                    <Link href="/admin" class="inline-block whitespace-nowrap p-4 px-0 text-left text-sm font-bold uppercase text-slate-600">
                        <i class="fas fa-leaf text-secondary"></i>
                        <span v-if="!sidebarCollapsed" class="ml-2">KazaKora Admin</span>
                    </Link>
                    <button type="button" class="text-slate-400 hover:text-slate-600" @click="toggleSidebar">
                        <i :class="sidebarCollapsed ? 'fas fa-angles-right' : 'fas fa-angles-left'"></i>
                    </button>
                </div>

                <Link href="/admin" class="mr-0 inline-block whitespace-nowrap p-4 px-0 text-left text-sm font-bold uppercase text-slate-600 md:hidden">
                    <i class="fas fa-leaf text-secondary me-2"></i> KazaKora Admin
                </Link>

                <div class="h-auto flex-1 items-center overflow-x-hidden overflow-y-auto rounded shadow md:relative md:mt-4 md:flex md:flex-col md:items-stretch md:opacity-100 md:shadow-none"
                    :class="collapseShow">
                    <h6 v-if="!sidebarCollapsed" class="block pb-4 pt-1 text-xs font-bold uppercase text-slate-400 no-underline md:min-w-full">
                        Gestão da loja
                    </h6>

                    <ul class="flex list-none flex-col md:min-w-full md:flex-col">
                        <li v-for="item in navItems" :key="item.href" class="items-center">
                            <Link :href="item.href" class="block py-3 text-xs font-bold uppercase" :title="item.label"
                                :class="isActive(item.href) ? 'text-emerald-500' : 'text-slate-700 hover:text-slate-500'">
                                <i class="text-sm" :class="[item.icon, isActive(item.href) ? 'opacity-75' : 'text-slate-300', { 'mr-2': !sidebarCollapsed }]"></i>
                                <span v-if="!sidebarCollapsed">{{ item.label }}</span>
                            </Link>
                        </li>
                    </ul>

                    <hr class="my-4 md:min-w-full">

                    <ul class="mb-4 flex list-none flex-col md:min-w-full md:flex-col">
                        <li class="items-center">
                            <Link href="/" class="block py-3 text-xs font-bold uppercase text-slate-700 hover:text-slate-500" title="Ver loja">
                                <i class="fas fa-store text-sm text-slate-300" :class="{ 'mr-2': !sidebarCollapsed }"></i>
                                <span v-if="!sidebarCollapsed">Ver loja</span>
                            </Link>
                        </li>
                        <li class="items-center">
                            <button type="button" class="block w-full py-3 text-left text-xs font-bold uppercase text-slate-700 hover:text-slate-500" title="Sair" @click="logout">
                                <i class="fas fa-sign-out-alt text-sm text-slate-300" :class="{ 'mr-2': !sidebarCollapsed }"></i>
                                <span v-if="!sidebarCollapsed">Sair</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <div class="relative transition-all" :class="sidebarCollapsed ? 'md:ml-20' : 'md:ml-64'">
            <!-- Navbar -->
            <nav class="flex items-center bg-white p-4 shadow md:flex-row md:flex-nowrap md:justify-start">
                <div class="mx-auto flex w-full flex-wrap items-center justify-between md:flex-nowrap md:px-4">
                    <span class="hidden text-sm font-semibold uppercase text-slate-700 lg:inline-block">Painel administrativo</span>

                    <div ref="userMenuRef" class="relative ml-auto">
                        <button type="button" class="flex items-center text-sm text-slate-600" @click="userMenuOpen = !userMenuOpen">
                            <img v-if="user?.avatar_url" :src="user.avatar_url" class="mr-2 h-9 w-9 rounded-full object-cover" alt="">
                            <span v-else class="mr-2 inline-flex h-9 w-9 items-center justify-center rounded-full bg-slate-200 text-sm font-semibold text-slate-600">
                                {{ user?.initials }}
                            </span>
                            {{ user?.name }}
                            <i class="fas fa-chevron-down ml-2 text-xs text-slate-400"></i>
                        </button>
                        <div v-if="userMenuOpen" class="absolute right-0 z-50 mt-2 min-w-48 rounded bg-white py-2 text-left shadow-lg">
                            <Link href="/perfil" class="block whitespace-nowrap px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                <i class="fas fa-user mr-2 text-slate-400"></i> Meu Perfil
                            </Link>
                            <Link href="/configuracoes" class="block whitespace-nowrap px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                <i class="fas fa-gear mr-2 text-slate-400"></i> Configurações
                            </Link>
                            <hr class="my-1 border-slate-100">
                            <button type="button" class="block w-full whitespace-nowrap bg-transparent px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50" @click="logout">
                                <i class="fas fa-arrow-right-from-bracket mr-2 text-slate-400"></i> Sair
                            </button>
                        </div>
                    </div>
                </div>
            </nav>

            <div class="mx-auto mt-4 w-full px-4 pb-16 md:px-10">
                <slot />
            </div>

            <footer class="fixed inset-x-0 bottom-0 z-10 border-t border-slate-200 bg-white transition-all"
                :class="sidebarCollapsed ? 'md:ml-20' : 'md:ml-64'">
                <div class="px-4 py-3 text-center text-sm text-slate-500 md:px-10">
                    © 2026 KazaKora · CNPJ: 65.604.590/0001-07
                </div>
            </footer>
        </div>
    </div>
</template>
