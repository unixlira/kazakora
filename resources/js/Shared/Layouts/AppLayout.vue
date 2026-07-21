<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const cartCount = computed(() => page.props.cart?.count ?? 0);
const flashSuccess = computed(() => page.props.flash?.success);
const user = computed(() => page.props.auth?.user);

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <div class="min-h-screen bg-gray-50 text-gray-900">
        <header class="border-b border-gray-200 bg-white">
            <nav class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                <Link href="/" class="text-lg font-semibold">Kazakora</Link>
                <div class="flex items-center gap-6 text-sm">
                    <Link href="/" class="hover:text-gray-600">Catálogo</Link>
                    <Link href="/cart" class="flex items-center gap-1 hover:text-gray-600">
                        Carrinho
                        <span
                            v-if="cartCount > 0"
                            class="rounded-full bg-gray-900 px-2 py-0.5 text-xs font-medium text-white"
                        >
                            {{ cartCount }}
                        </span>
                    </Link>
                    <Link href="/checkout" class="hover:text-gray-600">Checkout</Link>
                    <Link href="/admin" class="hover:text-gray-600">Admin</Link>

                    <template v-if="user">
                        <span class="text-gray-400">{{ user.name }}</span>
                        <button type="button" class="hover:text-gray-600" @click="logout">
                            Sair
                        </button>
                    </template>
                    <template v-else>
                        <Link href="/login" class="hover:text-gray-600">Entrar</Link>
                        <Link href="/register" class="hover:text-gray-600">Cadastrar</Link>
                    </template>
                </div>
            </nav>
        </header>

        <div v-if="flashSuccess" class="bg-green-50 px-6 py-2 text-center text-sm text-green-700">
            {{ flashSuccess }}
        </div>

        <main class="mx-auto max-w-6xl px-6 py-10">
            <slot />
        </main>
    </div>
</template>
