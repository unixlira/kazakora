<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const flashSuccess = computed(() => page.props.flash?.success);

const collapseShow = ref('hidden');
const userMenuOpen = ref(false);

const navItems = [
    { href: '/admin', label: 'Dashboard', icon: 'fas fa-tv' },
    { href: '/admin/products', label: 'Produtos', icon: 'fas fa-couch' },
    { href: '/admin/categories', label: 'Categorias', icon: 'fas fa-tags' },
    { href: '/admin/orders', label: 'Pedidos', icon: 'fas fa-receipt' },
];

const isActive = (href) => (href === '/admin' ? page.url === '/admin' : page.url.startsWith(href));

const logout = () => router.post('/logout');
</script>

<template>
    <div class="min-h-screen bg-slate-100">
        <!-- Sidebar -->
        <nav class="relative z-10 flex flex-wrap items-center justify-between bg-white px-6 py-4 shadow-xl md:fixed md:bottom-0 md:left-0 md:top-0 md:block md:w-64 md:flex-row md:flex-nowrap md:overflow-y-auto">
            <div class="mx-auto flex w-full flex-wrap items-center justify-between px-0 md:min-h-full md:flex-col md:flex-nowrap md:items-stretch">
                <button
                    class="cursor-pointer rounded border border-solid border-transparent bg-transparent px-3 py-1 text-xl leading-none text-black opacity-50 md:hidden"
                    type="button" @click="collapseShow = collapseShow === 'hidden' ? 'block' : 'hidden'">
                    <i class="fas fa-bars"></i>
                </button>

                <Link href="/admin" class="mr-0 inline-block whitespace-nowrap p-4 px-0 text-left text-sm font-bold uppercase text-slate-600 md:block md:pb-2">
                    <i class="fas fa-leaf text-secondary me-2"></i> KazaKora Admin
                </Link>

                <div class="h-auto flex-1 items-center overflow-x-hidden overflow-y-auto rounded shadow md:relative md:mt-4 md:flex md:flex-col md:items-stretch md:opacity-100 md:shadow-none"
                    :class="collapseShow">
                    <h6 class="block pb-4 pt-1 text-xs font-bold uppercase text-slate-400 no-underline md:min-w-full">
                        Gestão da loja
                    </h6>

                    <ul class="flex list-none flex-col md:min-w-full md:flex-col">
                        <li v-for="item in navItems" :key="item.href" class="items-center">
                            <Link :href="item.href" class="block py-3 text-xs font-bold uppercase"
                                :class="isActive(item.href) ? 'text-emerald-500' : 'text-slate-700 hover:text-slate-500'">
                                <i :class="[item.icon, isActive(item.href) ? 'opacity-75' : 'text-slate-300']" class="mr-2 text-sm"></i>
                                {{ item.label }}
                            </Link>
                        </li>
                    </ul>

                    <hr class="my-4 md:min-w-full">

                    <ul class="mb-4 flex list-none flex-col md:min-w-full md:flex-col">
                        <li class="items-center">
                            <Link href="/" class="block py-3 text-xs font-bold uppercase text-slate-700 hover:text-slate-500">
                                <i class="fas fa-store mr-2 text-sm text-slate-300"></i> Ver loja
                            </Link>
                        </li>
                        <li class="items-center">
                            <button type="button" class="block w-full py-3 text-left text-xs font-bold uppercase text-slate-700 hover:text-slate-500" @click="logout">
                                <i class="fas fa-sign-out-alt mr-2 text-sm text-slate-300"></i> Sair
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <div class="relative md:ml-64">
            <!-- Navbar -->
            <nav class="flex items-center bg-white p-4 shadow md:flex-row md:flex-nowrap md:justify-start">
                <div class="mx-auto flex w-full flex-wrap items-center justify-between md:flex-nowrap md:px-4">
                    <span class="hidden text-sm font-semibold uppercase text-slate-700 lg:inline-block">Painel administrativo</span>

                    <div class="relative ml-auto">
                        <button type="button" class="flex items-center text-sm text-slate-600" @click="userMenuOpen = !userMenuOpen">
                            <span class="mr-2 inline-flex h-9 w-9 items-center justify-center rounded-full bg-slate-200 text-sm font-semibold text-slate-600">
                                {{ user?.name?.charAt(0)?.toUpperCase() }}
                            </span>
                            {{ user?.name }}
                        </button>
                        <div v-if="userMenuOpen" class="absolute right-0 z-50 mt-2 min-w-48 rounded bg-white py-2 text-left shadow-lg">
                            <button type="button" class="block w-full whitespace-nowrap bg-transparent px-4 py-2 text-left text-sm text-slate-700" @click="logout">
                                Sair
                            </button>
                        </div>
                    </div>
                </div>
            </nav>

            <div v-if="flashSuccess" class="bg-emerald-50 px-6 py-2 text-center text-sm text-emerald-700">
                {{ flashSuccess }}
            </div>

            <div class="mx-auto mt-4 w-full px-4 md:px-10">
                <slot />

                <footer class="mt-8 pb-8">
                    <hr class="mb-4 border-slate-200">
                    <div class="text-center text-sm text-slate-500">
                        © 2026 KazaKora · CNPJ: 65.604.590/0001-07
                    </div>
                </footer>
            </div>
        </div>
    </div>
</template>
