<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
import ServerPagination from '@/Shared/Components/ServerPagination.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';

const props = defineProps({
    visitas: { type: Object, default: () => ({ data: [], links: [] }) },
    totais: { type: Object, default: () => ({ visitas: 0, ips: 0, visitantes: 0, escondidas: 0 }) },
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

// Período e visitante no formato das rotas do olho e da lixeira.
const alvo = (linha) => ({
    de: props.filtros.de,
    ate: props.filtros.ate,
    ...(linha.ip ? { ip: linha.ip } : { visitor_id: linha.visitor_id }),
});
const nomeAlvo = (linha) => (linha.ip ? `do IP ${linha.ip}` : 'do visitante anonimizado');
const diaMes = (iso) => (iso ? `${iso.slice(8, 10)}/${iso.slice(5, 7)}` : '');
const periodoTexto = computed(() => `${diaMes(props.filtros.de)} a ${diaMes(props.filtros.ate)}`);

const tituloCliente = (linha) => {
    if (!linha.cliente) return 'Ninguém logado nessas visitas';
    return linha.equipe ? `Logado como ${linha.cliente} (equipe)` : `Logado como ${linha.cliente}`;
};

// Olho: histórico do visitante no período, carregado sob demanda.
const detalhe = reactive({ aberto: false, carregando: false, erro: '', linha: null, dados: null });

const abrirDetalhe = async (linha) => {
    Object.assign(detalhe, { aberto: true, carregando: true, erro: '', linha, dados: null });
    document.body.style.overflow = 'hidden';
    const query = new URLSearchParams({ ...alvo(linha), ...(props.filtros.todos ? { todos: 1 } : {}) });
    try {
        const r = await fetch(`/admin/visitas-site/visitante?${query}`, {
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
    router.delete('/admin/visitas-site/visitante', {
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
    <Head title="Visitas ao site" />

    <AdminLayout>
        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-3xl">
                <h1 class="mb-1 text-2xl font-bold">Visitas ao site</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Quem visitou o kazakora.com, uma linha por IP no período, com a hora do primeiro acesso, do mais recente para o mais antigo.
                    O olho mostra todas as páginas que a pessoa abriu. Horário de Brasília. Visitas com mais de 6 meses ficam sem IP e sem navegador (privacidade).
                </p>
            </div>
            <button type="button"
                class="rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]"
                @click="router.reload({ preserveScroll: true, preserveState: true })">
                <i class="fas fa-rotate-right mr-1" aria-hidden="true"></i> Atualizar
            </button>
        </div>

        <!-- Totais do período (com os filtros aplicados) -->
        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <CardStats stat-subtitle="VISITANTES (LINHAS)" :stat-title="numero(props.totais.visitantes)" stat-icon-name="fas fa-users" variant="primary" compact />
            <CardStats stat-subtitle="PÁGINAS ABERTAS" :stat-title="numero(props.totais.visitas)" stat-icon-name="fas fa-eye" variant="info" compact />
            <CardStats stat-subtitle="IPS DISTINTOS" :stat-title="numero(props.totais.ips)" stat-icon-name="fas fa-network-wired" variant="info" compact />
            <CardStats v-if="!props.filtros.todos"
                stat-subtitle="EQUIPE E ROBÔS" :stat-title="numero(props.totais.escondidas)" stat-icon-name="fas fa-user-shield" variant="secondary" compact />
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
                                <div class="flex justify-end gap-1.5">
                                    <ActionIcon icon="fa-eye" label="Ver as páginas que abriu" color="slate" @click="abrirDetalhe(visita)" />
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

        <!-- Olho: histórico do visitante no período. Sem Teleport: as cores do
             admin (--surface etc.) só valem dentro do .admin-shell. -->
        <div v-if="detalhe.aberto" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="fecharDetalhe"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="visitante-titulo"
                class="relative flex w-full max-w-5xl flex-col rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-xl"
                style="max-height: 90vh;">
                <div class="flex items-start justify-between gap-3 border-b border-[var(--surface-border)] px-6 py-4">
                    <div>
                        <h2 id="visitante-titulo" class="text-lg font-semibold">
                            Páginas abertas {{ detalhe.linha ? nomeAlvo(detalhe.linha) : '' }}
                        </h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            {{ periodoTexto }}<template v-if="detalhe.dados">, {{ numero(detalhe.dados.total) }} {{ detalhe.dados.total === 1 ? 'página' : 'páginas' }}, em ordem de horário</template>
                            <template v-if="detalhe.linha && !detalhe.linha.ip"> (cookie <span class="font-mono text-xs">{{ detalhe.linha.visitor_id }}</span>)</template>
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
                    <table v-else-if="detalhe.dados" class="w-full text-left text-sm">
                        <thead class="sticky top-0 border-b border-[var(--surface-border)] bg-[var(--surface)] text-xs uppercase text-slate-500 dark:text-slate-400">
                            <tr>
                                <th scope="col" class="px-4 py-3">Horário</th>
                                <th scope="col" class="px-4 py-3">Página</th>
                                <th scope="col" class="px-4 py-3">Aparelho</th>
                                <th scope="col" class="px-4 py-3">Origem</th>
                                <th scope="col" class="px-4 py-3">Navegador</th>
                                <th scope="col" class="px-4 py-3">Cliente</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--surface-border)]">
                            <tr v-for="v in detalhe.dados.visitas" :key="v.id" class="align-top">
                                <td class="whitespace-nowrap px-4 py-2 tabular-nums text-slate-500 dark:text-slate-400">{{ v.quando }}</td>
                                <td class="px-4 py-2">
                                    <a :href="v.url" target="_blank" rel="noopener noreferrer"
                                        class="block max-w-xs truncate font-mono text-xs text-[var(--foreground)] hover:text-primary hover:underline"
                                        :title="v.path">{{ v.path }}</a>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-xs text-slate-600 dark:text-slate-300">
                                    <template v-if="DISPOSITIVOS[v.dispositivo]">
                                        <i class="fas mr-1" :class="DISPOSITIVOS[v.dispositivo].icone" aria-hidden="true"></i>{{ DISPOSITIVOS[v.dispositivo].label }}
                                    </template>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td class="px-4 py-2 text-xs text-slate-600 dark:text-slate-300">
                                    <span class="block max-w-[14rem] truncate" :title="v.origem ?? 'Acesso direto'">{{ v.origem ?? 'Direto' }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-xs text-slate-600 dark:text-slate-300">
                                    <template v-if="v.navegador">{{ v.navegador }}</template>
                                    <span v-else class="text-slate-400" title="Sem o OK de cookies o navegador não é gravado">sem OK de cookies</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-xs">
                                    <template v-if="v.cliente">
                                        {{ v.cliente }}
                                        <span v-if="v.equipe" class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">equipe</span>
                                    </template>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                            </tr>
                            <tr v-if="detalhe.dados.visitas.length === 0">
                                <td colspan="6" class="px-4 py-10 text-center text-slate-500 dark:text-slate-400">Nenhuma página nesse período.</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="detalhe.dados && detalhe.dados.total > detalhe.dados.visitas.length"
                        class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                        Mostrando as primeiras {{ numero(detalhe.dados.visitas.length) }} de {{ numero(detalhe.dados.total) }} páginas.
                    </p>
                </div>
            </div>
        </div>

        <!-- Lixeira: confirmação antes de apagar -->
        <ConfirmModal :open="paraApagar !== null" title="Apagar visitas" confirm-label="Apagar" danger
            :loading="apagando" @close="paraApagar = null" @confirm="confirmarApagar">
            <p v-if="paraApagar" class="text-sm">
                Apagar <strong>{{ numero(paraApagar.apagaveis) }} {{ paraApagar.apagaveis === 1 ? 'visita' : 'visitas' }}</strong>
                {{ nomeAlvo(paraApagar) }}
                de <strong>{{ periodoTexto }}</strong>?
            </p>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                Sai tudo desse {{ paraApagar?.ip ? 'IP' : 'visitante' }} no período, inclusive páginas escondidas pelos filtros, e some também da contagem do painel desses dias.
                Visitas fora do período ficam. Não dá para desfazer.
            </p>
        </ConfirmModal>
    </AdminLayout>
</template>
