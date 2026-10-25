<script setup>
// Frete por cidade (pedido 2026-10-24): todas as cidades do estado (tabela do
// IBGE) e a regra de frete de cada uma. A regra vale no preço da loja
// KazaKora (frete embutido) para quem tem CEP daquela cidade.
// Distritos (2026-10-25): cada cidade abre os distritos dela com regra própria.
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import DistritosDaCidade from './DistritosDaCidade.vue';
import { TIPOS, camposDaRegra, corpoDaRegra, descrever, normalizar, pedir } from './regraDeFrete';

const props = defineProps({
    uf: { type: String, required: true },
    estado: { type: String, default: '' },
});
const emit = defineEmits(['fechar', 'contagem']);

const carregando = ref(true);
const erro = ref('');
const cidades = ref([]);
const busca = ref('');
const soComRegra = ref(false);
const editando = ref(null);
const distritosAbertos = ref(null);
const salvando = ref(false);
const erroForm = ref('');
const form = reactive({ tipo: 'gratis', valor: 0, prazo_dias: '' });
const campoBusca = ref(null);

async function carregar() {
    carregando.value = true;
    erro.value = '';
    try {
        const dados = await pedir(`/admin/logistica/fretes-por-estado/${props.uf}/cidades`);
        cidades.value = dados.cidades;
    } catch (e) {
        erro.value = e.message;
    }
    carregando.value = false;
    await nextTick();
    campoBusca.value?.focus();
}

const temRegra = (c) => c.regra || c.distritos_com_regra > 0;
const comRegra = computed(() => cidades.value.filter(temRegra).length);
const filtradas = computed(() => {
    const termo = normalizar(busca.value.trim());

    return cidades.value.filter((c) => (!soComRegra.value || temRegra(c)) && (!termo || normalizar(c.nome).includes(termo)));
});

function editar(cidade) {
    Object.assign(form, camposDaRegra(cidade.regra));
    erroForm.value = '';
    distritosAbertos.value = null;
    editando.value = cidade.id;
}

function abrirDistritos(cidade) {
    editando.value = null;
    distritosAbertos.value = distritosAbertos.value === cidade.id ? null : cidade.id;
}

function contarDistritos(cidade, n) {
    cidade.distritos_com_regra = n;
    emit('contagem', props.uf, comRegra.value);
}

async function salvar(cidade) {
    salvando.value = true;
    erroForm.value = '';
    try {
        const dados = await pedir(`/admin/logistica/fretes-por-estado/cidades/${cidade.id}`, 'PUT', corpoDaRegra(form));
        cidade.regra = dados.regra;
        editando.value = null;
        emit('contagem', props.uf, comRegra.value);
    } catch (e) {
        erroForm.value = e.message;
    }
    salvando.value = false;
}

async function remover(cidade) {
    if (!window.confirm(`Tirar a regra de ${cidade.nome}? A cidade volta para o frete normal dos Correios.`)) return;
    salvando.value = true;
    erroForm.value = '';
    try {
        await pedir(`/admin/logistica/fretes-por-estado/cidades/${cidade.id}`, 'DELETE');
        cidade.regra = null;
        editando.value = null;
        emit('contagem', props.uf, comRegra.value);
    } catch (e) {
        erroForm.value = e.message;
    }
    salvando.value = false;
}

function aoTeclar(e) {
    if (e.key === 'Escape') emit('fechar');
}

onMounted(() => {
    carregar();
    window.addEventListener('keydown', aoTeclar);
});
onBeforeUnmount(() => window.removeEventListener('keydown', aoTeclar));
</script>

<template>
    <Teleport to="body">
        <!-- admin-shell: fora do layout (Teleport) a janela perdia --surface e ficava transparente. -->
        <div class="admin-shell fixed inset-0 z-[90] flex items-center justify-center bg-black/60 p-4 text-[var(--surface-foreground)]" role="dialog" aria-modal="true" aria-labelledby="cidades-titulo" @click.self="emit('fechar')">
            <div class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-2xl border border-[var(--surface-border)] bg-[var(--surface,#ffffff)] shadow-2xl">
                <header class="flex items-start justify-between gap-3 border-b border-[var(--surface-border)] px-5 py-4">
                    <div>
                        <h2 id="cidades-titulo" class="text-lg font-bold">
                            <span class="mr-2 inline-flex w-9 justify-center rounded bg-lightprimary py-0.5 text-xs font-bold text-primary">{{ uf }}</span>Cidades de {{ estado || uf }}
                        </h2>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            {{ cidades.length }} cidades · {{ comRegra }} com regra própria. A regra muda o frete embutido no preço da loja KazaKora para quem tem CEP da cidade; em "distritos", cada distrito pode ter a sua.
                        </p>
                    </div>
                    <button type="button" class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Fechar" @click="emit('fechar')">
                        <i class="fas fa-xmark text-lg" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="flex flex-wrap items-center gap-3 border-b border-[var(--surface-border)] px-5 py-3">
                    <input
                        ref="campoBusca"
                        v-model="busca"
                        type="search"
                        placeholder="Buscar cidade"
                        aria-label="Buscar cidade"
                        class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm sm:w-64"
                    >
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="soComRegra" type="checkbox" class="rounded">
                        Só cidades com regra
                    </label>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    <div v-if="carregando" class="px-5 py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                        <i class="fas fa-spinner fa-spin mr-2" aria-hidden="true"></i>Carregando as cidades…
                    </div>
                    <div v-else-if="erro" role="alert" class="m-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                        {{ erro }}
                    </div>
                    <ul v-else>
                        <li v-for="c in filtradas" :key="c.id" class="border-t border-[var(--surface-border)] first:border-t-0">
                            <div class="flex items-center justify-between gap-3 px-5 py-2 text-sm">
                                <span class="font-medium">{{ c.nome }}</span>
                                <span class="flex items-center gap-3">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :class="c.regra ? 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300' : 'text-slate-500 dark:text-slate-400'"
                                    >{{ descrever(c.regra) }}</span>
                                    <button
                                        v-if="editando !== c.id"
                                        type="button"
                                        class="whitespace-nowrap text-xs font-semibold text-primary hover:underline"
                                        @click="editar(c)"
                                    >{{ c.regra ? 'editar' : 'criar regra' }}</button>
                                    <button
                                        type="button"
                                        class="whitespace-nowrap text-xs font-semibold hover:underline"
                                        :class="c.distritos_com_regra ? 'text-green-700 dark:text-green-300' : 'text-slate-500 dark:text-slate-400'"
                                        :aria-expanded="distritosAbertos === c.id"
                                        @click="abrirDistritos(c)"
                                    >
                                        distritos<template v-if="c.distritos_com_regra"> ({{ c.distritos_com_regra }})</template>
                                        <i class="fas ml-0.5 text-[10px]" :class="distritosAbertos === c.id ? 'fa-chevron-up' : 'fa-chevron-down'" aria-hidden="true"></i>
                                    </button>
                                </span>
                            </div>

                            <DistritosDaCidade v-if="distritosAbertos === c.id" :cidade="c" @contagem="(n) => contarDistritos(c, n)" />

                            <form v-if="editando === c.id" class="mx-5 mb-3 rounded-xl border border-[var(--surface-border)] bg-slate-50 p-3 dark:bg-slate-900/40" @submit.prevent="salvar(c)">
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)] sm:items-end">
                                    <label class="block text-sm">
                                        <span class="font-medium">Regra</span>
                                        <select v-model="form.tipo" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
                                            <option v-for="t in TIPOS" :key="t.chave" :value="t.chave">{{ t.rotulo }}</option>
                                        </select>
                                    </label>
                                    <label class="block text-sm" :class="form.tipo === 'gratis' ? 'opacity-40' : ''">
                                        <span class="font-medium">Valor (R$)</span>
                                        <input
                                            v-model.number="form.valor"
                                            type="number"
                                            min="0"
                                            max="1000"
                                            step="0.01"
                                            :disabled="form.tipo === 'gratis'"
                                            class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm tabular-nums"
                                        >
                                    </label>
                                    <label class="block text-sm">
                                        <span class="font-medium">Prazo (dias úteis)</span>
                                        <input
                                            v-model="form.prazo_dias"
                                            type="number"
                                            min="1"
                                            max="60"
                                            placeholder="o dos Correios"
                                            class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm tabular-nums"
                                        >
                                    </label>
                                </div>
                                <p v-if="erroForm" role="alert" class="mt-2 text-sm text-red-700 dark:text-red-300">{{ erroForm }}</p>
                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    <button
                                        type="submit"
                                        :disabled="salvando"
                                        class="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50"
                                    >
                                        <i :class="salvando ? 'fas fa-spinner fa-spin' : 'fas fa-check'" aria-hidden="true"></i>Salvar
                                    </button>
                                    <button type="button" class="rounded-lg px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" @click="editando = null">Cancelar</button>
                                    <button
                                        v-if="c.regra"
                                        type="button"
                                        :disabled="salvando"
                                        class="ml-auto rounded-lg px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950"
                                        @click="remover(c)"
                                    >Tirar regra</button>
                                </div>
                            </form>
                        </li>
                        <li v-if="!filtradas.length" class="px-5 py-6 text-center text-sm text-slate-500 dark:text-slate-400">Nenhuma cidade encontrada.</li>
                    </ul>
                </div>
            </div>
        </div>
    </Teleport>
</template>
