<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import ServerPagination from '@/Shared/Components/ServerPagination.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

// Vídeos de embalagem (2026-10-13): tudo o que o KoraSync gravou, com
// filtro por período, canal, situação e pedido/cliente, e o vídeo tocando na própria tela.
const props = defineProps({
    videos: { type: Object, default: () => ({ data: [], links: [] }) },
    totais: { type: Object, default: () => ({ videos: 0, pedidos: 0, tamanho_mb: 0 }) },
    filtros: { type: Object, default: () => ({}) },
    canais: { type: Array, default: () => [] },
    diasGuardado: { type: Number, default: 90 },
});

const NOMES = { processando: 'Convertendo', pronto: 'Pronto', erro: 'Erro' };
const CORES = {
    processando: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    pronto: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    erro: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};
const CANAIS = { loja: 'Site', mercado_livre: 'Mercado Livre', shopee: 'Shopee', tiktok_shop: 'TikTok Shop', amazon: 'Amazon' };

const filtros = reactive({
    status: props.filtros.status ?? '',
    canal: props.filtros.canal ?? '',
    busca: props.filtros.busca ?? '',
    de: props.filtros.de ?? '',
    ate: props.filtros.ate ?? '',
});
const aberto = ref(null);

const buscar = () => {
    aberto.value = null;
    router.get('/admin/videos-embalagem', {
        status: filtros.status || undefined,
        canal: filtros.canal || undefined,
        busca: filtros.busca || undefined,
        de: filtros.de || undefined,
        ate: filtros.ate || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const alternar = (video) => {
    aberto.value = aberto.value === video.id ? null : video.id;
};

const numero = (valor) => Number(valor ?? 0).toLocaleString('pt-BR');
const dataHora = (valor) => (valor ? new Date(valor).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo' }) : '—');
const dia = (valor) => (valor ? new Date(valor).toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo' }) : '—');
const duracao = (s) => (s ? `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}` : '—');
</script>

<template>
    <Head title="Vídeos de embalagem" />

    <AdminLayout>
        <div class="mb-6 max-w-3xl">
            <h1 class="mb-1 text-2xl font-bold">Vídeos de embalagem</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Vídeos que o KoraSync grava enquanto o pedido é embalado, prova do que foi para a caixa.
                Cada vídeo fica guardado {{ props.diasGuardado }} dias. Clique na linha para assistir. Horário de Brasília.
            </p>
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-3">
            <CardStats stat-subtitle="VÍDEOS" :stat-title="numero(props.totais.videos)" stat-icon-name="fas fa-video" variant="primary" compact />
            <CardStats stat-subtitle="PEDIDOS" :stat-title="numero(props.totais.pedidos)" stat-icon-name="fas fa-box" variant="info" compact />
            <CardStats stat-subtitle="ESPAÇO" :stat-title="`${numero(props.totais.tamanho_mb)} MB`" stat-icon-name="fas fa-hard-drive" variant="info" compact />
        </div>

        <form class="mb-4 grid grid-cols-1 gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:grid-cols-3 lg:grid-cols-6" @submit.prevent="buscar">
            <div>
                <label for="ve-de" class="block text-xs font-medium text-slate-500 dark:text-slate-400">Gravado de</label>
                <input id="ve-de" v-model="filtros.de" type="date" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm" @change="buscar()">
            </div>
            <div>
                <label for="ve-ate" class="block text-xs font-medium text-slate-500 dark:text-slate-400">Até</label>
                <input id="ve-ate" v-model="filtros.ate" type="date" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm" @change="buscar()">
            </div>
            <div>
                <label for="ve-canal" class="block text-xs font-medium text-slate-500 dark:text-slate-400">Canal</label>
                <select id="ve-canal" v-model="filtros.canal" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm" @change="buscar()">
                    <option value="">Todos</option>
                    <option v-for="c in props.canais" :key="c" :value="c">{{ CANAIS[c] ?? c }}</option>
                </select>
            </div>
            <div>
                <label for="ve-status" class="block text-xs font-medium text-slate-500 dark:text-slate-400">Situação</label>
                <select id="ve-status" v-model="filtros.status" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm" @change="buscar()">
                    <option value="">Todas</option>
                    <option v-for="(nome, s) in NOMES" :key="s" :value="s">{{ nome }}</option>
                </select>
            </div>
            <div class="sm:col-span-3 lg:col-span-2">
                <label for="ve-busca" class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pedido, número do canal ou cliente</label>
                <div class="mt-1 flex gap-2">
                    <input id="ve-busca" v-model="filtros.busca" type="search" autocomplete="off"
                        class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                    <button type="submit" class="whitespace-nowrap rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                        <i class="fas fa-magnifying-glass mr-1" aria-hidden="true"></i> Buscar
                    </button>
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-500 dark:text-slate-400">
                        <tr>
                            <th scope="col" class="px-4 py-3">Gravado em</th>
                            <th scope="col" class="px-4 py-3">Pedido</th>
                            <th scope="col" class="px-4 py-3">Cliente</th>
                            <th scope="col" class="px-4 py-3 text-right">Duração</th>
                            <th scope="col" class="px-4 py-3 text-right">Tamanho</th>
                            <th scope="col" class="px-4 py-3">Situação</th>
                            <th scope="col" class="px-4 py-3">Guardado até</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--surface-border)]">
                        <template v-for="video in props.videos.data" :key="video.id">
                            <tr class="cursor-pointer hover:bg-[var(--surface-muted)]/50" @click="alternar(video)">
                                <td class="whitespace-nowrap px-4 py-2.5 tabular-nums text-slate-500 dark:text-slate-400">{{ dataHora(video.gravado_em) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <Link v-if="video.pedido" :href="`/admin/pedidos/${video.pedido.id}`" class="font-medium text-primary" @click.stop>#{{ video.pedido.id }}</Link>
                                    <div v-if="video.pedido" class="text-xs text-slate-500">
                                        {{ CANAIS[video.pedido.canal] ?? video.pedido.canal }}<span v-if="video.pedido.numero"> · {{ video.pedido.numero }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5">
                                    {{ video.pedido?.cliente ?? '—' }}
                                    <div v-if="video.pedido?.apelido" class="text-xs text-slate-500">{{ video.pedido.apelido }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">{{ duracao(video.duracao) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">
                                    {{ video.tamanho_mb ? `${video.tamanho_mb} MB` : '—' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <span :class="['rounded-full px-2.5 py-1 text-xs font-medium', CORES[video.status]]">{{ NOMES[video.status] ?? video.status }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 tabular-nums text-slate-500 dark:text-slate-400">{{ dia(video.expira_em) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                    <button type="button" class="mr-3 font-semibold text-primary" :aria-expanded="aberto === video.id" @click.stop="alternar(video)">
                                        <i :class="['fas mr-1', aberto === video.id ? 'fa-xmark' : 'fa-play']" aria-hidden="true"></i>{{ aberto === video.id ? 'Fechar' : 'Assistir' }}
                                    </button>
                                    <a :href="video.download" class="font-semibold text-primary" @click.stop><i class="fas fa-download mr-1" aria-hidden="true"></i>Baixar</a>
                                </td>
                            </tr>
                            <tr v-if="aberto === video.id">
                                <td colspan="8" class="bg-[var(--surface-muted)]/40 px-4 py-4">
                                    <p v-if="video.status === 'processando'" class="mb-2 text-sm text-slate-500"><i class="fas fa-circle-notch animate-spin mr-1" aria-hidden="true"></i> Ainda convertendo; o original pode não tocar em todo navegador.</p>
                                    <p v-if="video.status === 'erro'" class="mb-2 text-sm text-red-600 dark:text-red-400">Erro na conversão: {{ video.erro ?? 'sem detalhe' }}</p>
                                    <video :src="video.url" controls autoplay preload="metadata" class="w-full max-w-2xl rounded-lg bg-black"></video>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="props.videos.data.length === 0">
                            <td colspan="8" class="px-4 py-10 text-center text-slate-500 dark:text-slate-400">Nenhum vídeo de embalagem encontrado.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ServerPagination :paginator="props.videos" embedded />
        </div>
    </AdminLayout>
</template>
