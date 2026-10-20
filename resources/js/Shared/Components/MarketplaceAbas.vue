<script setup>
import { Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

// Cabeçalho de Marketplaces › <marketplace> (Lira, 2026-10-17): logotipo,
// nome e as seis abas. O AdminLayout desenha este bloco em cima de
// qualquer página que receber a prop `marketplaceNav`, inclusive as telas
// antigas reaproveitadas dentro de uma aba. No celular as abas rolam de lado.
defineProps({
    nav: { type: Object, required: true },
});

// No celular a aba aberta pode ficar fora da tela: rola até ela.
const barra = ref(null);
onMounted(() => {
    const ativa = barra.value?.querySelector('[aria-current="page"]');
    if (ativa && barra.value.scrollWidth > barra.value.clientWidth) {
        barra.value.scrollLeft = ativa.offsetLeft - 16;
    }
});
</script>

<template>
    <div class="mb-6">
        <nav class="mb-3 text-sm" aria-label="Caminho">
            <Link href="/admin/marketplaces" class="text-slate-500 hover:text-primary dark:text-slate-400">
                <i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>Marketplaces
            </Link>
        </nav>

        <div class="mb-4 flex items-center gap-3">
            <span class="flex h-12 w-28 shrink-0 items-center justify-center rounded-xl border border-[var(--surface-border)] bg-white p-2">
                <img :src="nav.logo" :alt="`Logotipo ${nav.nome}`" class="max-h-full max-w-full object-contain" />
            </span>
            <p class="text-2xl font-bold">{{ nav.nome }}</p>
        </div>

        <nav ref="barra" class="relative -mx-4 overflow-x-auto px-4 md:mx-0 md:px-0" :aria-label="`Abas de ${nav.nome}`">
            <div class="flex w-max gap-1 border-b border-[var(--surface-border)] pb-2 md:w-auto">
                <Link v-for="aba in nav.abas" :key="aba.slug" :href="aba.href"
                    :aria-current="aba.slug === nav.aba ? 'page' : undefined"
                    class="flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                    :class="aba.slug === nav.aba ? 'bg-primary text-white' : 'text-slate-500 hover:bg-lightprimary dark:text-slate-400'">
                    {{ aba.nome }}
                    <span v-if="!aba.pronta" class="rounded-full px-1.5 text-[10px] font-semibold uppercase"
                        :class="aba.slug === nav.aba ? 'bg-white/20 text-white' : 'bg-[var(--surface-border)] text-slate-500 dark:text-slate-400'">em breve</span>
                </Link>
            </div>
        </nav>

        <p v-if="nav.origem" class="mt-3 text-xs text-slate-500 dark:text-slate-400">
            Mesma tela de <Link :href="nav.origem.href" class="font-medium text-primary hover:underline">{{ nav.origem.nome }}</Link>.
        </p>
        <p v-if="nav.aviso" class="mt-3 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm" role="note">
            <i class="fas fa-circle-info mr-1 text-primary" aria-hidden="true"></i>{{ nav.aviso }}
        </p>
    </div>
</template>
