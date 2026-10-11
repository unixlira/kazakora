<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';

const props = defineProps({
    logs: { type: Array, default: () => [] },
    arquivos: { type: Array, default: () => [] },
    niveis: { type: Array, default: () => [] },
    filtros: { type: Object, default: () => ({}) },
    paginacao: { type: Object, default: () => ({ pagina: 1, porPagina: 50, temProxima: false }) },
    varredura: { type: Object, default: () => ({ arquivos: 0, lidos: '0 B', parcial: false }) },
});

const NIVEL_CORES = {
    emergency: 'bg-red-600 text-white',
    alert: 'bg-red-600 text-white',
    critical: 'bg-red-600 text-white',
    error: 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300',
    warning: 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300',
    notice: 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300',
    info: 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300',
    debug: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};

const filtros = reactive({
    arquivo: props.filtros.arquivo ?? '',
    niveis: [...(props.filtros.niveis ?? [])],
    de: props.filtros.de ?? '',
    ate: props.filtros.ate ?? '',
    q: props.filtros.q ?? '',
    porPagina: props.filtros.porPagina ?? 50,
});

const buscar = (pagina = 1) => {
    router.get('/admin/logs', {
        arquivo: filtros.arquivo || undefined,
        niveis: filtros.niveis.length ? filtros.niveis : undefined,
        de: filtros.de || undefined,
        ate: filtros.ate || undefined,
        q: filtros.q || undefined,
        porPagina: filtros.porPagina !== 50 ? filtros.porPagina : undefined,
        pagina: pagina > 1 ? pagina : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const alternarNivel = (nivel) => {
    const posicao = filtros.niveis.indexOf(nivel);
    if (posicao === -1) {
        filtros.niveis.push(nivel);
    } else {
        filtros.niveis.splice(posicao, 1);
    }
    buscar();
};

const soErros = () => {
    filtros.niveis = ['error', 'critical', 'alert', 'emergency'];
    buscar();
};

const limpar = () => {
    filtros.arquivo = '';
    filtros.niveis = [];
    filtros.de = '';
    filtros.ate = '';
    filtros.q = '';
    buscar();
};

// Log é tela de acompanhar: a atualização automática evita ficar apertando
// F5 enquanto se espera o erro acontecer de novo. Só recarrega quando está
// na primeira página — em página 2 o conteúdo escorregaria embaixo do dedo.
const autoAtualizar = ref(false);
let cronometro = null;

const pararAuto = () => {
    if (cronometro) {
        clearInterval(cronometro);
        cronometro = null;
    }
};

watch(autoAtualizar, (ligado) => {
    pararAuto();
    if (ligado) {
        cronometro = setInterval(() => {
            router.reload({ preserveScroll: true, preserveState: true });
        }, 10000);
    }
});

watch(() => props.paginacao.pagina, (pagina) => {
    if (pagina > 1) {
        autoAtualizar.value = false;
    }
});

onBeforeUnmount(pararAuto);

const expandido = ref(null);
const alternarDetalhe = (id) => {
    expandido.value = expandido.value === id ? null : id;
};

const copiado = ref(null);

const copiar = async (log) => {
    const texto = [log.dataHora, log.arquivo, log.nivelLabel, log.mensagem, log.detalhe].filter(Boolean).join('\n');
    try {
        await navigator.clipboard.writeText(texto);
        copiado.value = log.id;
        setTimeout(() => { copiado.value = null; }, 1500);
    } catch {
        copiado.value = null;
    }
};

const arquivoAtual = computed(
    () => props.arquivos.find((arquivo) => arquivo.name === props.filtros.arquivo) ?? null,
);
</script>

<template>
    <Head title="Logs do Sistema" />

    <AdminLayout>
        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="mb-1 text-2xl font-bold">Logs do Sistema</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Tudo que o sistema escreve em <code>storage/logs</code> — aplicação, marketplaces, fila e integrações —
                    do mais recente para o mais antigo.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                    <input v-model="autoAtualizar" type="checkbox" class="rounded border-[var(--surface-border)]">
                    Atualizar sozinho (10s)
                </label>
                <button type="button"
                    class="rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]"
                    @click="router.reload({ preserveScroll: true, preserveState: true })">
                    <i class="fas fa-rotate-right mr-1"></i> Atualizar
                </button>
            </div>
        </div>

        <!-- Filtros -->
        <div class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <label class="block text-xs font-medium text-slate-400">Buscar no texto do log</label>
                    <div class="mt-1 flex gap-2">
                        <input v-model="filtros.q" type="search" placeholder="pedido 2028, nfe.issue.failed, CPF, SKU..."
                            class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"
                            @keyup.enter="buscar()">
                        <button type="button"
                            class="whitespace-nowrap rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis"
                            @click="buscar()">
                            <i class="fas fa-magnifying-glass mr-1"></i> Buscar
                        </button>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-400">Arquivo</label>
                    <select v-model="filtros.arquivo"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm"
                        @change="buscar()">
                        <option value="">Todos os arquivos</option>
                        <option v-for="arquivo in props.arquivos" :key="arquivo.name" :value="arquivo.name">
                            {{ arquivo.name }} ({{ arquivo.tamanho }})
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-400">Itens por página</label>
                    <select v-model.number="filtros.porPagina"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm"
                        @change="buscar()">
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                        <option :value="200">200</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-400">De (data e hora)</label>
                    <input v-model="filtros.de" type="datetime-local"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm"
                        @change="buscar()">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-400">Até (data e hora)</label>
                    <input v-model="filtros.ate" type="datetime-local"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm"
                        @change="buscar()">
                </div>

                <div class="sm:col-span-2 lg:col-span-2">
                    <label class="block text-xs font-medium text-slate-400">Nível</label>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                        <button v-for="nivel in props.niveis" :key="nivel.value" type="button"
                            class="rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                            :class="filtros.niveis.includes(nivel.value)
                                ? 'border-primary bg-primary text-white'
                                : 'border-[var(--surface-border)] text-slate-500 hover:bg-[var(--surface-muted)]'"
                            @click="alternarNivel(nivel.value)">
                            {{ nivel.label }}
                        </button>
                        <button type="button"
                            class="rounded-full border border-error px-3 py-1 text-xs font-medium text-error hover:bg-error hover:text-white"
                            @click="soErros">
                            Só problemas
                        </button>
                    </div>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-[var(--surface-border)] pt-3">
                <p class="text-xs text-slate-400">
                    {{ props.varredura.arquivos }} arquivo(s) na varredura · {{ props.varredura.lidos }} lidos
                    <span v-if="arquivoAtual"> · {{ arquivoAtual.name }} atualizado em {{ arquivoAtual.atualizado }}</span>
                </p>
                <button type="button" class="text-xs text-primary hover:underline" @click="limpar">Limpar filtros</button>
            </div>
        </div>

        <div v-if="props.varredura.parcial"
            class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            <i class="fas fa-triangle-exclamation mr-1"></i>
            A varredura parou no limite de leitura (os logs somam centenas de MB). Restrinja por arquivo ou por data
            para alcançar registros mais antigos.
        </div>

        <!-- Lista -->
        <div class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Data/Hora</th>
                            <th class="px-4 py-3">Nível</th>
                            <th class="px-4 py-3">Origem</th>
                            <th class="px-4 py-3">Mensagem</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--surface-border)]">
                        <template v-for="log in props.logs" :key="log.id">
                            <tr class="cursor-pointer align-top hover:bg-[var(--surface-muted)]/50" @click="alternarDetalhe(log.id)">
                                <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ log.dataHora ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium"
                                        :class="NIVEL_CORES[log.nivel] ?? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'">
                                        {{ log.nivelLabel }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-slate-500">{{ log.canal }}</td>
                                <td class="px-4 py-3">
                                    <span class="block max-w-2xl truncate font-mono text-xs">{{ log.mensagem }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-primary">
                                    {{ expandido === log.id ? 'Ocultar' : 'Detalhes' }}
                                </td>
                            </tr>
                            <tr v-if="expandido === log.id">
                                <td colspan="5" class="bg-[var(--surface-muted)]/30 px-4 py-4">
                                    <div class="mb-2 flex flex-wrap items-center gap-3 text-xs text-slate-400">
                                        <span><i class="fas fa-file-lines mr-1"></i>{{ log.arquivo }}</span>
                                        <button type="button" class="text-primary hover:underline" @click.stop="copiar(log)">
                                            {{ copiado === log.id ? 'Copiado!' : 'Copiar entrada' }}
                                        </button>
                                    </div>
                                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-900 p-4 text-xs text-slate-100">{{ log.mensagem }}<template v-if="log.detalhe">
{{ log.detalhe }}</template></pre>
                                    <p v-if="log.cortada" class="mt-2 text-xs text-amber-600">
                                        Entrada muito grande — exibida parcialmente.
                                    </p>
                                </td>
                            </tr>
                        </template>

                        <tr v-if="props.logs.length === 0">
                            <td colspan="5" class="px-4 py-10 text-center text-slate-400">
                                Nenhum registro encontrado com esses filtros.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--surface-border)] px-4 py-3">
                <span class="text-xs text-slate-400">Página {{ props.paginacao.pagina }}</span>
                <div class="flex gap-2">
                    <button type="button" :disabled="props.paginacao.pagina <= 1"
                        class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm disabled:opacity-40"
                        @click="buscar(props.paginacao.pagina - 1)">
                        Anterior
                    </button>
                    <button type="button" :disabled="!props.paginacao.temProxima"
                        class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm disabled:opacity-40"
                        @click="buscar(props.paginacao.pagina + 1)">
                        Próxima
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
