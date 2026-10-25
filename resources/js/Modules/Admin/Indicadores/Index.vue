<script setup>
/**
 * Indicadores em abas (2026-10-19). Quando /admin virou o Fluxo de Caixa,
 * tudo o que o Gerenciador mostrava veio para cá: "Visão geral" é o
 * Indicadores de antes; "Hoje e mês", "Cauê e Diego", "Produtos" e
 * "Visitas KazaKora" são os blocos do Gerenciador. A aba fica na URL
 * (?aba=) e o servidor calcula só a aba aberta (KpiController).
 * "Visitas FerraKora" (pedido 2026-10-22) é o mesmo bloco com as visitas
 * gravadas com site = ferrakora.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import AcessosSite from './AcessosSite.vue';
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
    visitantes: { type: Object, default: null },
});

const ABAS = [
    { chave: 'visao-geral', rotulo: 'Visão geral', icone: 'fas fa-square-poll-vertical' },
    { chave: 'hoje-e-mes', rotulo: 'Hoje e mês', icone: 'fas fa-calendar-day' },
    { chave: 'caue-diego', rotulo: 'Cauê e Diego', icone: 'fas fa-list-check' },
    { chave: 'produtos', rotulo: 'Produtos', icone: 'fas fa-fire' },
    { chave: 'acessos', rotulo: 'Visitas KazaKora', icone: 'fas fa-globe' },
    { chave: 'visitas-ferrakora', rotulo: 'Visitas FerraKora', icone: 'fas fa-screwdriver-wrench' },
];
</script>

<template>
    <Head title="Indicadores" />

    <AdminLayout>
        <div class="mb-4">
            <h1 class="text-2xl font-bold">Indicadores</h1>
        </div>

        <nav class="abas-indicadores mb-5 flex gap-1 overflow-x-auto border-b border-[var(--surface-border)]" aria-label="Abas de Indicadores">
            <Link v-for="a in ABAS" :key="a.chave" :href="`/admin/indicadores?aba=${a.chave}`" preserve-scroll :data-clique="`Indicadores: aba ${a.rotulo}`"
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
        <AcessosSite v-else-if="(aba === 'acessos' || aba === 'visitas-ferrakora') && visitantes" :key="aba" :aba="aba" :visitantes="visitantes" />
    </AdminLayout>
</template>
