<script setup>
// Página pública da promoção (pedido 2026-10-21): título e descrição definidos
// em Vender › Promoções. Cada parágrafo mantém as quebras simples (pre-line);
// o texto entra como texto, nunca como HTML.
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    promocao: { type: Object, required: true },
    previa: { type: Boolean, default: false },
});
</script>

<template>
    <Head :title="promocao.titulo" />

    <AppLayout>
        <div class="mx-auto max-w-[840px] px-4 py-12 md:px-6">
            <p v-if="previa" class="mb-6 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Prévia: a promoção está desativada ou fora do período, o cliente não vê esta página.
            </p>

            <p class="font-store-mono text-xs uppercase tracking-wider text-store-fg-faint">
                <Link href="/" class="hover:text-store-accent">Início</Link> / Promoção
            </p>
            <h1 class="mt-2 font-display text-3xl font-semibold">{{ promocao.titulo }}</h1>

            <section class="mt-8 flex flex-col gap-6 rounded-2xl border border-store-border bg-store-bg-raised p-6 md:flex-row md:items-start lg:p-7">
                <div class="min-w-0 flex-1 space-y-4 text-base leading-relaxed text-store-fg-muted">
                    <p v-for="(paragrafo, i) in promocao.paragrafos" :key="i" class="whitespace-pre-line break-words">{{ paragrafo }}</p>
                    <p v-if="promocao.fim" class="text-sm text-store-fg-faint">Válida até {{ promocao.fim }}.</p>
                </div>
                <img v-if="promocao.imagem_url" :src="promocao.imagem_url" :alt="promocao.titulo"
                     class="mx-auto w-full max-w-[260px] shrink-0 rounded-xl object-contain md:mx-0">
            </section>
        </div>
    </AppLayout>
</template>
