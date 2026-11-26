<script setup>
// Admin > Leads de materiais (pedido do Lira 26/11/2026): quem baixou o Guia de
// Viagem grátis da FerraKora (/guia-de-viagem), com a origem do link e as UTMs.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    leads: { type: Object, default: () => ({ data: [], links: [] }) },
    total: { type: Number, default: 0 },
    hoje: { type: Number, default: 0 },
    porOrigem: { type: Object, default: () => ({}) },
    filtros: { type: Object, default: () => ({}) },
});

const busca = ref(props.filtros.busca ?? '');
const filtrar = (extra = {}) => router.get('/admin/leads-materiais', { origem: props.filtros.origem || undefined, busca: busca.value || undefined, ...extra }, { preserveState: true, preserveScroll: true });
const exportar = computed(() => {
    const p = new URLSearchParams();
    if (props.filtros.origem) p.set('origem', props.filtros.origem);
    if (props.filtros.busca) p.set('busca', props.filtros.busca);
    const q = p.toString();
    return `/admin/leads-materiais/exportar${q ? `?${q}` : ''}`;
});
const data = (v) => (v ? new Date(v).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', dateStyle: 'short', timeStyle: 'short' }) : '-');
</script>

<template>
    <Head title="Leads de materiais" />

    <AdminLayout>
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-bold">Leads de materiais</h1>
            <a :href="exportar" class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                <i class="fas fa-file-csv"></i> Exportar CSV
            </a>
        </div>
        <p class="mb-4 text-sm text-slate-500">
            Quem deixou nome, e-mail e celular para baixar o Guia de Viagem grátis na FerraKora (ferrakora.com/guia-de-viagem).
            "Origem" é o <code>?origem=</code> do link (instagram, facebook, reels, story...). Cidade/UF é aproximada pelo IP.
        </p>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-slate-200 p-4"><div class="text-xs text-slate-500">Total</div><div class="text-2xl font-bold">{{ total }}</div></div>
            <div class="rounded-lg border border-slate-200 p-4"><div class="text-xs text-slate-500">Hoje</div><div class="text-2xl font-bold">{{ hoje }}</div></div>
            <div class="rounded-lg border border-slate-200 p-4 text-sm">
                <div class="mb-1 text-xs text-slate-500">Por origem</div>
                <div class="flex flex-wrap gap-1">
                    <button type="button" class="rounded-full border px-2 py-0.5 text-xs" :class="!filtros.origem ? 'border-sky-600 bg-sky-50 text-sky-700' : 'border-slate-200'" @click="filtrar({ origem: undefined })">Todas</button>
                    <button v-for="(n, o) in porOrigem" :key="o" type="button" class="rounded-full border px-2 py-0.5 text-xs"
                        :class="filtros.origem === o ? 'border-sky-600 bg-sky-50 text-sky-700' : 'border-slate-200'" @click="filtrar({ origem: o })">{{ o }} ({{ n }})</button>
                </div>
            </div>
        </div>

        <form class="mb-3 flex justify-end gap-2" @submit.prevent="filtrar()">
            <input v-model="busca" type="search" placeholder="Buscar nome, e-mail ou celular" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
            <button type="submit" class="rounded-md border border-slate-300 px-3 py-1 text-sm hover:bg-slate-50">Buscar</button>
        </form>

        <div class="overflow-x-auto rounded-lg border border-slate-200">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr><th class="px-3 py-2">Data</th><th class="px-3 py-2">Nome</th><th class="px-3 py-2">E-mail</th><th class="px-3 py-2">Celular</th><th class="px-3 py-2">Local (IP)</th><th class="px-3 py-2">Origem / UTM</th><th class="px-3 py-2">Contato</th><th class="px-3 py-2">Baixou</th></tr>
                </thead>
                <tbody>
                    <tr v-for="l in leads.data" :key="l.id" class="border-t border-slate-100 align-top">
                        <td class="whitespace-nowrap px-3 py-2">{{ data(l.created_at) }}</td>
                        <td class="px-3 py-2">{{ l.nome }}</td>
                        <td class="break-all px-3 py-2">{{ l.email }}</td>
                        <td class="whitespace-nowrap px-3 py-2"><a :href="l.whatsapp" target="_blank" rel="noopener" class="text-green-700 hover:underline"><i class="fab fa-whatsapp"></i> {{ l.telefone }}</a></td>
                        <td class="px-3 py-2 text-xs text-slate-500">{{ l.local || '-' }}</td>
                        <td class="px-3 py-2 text-xs">
                            <span class="font-semibold">{{ l.origem || '-' }}</span>
                            <div v-for="(v, k) in l.utm" :key="k" class="text-slate-500">utm_{{ k }}: {{ v }}</div>
                        </td>
                        <td class="px-3 py-2 text-xs"><span :class="l.aceita_contato ? 'text-green-700' : 'text-slate-500'">{{ l.aceita_contato ? 'Aceita' : 'Saiu' }}</span></td>
                        <td class="px-3 py-2 text-xs">{{ l.downloads }}x<span v-if="l.cadastros > 1" class="text-slate-500"> · {{ l.cadastros }} cadastros</span></td>
                    </tr>
                    <tr v-if="!leads.data?.length"><td colspan="8" class="px-3 py-8 text-center text-slate-500">Nenhum lead ainda.</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="leads.last_page > 1" class="mt-3 flex flex-wrap gap-1">
            <template v-for="l in leads.links" :key="l.label">
                <Link v-if="l.url" :href="l.url" preserve-scroll class="rounded border px-2.5 py-1 text-sm" :class="l.active ? 'border-sky-600 bg-sky-50 text-sky-700' : 'border-slate-200'"><span v-html="l.label"></span></Link>
            </template>
        </div>
    </AdminLayout>
</template>
