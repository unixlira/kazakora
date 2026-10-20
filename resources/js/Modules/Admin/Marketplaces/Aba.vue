<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

// Aba de marketplace sem tela pronta (Lira, 17/10/2026): diz o que vai ter,
// sem número inventado. Também cobre quem não tem acesso à tela da aba.
// As abas e o logotipo vêm do AdminLayout (prop marketplaceNav).
defineProps({
    marketplaceNav: { type: Object, required: true },
    estado: { type: String, required: true }, // em-breve | sem-permissao
    texto: { type: String, default: null },
    atalho: { type: Object, default: null },
});
</script>

<template>
    <Head :title="`${marketplaceNav.abaNome} · ${marketplaceNav.nome}`" />

    <AdminLayout>
        <section class="rounded-xl border border-dashed border-[var(--surface-border)] bg-[var(--surface)] px-6 py-12 text-center" aria-labelledby="aba-titulo">
            <template v-if="estado === 'sem-permissao'">
                <i class="fas fa-lock mb-3 text-3xl text-slate-400" aria-hidden="true"></i>
                <h1 id="aba-titulo" class="mb-2 text-lg font-semibold">{{ marketplaceNav.abaNome }}</h1>
                <p class="mx-auto max-w-xl text-sm text-slate-500 dark:text-slate-400">Seu usuário não tem acesso a esta tela. Peça a um administrador.</p>
            </template>
            <template v-else>
                <span class="mb-3 inline-block rounded-full bg-[var(--surface-border)] px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Em breve</span>
                <h1 id="aba-titulo" class="mb-2 text-lg font-semibold">{{ marketplaceNav.abaNome }}</h1>
                <p class="mx-auto max-w-xl text-sm text-slate-500 dark:text-slate-400">{{ texto }}</p>
                <div v-if="atalho" class="mt-6">
                    <p class="mb-2 text-sm">{{ atalho.texto }}</p>
                    <Link :href="atalho.href" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-emphasis">
                        {{ atalho.nome }} <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i>
                    </Link>
                </div>
            </template>
        </section>
    </AdminLayout>
</template>
