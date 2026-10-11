<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    product: { type: Object, required: true },
});
</script>

<template>
    <Head :title="`Formas de envio — ${product.name}`" />

    <AppLayout>
        <div class="mx-auto flex max-w-2xl flex-col items-center px-4 py-24 text-center md:px-6">
            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-store-accent/10 text-store-accent">
                <i class="fas fa-truck-fast text-2xl"></i>
            </span>
            <h1 class="mt-4 font-display text-2xl font-semibold text-store-fg md:text-3xl">Mais formas de envio</h1>
            <p class="mt-3 text-store-fg-muted">
                Estamos preparando esta página com todos os detalhes de entrega para <strong class="text-store-fg">{{ product.name }}</strong>.
                Em breve você poderá conferir aqui todas as opções de frete disponíveis.
            </p>
            <Link :href="`/produtos/${product.slug}`" class="mt-6 text-sm font-semibold text-store-accent hover:underline">
                Voltar para o produto
            </Link>
        </div>
    </AppLayout>
</template>
