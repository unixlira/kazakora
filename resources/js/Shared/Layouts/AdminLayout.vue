<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const flashSuccess = computed(() => page.props.flash?.success);

const logout = () => {
    router.post('/logout');
};

const navItems = [
    { href: '/admin', label: 'Dashboard' },
    { href: '/admin/products', label: 'Produtos' },
    { href: '/admin/categories', label: 'Categorias' },
    { href: '/admin/orders', label: 'Pedidos' },
];
</script>

<template>
    <div class="flex min-h-screen bg-gray-50 text-gray-900">
        <aside class="w-56 shrink-0 border-r border-gray-200 bg-white">
            <div class="px-6 py-4">
                <Link href="/" class="text-lg font-semibold">Kazakora</Link>
                <p class="text-xs text-gray-400">Painel administrativo</p>
            </div>

            <nav class="flex flex-col gap-1 px-3">
                <Link
                    v-for="item in navItems"
                    :key="item.href"
                    :href="item.href"
                    class="rounded px-3 py-2 text-sm hover:bg-gray-100"
                >
                    {{ item.label }}
                </Link>
            </nav>

            <div class="mt-auto border-t border-gray-200 px-6 py-4 text-sm">
                <p class="text-gray-500">{{ user?.name }}</p>
                <button type="button" class="mt-1 text-gray-400 hover:text-gray-700" @click="logout">
                    Sair
                </button>
            </div>
        </aside>

        <div class="flex-1">
            <div v-if="flashSuccess" class="bg-green-50 px-6 py-2 text-center text-sm text-green-700">
                {{ flashSuccess }}
            </div>

            <main class="mx-auto max-w-5xl px-6 py-10">
                <slot />
            </main>
        </div>
    </div>
</template>
