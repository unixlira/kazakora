<script setup>
// Admin > Newsletter (pedido do Lira 24/11/2026): inscritos, CSV, prévia do
// resumo do blog e "Enviar teste para mim" (vai só para o seu e-mail).
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    inscritos: { type: Object, default: () => ({ data: [], links: [] }) },
    contagem: { type: Object, default: () => ({}) },
    filtros: { type: Object, default: () => ({}) },
    blog: { type: Object, default: () => ({}) },
    envios: { type: Array, default: () => [] },
});

const busca = ref(props.filtros.busca ?? '');
const enviandoTeste = ref(false);

const filtrar = (extra = {}) => router.get('/admin/newsletter', { status: props.filtros.status || undefined, busca: busca.value || undefined, ...extra }, { preserveState: true, preserveScroll: true });
const enviarTeste = () => router.post('/admin/newsletter/teste', {}, {
    preserveScroll: true,
    onStart: () => { enviandoTeste.value = true; },
    onFinish: () => { enviandoTeste.value = false; },
});

const data = (v) => (v ? new Date(v).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', dateStyle: 'short', timeStyle: 'short' }) : '-');
const selo = { ativo: 'bg-green-100 text-green-800', pendente: 'bg-amber-100 text-amber-800', descadastrado: 'bg-slate-100 text-slate-600' };
const nomeStatus = { ativo: 'Ativo', pendente: 'Aguardando confirmação', descadastrado: 'Saiu' };
const abas = [
    { valor: null, label: 'Todos' },
    { valor: 'ativo', label: `Ativos (${props.contagem.ativos ?? 0})` },
    { valor: 'pendente', label: `Aguardando (${props.contagem.pendentes ?? 0})` },
    { valor: 'descadastrado', label: `Saíram (${props.contagem.descadastrados ?? 0})` },
];
</script>

<template>
    <Head title="Newsletter" />

    <AdminLayout>
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-bold">Newsletter</h1>
            <div class="flex flex-wrap gap-2">
                <a href="/admin/newsletter/previa" target="_blank" class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                    <i class="fas fa-eye"></i> Ver e-mail do blog
                </a>
                <button type="button" :disabled="enviandoTeste" class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 disabled:opacity-50" @click="enviarTeste">
                    <i class="fas" :class="enviandoTeste ? 'fa-spinner animate-spin' : 'fa-paper-plane'"></i> Enviar teste para mim
                </button>
                <a href="/admin/newsletter/exportar" class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                    <i class="fas fa-file-csv"></i> Exportar CSV
                </a>
            </div>
        </div>
        <p class="mb-4 text-sm text-slate-500">
            Quem se inscreveu no bloco antes do rodapé da loja. Só recebe quem confirmou pelo e-mail (Ativo). "Enviar teste para mim" manda o
            resumo do blog só para o seu e-mail.
        </p>

        <div class="mb-4 grid gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-slate-200 p-4"><div class="text-xs text-slate-500">Ativos</div><div class="text-2xl font-bold">{{ contagem.ativos ?? 0 }}</div></div>
            <div class="rounded-lg border border-slate-200 p-4"><div class="text-xs text-slate-500">Aguardando confirmação</div><div class="text-2xl font-bold">{{ contagem.pendentes ?? 0 }}</div></div>
            <div class="rounded-lg border border-slate-200 p-4"><div class="text-xs text-slate-500">Saíram</div><div class="text-2xl font-bold">{{ contagem.descadastrados ?? 0 }}</div></div>
            <div class="rounded-lg border border-slate-200 p-4 text-sm">
                <div class="text-xs text-slate-500">E-mail automático do blog</div>
                <div class="mt-1 font-semibold" :class="blog.agendado ? 'text-green-700' : 'text-slate-600'">
                    {{ blog.agendado ? `Ligado (${blog.frequencia}, ${blog.hora})` : 'Desligado' }}
                </div>
                <div class="mt-1 text-xs text-slate-500">{{ blog.materias ? `${blog.materias} matéria(s) para o próximo resumo` : 'Nenhuma matéria no período ainda' }}</div>
            </div>
        </div>

        <div class="mb-3 flex flex-wrap items-center gap-3">
            <div class="flex gap-1 border-b border-slate-200">
                <button v-for="a in abas" :key="a.label" type="button"
                    :class="['-mb-px border-b-2 px-3 py-2 text-sm font-semibold', (filtros.status ?? null) === a.valor ? 'border-sky-600 text-sky-700' : 'border-transparent text-slate-500 hover:text-slate-700']"
                    @click="filtrar({ status: a.valor || undefined })">{{ a.label }}</button>
            </div>
            <form class="ml-auto flex gap-2" @submit.prevent="filtrar()">
                <input v-model="busca" type="search" placeholder="Buscar e-mail ou nome" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                <button type="submit" class="rounded-md border border-slate-300 px-3 py-1 text-sm hover:bg-slate-50">Buscar</button>
            </form>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr><th class="px-3 py-2">E-mail</th><th class="px-3 py-2">Nome</th><th class="px-3 py-2">Situação</th><th class="px-3 py-2">Inscrição</th><th class="px-3 py-2">Último envio</th><th class="px-3 py-2">Página</th></tr>
                </thead>
                <tbody>
                    <tr v-for="i in inscritos.data" :key="i.id" class="border-t border-slate-100">
                        <td class="break-all px-3 py-2">{{ i.email }}</td>
                        <td class="px-3 py-2">{{ i.nome || '-' }}</td>
                        <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="selo[i.status]">{{ nomeStatus[i.status] ?? i.status }}</span></td>
                        <td class="whitespace-nowrap px-3 py-2">{{ data(i.created_at) }}</td>
                        <td class="whitespace-nowrap px-3 py-2">{{ data(i.ultimo_envio_em) }}</td>
                        <td class="px-3 py-2 text-xs text-slate-500">{{ i.origem || '-' }}</td>
                    </tr>
                    <tr v-if="!inscritos.data?.length"><td colspan="6" class="px-3 py-8 text-center text-slate-500">Nenhum inscrito ainda.</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="inscritos.last_page > 1" class="mt-3 flex flex-wrap gap-1">
            <template v-for="l in inscritos.links" :key="l.label">
                <Link v-if="l.url" :href="l.url" preserve-scroll class="rounded border px-2.5 py-1 text-sm" :class="l.active ? 'border-sky-600 bg-sky-50 text-sky-700' : 'border-slate-200'"><span v-html="l.label"></span></Link>
            </template>
        </div>

        <div v-if="envios.length" class="mt-8">
            <h2 class="mb-2 text-lg font-semibold">Últimos envios do blog</h2>
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                <li v-for="e in envios" :key="e.id" class="flex flex-wrap justify-between gap-2 px-3 py-2">
                    <span>{{ e.assunto }}</span>
                    <span class="text-slate-500">{{ data(e.created_at) }} · {{ e.destinatarios }} inscrito(s) · {{ e.lotes }} lote(s)</span>
                </li>
            </ul>
        </div>
    </AdminLayout>
</template>
