<script setup>
/**
 * Indicadores em abas (2026-10-19). Quando /admin virou o Fluxo de Caixa,
 * tudo o que o Gerenciador mostrava veio para cá: "Visão geral" é o
 * Indicadores de antes; "Hoje e mês", "Cauê e Diego" e "Produtos" são os
 * blocos do Gerenciador. "Acessos ao site" saiu em 2026-10-19 (pedido do
 * Lira); visitantes seguem na Visão geral e no Log de visitas. A aba fica na URL
 * (?aba=) e o servidor calcula só a aba aberta (KpiController).
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import CaueDiego from './CaueDiego.vue';
import HojeEMes from './HojeEMes.vue';
import Produtos from './Produtos.vue';
import VisaoGeral from './VisaoGeral.vue';

defineProps({
    aba: { type: String, default: 'visao-geral' },
    // Visão geral
    painel: { type: Object, default: null },
    paineis: { type: Object, default: null },
    composicao: { type: Object, default: null },
    serie: { type: Object, default: null },
    kpis: { type: Object, default: null },
    // Abas do antigo Gerenciador
    hojeMes: { type: Object, default: null },
    diagnosticos: { type: Object, default: null },
    produtos: { type: Object, default: null },
});

const ABAS = [
    { chave: 'visao-geral', rotulo: 'Visão geral', icone: 'fas fa-square-poll-vertical' },
    { chave: 'hoje-e-mes', rotulo: 'Hoje e mês', icone: 'fas fa-calendar-day' },
    { chave: 'caue-diego', rotulo: 'Cauê e Diego', icone: 'fas fa-list-check' },
    { chave: 'produtos', rotulo: 'Produtos', icone: 'fas fa-fire' },
];
</script>

<template>
    <Head title="Indicadores" />

    <AdminLayout>
        <div class="mb-4">
            <h1 class="text-2xl font-bold">Indicadores</h1>
        </div>

        <nav class="abas-indicadores mb-5 flex gap-1 overflow-x-auto border-b border-[var(--surface-border)]" aria-label="Abas de Indicadores">
            <Link v-for="a in ABAS" :key="a.chave" :href="`/admin/indicadores?aba=${a.chave}`" preserve-scroll
                class="inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-semibold transition-colors"
                :class="aba === a.chave ? 'border-[var(--color-primary)] text-[var(--surface-foreground)]' : 'border-transparent text-[var(--texto-suave)] hover:text-[var(--surface-foreground)]'"
                :aria-current="aba === a.chave ? 'page' : undefined">
                <i :class="a.icone" class="text-xs" aria-hidden="true"></i>{{ a.rotulo }}
            </Link>
        </nav>

        <VisaoGeral v-if="aba === 'visao-geral' && painel" :painel="painel" :paineis="paineis" :composicao="composicao" :serie="serie" :kpis="kpis" />
        <HojeEMes v-else-if="aba === 'hoje-e-mes' && hojeMes" :hoje-mes="hojeMes" />
        <CaueDiego v-else-if="aba === 'caue-diego'" :diagnosticos="diagnosticos ?? { shopee: null, mercado_livre: null }" />
        <Produtos v-else-if="aba === 'produtos' && produtos" :produtos="produtos" />
    </AdminLayout>
</template>
