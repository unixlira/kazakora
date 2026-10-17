<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

// Marketplaces (pedido do Lira, 17/10/2026): os canais integrados de fato,
// cada um pelo logotipo oficial, abrindo nas abas daquele marketplace.
defineProps({
    marketplaces: { type: Array, required: true },
});

const numero = (v) => new Intl.NumberFormat('pt-BR').format(v);
</script>

<template>
    <Head title="Marketplaces" />

    <AdminLayout>
        <div class="mb-6">
            <h1 class="mb-1 text-2xl font-bold">Marketplaces</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Escolha o marketplace para ver oportunidades, promoções, imagens, vídeos, cupons e campanhas.
            </p>
        </div>

        <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <li v-for="m in marketplaces" :key="m.slug">
                <Link :href="m.href"
                    class="group flex h-full flex-col rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm transition hover:border-primary hover:shadow-md focus-visible:outline-2 focus-visible:outline-primary">
                    <span class="mb-4 flex h-20 items-center justify-center rounded-lg bg-white p-3">
                        <img :src="m.logo" :alt="`Logotipo ${m.nome}`" class="max-h-full max-w-[80%] object-contain" />
                    </span>
                    <span class="mb-1 flex items-center justify-between gap-2">
                        <span class="text-base font-semibold">{{ m.nome }}</span>
                        <i class="fas fa-chevron-right text-xs text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-primary" aria-hidden="true"></i>
                    </span>
                    <span class="mb-3 text-sm text-slate-500 dark:text-slate-400">{{ m.integracao }}</span>
                    <span class="mt-auto flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                        <span><strong class="text-[var(--surface-foreground)]">{{ numero(m.pedidos30d) }}</strong> pedidos em 30 dias</span>
                        <span><strong class="text-[var(--surface-foreground)]">{{ m.abasProntas }}</strong> de {{ m.abasTotal }} abas prontas</span>
                    </span>
                </Link>
            </li>
        </ul>
    </AdminLayout>
</template>
