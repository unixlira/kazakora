<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

// Logística › Rastreio (2026-10-19): objetos dos Correios acompanhados pelo
// SRO Rastro e a linha do tempo de cada um. Só leitura.
const props = defineProps({
    rastreios: { type: Object, required: true },
    filtros: { type: Object, default: () => ({ situacao: '', busca: '' }) },
    situacoes: { type: Object, default: () => ({}) },
    contagem: { type: Object, default: () => ({}) },
    abertos: { type: Number, default: 0 },
    whatsappLigado: { type: Boolean, default: false },
});

const situacao = ref(props.filtros.situacao || '');
const busca = ref(props.filtros.busca || '');
const expandidos = ref({});

const CANAIS = { loja: 'Site', amazon: 'Amazon', mercado_livre: 'Mercado Livre', shopee: 'Shopee', tiktok_shop: 'TikTok' };

const COR = {
    entregue: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    saiu_para_entrega: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    aguardando_retirada: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    nao_entregue: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    em_devolucao: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    devolvido: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    erro: 'bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
};
const cor = (s) => COR[s] || 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200';

const dataHora = (v) => (v ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Sao_Paulo' }).format(new Date(v)) : '—');
const data = (v) => (v ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeZone: 'UTC' }).format(new Date(`${v}T00:00:00Z`)) : '—');
const total = (s) => Number(props.contagem?.[s] || 0);

function filtrar() {
    router.get('/admin/logistica/rastreio', { situacao: situacao.value || undefined, busca: busca.value || undefined }, { preserveState: true, replace: true });
}

function alternar(id) {
    expandidos.value = { ...expandidos.value, [id]: !expandidos.value[id] };
}
</script>

<template>
    <Head title="Rastreio" />

    <AdminLayout>
        <div class="mb-2 flex items-center justify-between">
            <h1 class="text-2xl font-bold">Rastreio</h1>
        </div>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            Objetos dos Correios acompanhados pelo rastreio oficial (SRO). A consulta roda sozinha a cada 5 minutos e para quando o pacote é entregue ou volta para a loja.
            <span v-if="!whatsappLigado" class="font-medium text-amber-700 dark:text-amber-300">Avisos de WhatsApp ao cliente desligados neste ambiente.</span>
        </p>

        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <CardStats compact stat-subtitle="EM ANDAMENTO" :stat-title="String(abertos)" stat-icon-name="fas fa-route" variant="primary" />
            <CardStats compact stat-subtitle="SAIU PARA ENTREGA" :stat-title="String(total('saiu_para_entrega'))" stat-icon-name="fas fa-truck-fast" variant="primary" />
            <CardStats compact stat-subtitle="ENTREGUES" :stat-title="String(total('entregue'))" stat-icon-name="fas fa-circle-check" variant="success" />
            <CardStats compact stat-subtitle="DEVOLVIDOS OU NÃO ENTREGUES" :stat-title="String(total('devolvido') + total('em_devolucao') + total('nao_entregue'))" stat-icon-name="fas fa-rotate-left" variant="error" />
        </div>

        <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="filtrar">
            <label class="block text-sm">
                <span class="font-medium">Situação</span>
                <select v-model="situacao" class="mt-1 block rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm" @change="filtrar">
                    <option value="">Todas</option>
                    <option value="abertos">Em andamento</option>
                    <option v-for="(rotulo, chave) in situacoes" :key="chave" :value="chave">{{ rotulo }}</option>
                </select>
            </label>
            <label class="block text-sm">
                <span class="font-medium">Código ou pedido</span>
                <input v-model="busca" type="search" placeholder="AP629988851BR ou 3274" autocomplete="off" spellcheck="false"
                    class="mt-1 block w-64 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
            </label>
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                <i class="fas fa-magnifying-glass" aria-hidden="true"></i>Buscar
            </button>
        </form>

        <div class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <p v-if="!rastreios.data.length" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">
                Nenhum objeto acompanhado ainda.
            </p>
            <ul v-else class="divide-y divide-[var(--surface-border)]">
                <li v-for="r in rastreios.data" :key="r.id">
                    <button type="button" class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-left hover:bg-[var(--surface-muted)]"
                        :aria-expanded="!!expandidos[r.id]" @click="alternar(r.id)">
                        <i :class="expandidos[r.id] ? 'fas fa-chevron-down' : 'fas fa-chevron-right'" class="w-3 text-xs text-slate-400" aria-hidden="true"></i>
                        <span class="font-mono text-sm font-semibold">{{ r.codigo }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="cor(r.situacao)">{{ r.situacao_rotulo || 'Sem consulta' }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm text-slate-600 dark:text-slate-300">{{ r.ultimo_evento || '—' }}</span>
                        <span class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ dataHora(r.ultimo_evento_em) }}</span>
                    </button>
                    <div v-if="expandidos[r.id]" class="border-t border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3 text-sm">
                        <dl class="mb-3 grid grid-cols-2 gap-x-6 gap-y-1 sm:grid-cols-4">
                            <div>
                                <dt class="text-xs text-slate-500 dark:text-slate-400">Pedido</dt>
                                <dd>
                                    <Link v-if="r.pedido" :href="`/admin/pedidos/${r.pedido.id}`" class="font-medium text-primary hover:underline">#{{ r.pedido.numero }}</Link>
                                    <span v-else>—</span>
                                    <span v-if="r.pedido" class="text-slate-500"> · {{ CANAIS[r.pedido.canal] || r.pedido.canal }}</span>
                                </dd>
                            </div>
                            <div><dt class="text-xs text-slate-500 dark:text-slate-400">Destino</dt><dd>{{ r.pedido?.destino || '—' }}</dd></div>
                            <div><dt class="text-xs text-slate-500 dark:text-slate-400">Postado</dt><dd class="tabular-nums">{{ dataHora(r.postado_em) }}</dd></div>
                            <div><dt class="text-xs text-slate-500 dark:text-slate-400">{{ r.entregue_em ? 'Entregue' : 'Previsão' }}</dt><dd class="tabular-nums">{{ r.entregue_em ? dataHora(r.entregue_em) : data(r.previsao_entrega) }}</dd></div>
                        </dl>
                        <p v-if="r.ultimo_erro" role="alert" class="mb-3 text-red-700 dark:text-red-300">{{ r.ultimo_erro }}</p>
                        <ol class="space-y-2">
                            <li v-for="e in r.eventos" :key="e.id" class="grid grid-cols-[8.5rem_minmax(0,1fr)] gap-3">
                                <span class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ dataHora(e.quando) }}</span>
                                <span>
                                    <span class="font-medium">{{ e.descricao }}</span>
                                    <span class="ml-1 font-mono text-xs text-slate-400">{{ e.codigo }}</span>
                                    <span v-if="e.local" class="block text-xs text-slate-500 dark:text-slate-400">{{ e.local }}<template v-if="e.destino"> → {{ e.destino }}</template></span>
                                </span>
                            </li>
                        </ol>
                        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Última consulta: {{ dataHora(r.consultado_em) }}</p>
                    </div>
                </li>
            </ul>

            <div v-if="rastreios.links && rastreios.links.length > 3" class="flex flex-wrap items-center justify-center gap-1 border-t border-[var(--surface-border)] px-4 py-3">
                <template v-for="(link, index) in rastreios.links" :key="index">
                    <Link v-if="link.url" :href="link.url" preserve-scroll preserve-state
                        class="rounded-lg px-3 py-1.5 text-sm"
                        :class="link.active ? 'bg-primary text-white' : 'text-slate-500 hover:bg-[var(--surface-muted)]'"
                        v-html="link.label" />
                    <span v-else class="rounded-lg px-3 py-1.5 text-sm text-slate-300" v-html="link.label"></span>
                </template>
            </div>
        </div>
    </AdminLayout>
</template>
