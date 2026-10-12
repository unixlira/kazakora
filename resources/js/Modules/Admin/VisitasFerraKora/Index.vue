<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
import ServerPagination from '@/Shared/Components/ServerPagination.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';

// Visitas FerraKora (2026-10-12): mesmo layout de Visitas KazaKora, só com
// Data/Hora, IP, Cliente e Ações.
const props = defineProps({
    visitas: { type: Object, default: () => ({ data: [], links: [] }) },
    totais: { type: Object, default: () => ({ visitas: 0, ips: 0, visitantes: 0 }) },
    filtros: { type: Object, default: () => ({}) },
    hoje: { type: String, default: '' },
    maxDias: { type: Number, default: 92 },
});

const filtros = reactive({
    de: props.filtros.de ?? props.hoje,
    ate: props.filtros.ate ?? props.hoje,
    ip: props.filtros.ip ?? '',
});

const buscar = () => {
    router.get('/admin/visitas-ferrakora', {
        de: filtros.de || undefined,
        ate: filtros.ate || undefined,
        ip: filtros.ip || undefined,
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
    buscar();
};

const numero = (valor) => Number(valor ?? 0).toLocaleString('pt-BR');

const alvo = (linha) => ({ de: props.filtros.de, ate: props.filtros.ate, ip: linha.ip });
const diaMes = (iso) => (iso ? `${iso.slice(8, 10)}/${iso.slice(5, 7)}` : '');
const periodoTexto = computed(() => `${diaMes(props.filtros.de)} a ${diaMes(props.filtros.ate)}`);

const tituloCliente = (linha) => (linha.cliente
    ? `IP de ${linha.cliente}, cliente do kazakora.com`
    : 'IP sem cliente logado no kazakora.com nos últimos 6 meses');

// Olho: horários das páginas abertas pelo IP no período.
const detalhe = reactive({ aberto: false, carregando: false, erro: '', linha: null, dados: null });

const abrirDetalhe = async (linha) => {
    Object.assign(detalhe, { aberto: true, carregando: true, erro: '', linha, dados: null });
    document.body.style.overflow = 'hidden';
    try {
        const r = await fetch(`/admin/visitas-ferrakora/visitante?${new URLSearchParams(alvo(linha))}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const dados = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(dados.message || 'Não foi possível carregar o histórico.');
        if (detalhe.linha === linha) detalhe.dados = dados;
    } catch (e) {
        if (detalhe.linha === linha) detalhe.erro = e.message;
    } finally {
        if (detalhe.linha === linha) detalhe.carregando = false;
    }
};

const fecharDetalhe = () => {
    detalhe.aberto = false;
    detalhe.linha = null;
    document.body.style.overflow = '';
};

const onKeydown = (event) => {
    if (event.key === 'Escape' && detalhe.aberto) fecharDetalhe();
};
onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});

// Lixeira: apaga as visitas do IP no período, só depois de confirmar.
const apagando = ref(false);
const paraApagar = ref(null);

const confirmarApagar = () => {
    const linha = paraApagar.value;
    if (!linha) return;
    router.delete('/admin/visitas-ferrakora/visitante', {
        data: alvo(linha),
        preserveScroll: true,
        onStart: () => { apagando.value = true; },
        onFinish: () => {
            apagando.value = false;
            paraApagar.value = null;
        },
    });
};
</script>

<template>
    <Head title="Visitas FerraKora" />

    <AdminLayout>
        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-3xl">
                <h1 class="mb-1 text-2xl font-bold">Visitas FerraKora</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Quem visitou o site FerraKora, uma linha por IP no período, com a hora do primeiro acesso, do mais recente para o mais antigo.
                    Cliente "Sim" é o IP de quem já entrou logado como cliente no kazakora.com. Horário de Brasília. Visitas com mais de 6 meses ficam sem IP (privacidade).
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
            <CardStats stat-subtitle="VISITANTES (LINHAS)" :stat-title="numero(props.totais.visitantes)" stat-icon-name="fas fa-users" variant="primary" compact />
            <CardStats stat-subtitle="PÁGINAS ABERTAS" :stat-title="numero(props.totais.visitas)" stat-icon-name="fas fa-eye" variant="info" compact />
            <CardStats stat-subtitle="IPS DISTINTOS" :stat-title="numero(props.totais.ips)" stat-icon-name="fas fa-network-wired" variant="info" compact />
        </div>

        <!-- Filtros -->
        <form class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" @submit.prevent="buscar">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                    <label for="ferrakora-de" class="block text-xs font-medium text-slate-500 dark:text-slate-400">De</label>
                    <input id="ferrakora-de" v-model="filtros.de" type="date" :max="props.hoje"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm"
                        @change="buscar()">
                </div>
                <div>
                    <label for="ferrakora-ate" class="block text-xs font-medium text-slate-500 dark:text-slate-400">Até</label>
                    <input id="ferrakora-ate" v-model="filtros.ate" type="date" :max="props.hoje"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm"
                        @change="buscar()">
                </div>
                <div>
                    <label for="ferrakora-ip" class="block text-xs font-medium text-slate-500 dark:text-slate-400">IP</label>
                    <div class="mt-1 flex gap-2">
                        <input id="ferrakora-ip" v-model="filtros.ip" type="search" inputmode="decimal" autocomplete="off" spellcheck="false"
                            placeholder="189.40.12.7 ou começo do IP…"
                            class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 font-mono text-sm">
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
                            <th scope="col" class="px-4 py-3">IP</th>
                            <th scope="col" class="px-4 py-3">Cliente</th>
                            <th scope="col" class="px-4 py-3 text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--surface-border)]">
                        <tr v-for="visita in props.visitas.data" :key="visita.chave" class="hover:bg-[var(--surface-muted)]/50">
                            <td class="whitespace-nowrap px-4 py-2.5 tabular-nums text-slate-500 dark:text-slate-400">{{ visita.quando ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5">
                                <button v-if="visita.ip" type="button"
                                    class="font-mono text-xs text-primary hover:underline"
                                    :title="`Ver só as visitas do IP ${visita.ip}`"
                                    @click="filtrarIp(visita.ip)">{{ visita.ip }}</button>
                                <span v-else class="text-xs italic text-slate-400" title="IP apagado depois de 6 meses (privacidade)">anonimizado</span>
                                <span class="ml-2 text-xs tabular-nums text-slate-400">{{ visita.paginas }} {{ visita.paginas === 1 ? 'página' : 'páginas' }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5">
                                <span v-if="visita.cliente" :title="tituloCliente(visita)"
                                    class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/40 dark:text-green-300">Sim</span>
                                <span v-else :title="tituloCliente(visita)"
                                    class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400">Não</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5">
                                <div v-if="visita.ip" class="flex justify-end gap-1.5">
                                    <ActionIcon icon="fa-eye" label="Ver os horários das visitas" color="slate" @click="abrirDetalhe(visita)" />
                                    <ActionIcon icon="fa-trash" label="Apagar as visitas do período" color="red" @click="paraApagar = visita" />
                                </div>
                            </td>
                        </tr>

                        <tr v-if="props.visitas.data.length === 0">
                            <td colspan="4" class="px-4 py-10 text-center text-slate-500 dark:text-slate-400">
                                Nenhuma visita encontrada com esses filtros.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <ServerPagination :paginator="props.visitas" embedded />
        </div>

        <!-- Olho: horários das visitas do IP no período. Sem Teleport: as cores
             do admin (--surface etc.) só valem dentro do .admin-shell. -->
        <div v-if="detalhe.aberto" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="fecharDetalhe"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="ferrakora-visitante-titulo"
                class="relative flex w-full max-w-lg flex-col rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-xl"
                style="max-height: 90vh;">
                <div class="flex items-start justify-between gap-3 border-b border-[var(--surface-border)] px-6 py-4">
                    <div>
                        <h2 id="ferrakora-visitante-titulo" class="text-lg font-semibold">
                            Visitas do IP {{ detalhe.linha?.ip }}
                        </h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            {{ periodoTexto }}<template v-if="detalhe.dados">, {{ numero(detalhe.dados.total) }} {{ detalhe.dados.total === 1 ? 'página' : 'páginas' }}, em ordem de horário</template>
                        </p>
                    </div>
                    <button type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 hover:bg-[var(--surface-muted)]"
                        aria-label="Fechar" @click="fecharDetalhe">
                        <i class="fas fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="overflow-auto px-2 pb-2">
                    <p v-if="detalhe.carregando" class="px-4 py-10 text-center text-slate-500 dark:text-slate-400">
                        <i class="fas fa-spinner fa-spin mr-1.5" aria-hidden="true"></i> Carregando…
                    </p>
                    <p v-else-if="detalhe.erro" class="px-4 py-10 text-center text-red-600">{{ detalhe.erro }}</p>
                    <table v-if="detalhe.dados && !detalhe.carregando && !detalhe.erro" class="w-full text-left text-sm">
                        <thead class="sticky top-0 border-b border-[var(--surface-border)] bg-[var(--surface)] text-xs uppercase text-slate-500 dark:text-slate-400">
                            <tr>
                                <th scope="col" class="px-4 py-3">Data/Hora</th>
                                <th scope="col" class="px-4 py-3">Cliente</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--surface-border)]">
                            <tr v-for="v in detalhe.dados.visitas" :key="v.id">
                                <td class="whitespace-nowrap px-4 py-2 tabular-nums text-slate-500 dark:text-slate-400">{{ v.quando }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-xs">
                                    <span v-if="detalhe.dados.cliente"
                                        class="rounded-full bg-green-100 px-2.5 py-0.5 font-medium text-green-700 dark:bg-green-900/40 dark:text-green-300">Sim · {{ detalhe.dados.cliente }}</span>
                                    <span v-else class="rounded-full bg-slate-100 px-2.5 py-0.5 font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400">Não</span>
                                </td>
                            </tr>
                            <tr v-if="detalhe.dados.visitas.length === 0">
                                <td colspan="2" class="px-4 py-10 text-center text-slate-500 dark:text-slate-400">Nenhuma visita nesse período.</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="detalhe.dados && detalhe.dados.total > detalhe.dados.visitas.length"
                        class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                        Mostrando as primeiras {{ numero(detalhe.dados.visitas.length) }} de {{ numero(detalhe.dados.total) }} visitas.
                    </p>
                </div>
            </div>
        </div>

        <!-- Lixeira: confirmação antes de apagar -->
        <ConfirmModal :open="paraApagar !== null" title="Apagar visitas" confirm-label="Apagar" danger
            :loading="apagando" @close="paraApagar = null" @confirm="confirmarApagar">
            <p v-if="paraApagar" class="text-sm">
                Apagar <strong>{{ numero(paraApagar.paginas) }} {{ paraApagar.paginas === 1 ? 'visita' : 'visitas' }}</strong>
                do IP {{ paraApagar.ip }} de <strong>{{ periodoTexto }}</strong>?
            </p>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                Visitas fora do período ficam. Não dá para desfazer.
            </p>
        </ConfirmModal>
    </AdminLayout>
</template>
