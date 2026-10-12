<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import ServerPagination from '@/Shared/Components/ServerPagination.vue';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    visitas: { type: Object, default: () => ({ data: [], links: [] }) },
    totais: { type: Object, default: () => ({ visitas: 0, ips: 0, escondidas: 0 }) },
    filtros: { type: Object, default: () => ({}) },
    hoje: { type: String, default: '' },
    maxDias: { type: Number, default: 92 },
});

const DISPOSITIVOS = {
    celular: { label: 'Celular', icone: 'fa-mobile-screen' },
    tablet: { label: 'Tablet', icone: 'fa-tablet-screen-button' },
    computador: { label: 'Computador', icone: 'fa-desktop' },
};

const filtros = reactive({
    de: props.filtros.de ?? props.hoje,
    ate: props.filtros.ate ?? props.hoje,
    ip: props.filtros.ip ?? '',
    pagina: props.filtros.pagina ?? '',
    todos: Boolean(props.filtros.todos),
});

const buscar = () => {
    router.get('/admin/visitas-site', {
        de: filtros.de || undefined,
        ate: filtros.ate || undefined,
        ip: filtros.ip || undefined,
        pagina: filtros.pagina || undefined,
        todos: filtros.todos ? 1 : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const filtrarIp = (ip) => {
    filtros.ip = ip;
    buscar();
};

const periodo = (dias) => {
    const fim = new Date(`${props.hoje}T12:00:00`);
    const inicio = new Date(fim);
    inicio.setDate(inicio.getDate() - (dias - 1));
    const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    filtros.de = iso(inicio);
    filtros.ate = iso(fim);
    buscar();
};

const limpar = () => {
    filtros.de = props.hoje;
    filtros.ate = props.hoje;
    filtros.ip = '';
    filtros.pagina = '';
    filtros.todos = false;
    buscar();
};

const numero = (valor) => Number(valor ?? 0).toLocaleString('pt-BR');
</script>

<template>
    <Head title="Visitas ao site" />

    <AdminLayout>
        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-3xl">
                <h1 class="mb-1 text-2xl font-bold">Visitas ao site</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Cada página aberta no kazakora.com, com o IP de quem abriu, da mais recente para a mais antiga.
                    Horário de Brasília. Visitas com mais de 6 meses ficam sem IP e sem navegador (privacidade).
                </p>
            </div>
            <button type="button"
                class="rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]"
                @click="router.reload({ preserveScroll: true, preserveState: true })">
                <i class="fas fa-rotate-right mr-1" aria-hidden="true"></i> Atualizar
            </button>
        </div>

        <!-- Totais do período (com os filtros aplicados) -->
        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-3">
            <CardStats stat-subtitle="VISITAS (PÁGINAS ABERTAS)" :stat-title="numero(props.totais.visitas)" stat-icon-name="fas fa-eye" variant="primary" compact />
            <CardStats stat-subtitle="IPS DISTINTOS" :stat-title="numero(props.totais.ips)" stat-icon-name="fas fa-network-wired" variant="info" compact />
            <CardStats v-if="!props.filtros.todos" class="col-span-2 lg:col-span-1"
                stat-subtitle="ESCONDIDAS (EQUIPE E ROBÔS)" :stat-title="numero(props.totais.escondidas)" stat-icon-name="fas fa-user-shield" variant="secondary" compact />
        </div>

        <!-- Filtros -->
        <form class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" @submit.prevent="buscar">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label for="visitas-de" class="block text-xs font-medium text-slate-500 dark:text-slate-400">De</label>
                    <input id="visitas-de" v-model="filtros.de" type="date" :max="props.hoje"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm"
                        @change="buscar()">
                </div>
                <div>
                    <label for="visitas-ate" class="block text-xs font-medium text-slate-500 dark:text-slate-400">Até</label>
                    <input id="visitas-ate" v-model="filtros.ate" type="date" :max="props.hoje"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm"
                        @change="buscar()">
                </div>
                <div>
                    <label for="visitas-ip" class="block text-xs font-medium text-slate-500 dark:text-slate-400">IP</label>
                    <input id="visitas-ip" v-model="filtros.ip" type="search" inputmode="decimal" autocomplete="off" spellcheck="false"
                        placeholder="189.40.12.7 ou começo do IP…"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 font-mono text-sm">
                </div>
                <div>
                    <label for="visitas-pagina" class="block text-xs font-medium text-slate-500 dark:text-slate-400">Página contém</label>
                    <div class="mt-1 flex gap-2">
                        <input id="visitas-pagina" v-model="filtros.pagina" type="search" autocomplete="off" spellcheck="false"
                            placeholder="/produto, /carrinho…"
                            class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                        <button type="submit"
                            class="whitespace-nowrap rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                            <i class="fas fa-magnifying-glass mr-1" aria-hidden="true"></i> Buscar
                        </button>
                    </div>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-[var(--surface-border)] pt-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button type="button" class="rounded-full border border-[var(--surface-border)] px-3 py-1 text-xs font-medium text-slate-600 hover:bg-[var(--surface-muted)] dark:text-slate-300" @click="periodo(1)">Hoje</button>
                    <button type="button" class="rounded-full border border-[var(--surface-border)] px-3 py-1 text-xs font-medium text-slate-600 hover:bg-[var(--surface-muted)] dark:text-slate-300" @click="periodo(7)">7 dias</button>
                    <button type="button" class="rounded-full border border-[var(--surface-border)] px-3 py-1 text-xs font-medium text-slate-600 hover:bg-[var(--surface-muted)] dark:text-slate-300" @click="periodo(30)">30 dias</button>
                    <label class="ml-2 flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input v-model="filtros.todos" type="checkbox" class="rounded border-[var(--surface-border)]" @change="buscar()">
                        Mostrar equipe e robôs
                    </label>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-slate-500 dark:text-slate-400">Período máximo: {{ props.maxDias }} dias</span>
                    <button type="button" class="text-xs text-primary hover:underline" @click="limpar">Limpar filtros</button>
                </div>
            </div>
        </form>

        <!-- Lista -->
        <div class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-500 dark:text-slate-400">
                        <tr>
                            <th scope="col" class="px-4 py-3">Data/Hora</th>
                            <th scope="col" class="px-4 py-3">Página</th>
                            <th scope="col" class="px-4 py-3">IP</th>
                            <th scope="col" class="px-4 py-3">Cliente</th>
                            <th scope="col" class="px-4 py-3">Aparelho</th>
                            <th scope="col" class="px-4 py-3">Origem</th>
                            <th scope="col" class="px-4 py-3">Navegador</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--surface-border)]">
                        <tr v-for="visita in props.visitas.data" :key="visita.id" class="align-top hover:bg-[var(--surface-muted)]/50">
                            <td class="whitespace-nowrap px-4 py-2.5 tabular-nums text-slate-500 dark:text-slate-400">{{ visita.quando ?? '—' }}</td>
                            <td class="px-4 py-2.5">
                                <a :href="visita.url" target="_blank" rel="noopener noreferrer"
                                    class="block max-w-xs truncate font-mono text-xs text-[var(--foreground)] hover:text-primary hover:underline"
                                    :title="visita.path">{{ visita.path }}</a>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5">
                                <button v-if="visita.ip" type="button"
                                    class="font-mono text-xs text-primary hover:underline"
                                    :title="`Ver só as visitas do IP ${visita.ip}`"
                                    @click="filtrarIp(visita.ip)">{{ visita.ip }}</button>
                                <span v-else class="text-xs text-slate-400">—</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5">
                                <template v-if="visita.cliente">
                                    {{ visita.cliente }}
                                    <span v-if="visita.equipe" class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">equipe</span>
                                </template>
                                <span v-else class="text-xs text-slate-400">—</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-xs text-slate-600 dark:text-slate-300">
                                <template v-if="DISPOSITIVOS[visita.dispositivo]">
                                    <i class="fas mr-1" :class="DISPOSITIVOS[visita.dispositivo].icone" aria-hidden="true"></i>{{ DISPOSITIVOS[visita.dispositivo].label }}
                                </template>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="px-4 py-2.5 text-xs text-slate-600 dark:text-slate-300">
                                <span class="block max-w-[14rem] truncate" :title="visita.origem ?? 'Acesso direto'">{{ visita.origem ?? 'Direto' }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-xs text-slate-600 dark:text-slate-300">
                                <template v-if="visita.navegador">{{ visita.navegador }}</template>
                                <span v-else class="text-slate-400" title="Sem o OK de cookies o navegador não é gravado">sem OK de cookies</span>
                            </td>
                        </tr>

                        <tr v-if="props.visitas.data.length === 0">
                            <td colspan="7" class="px-4 py-10 text-center text-slate-500 dark:text-slate-400">
                                Nenhuma visita encontrada com esses filtros.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <ServerPagination :paginator="props.visitas" embedded />
        </div>
    </AdminLayout>
</template>
