<script setup>
// Frete por cidade (pedido 2026-10-24): todas as cidades do estado (tabela do
// IBGE) e a regra de frete de cada uma. A regra vale no preço da loja
// KazaKora (frete embutido) para quem tem CEP daquela cidade.
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    uf: { type: String, required: true },
    estado: { type: String, default: '' },
});
const emit = defineEmits(['fechar', 'contagem']);

const TIPOS = [
    { chave: 'gratis', rotulo: 'Frete grátis' },
    { chave: 'fixo', rotulo: 'Valor fixo' },
    { chave: 'acrescimo', rotulo: 'Correios + valor' },
    { chave: 'desconto', rotulo: 'Correios − valor' },
    { chave: 'teto', rotulo: 'Correios até no máximo' },
];

const carregando = ref(true);
const erro = ref('');
const cidades = ref([]);
const busca = ref('');
const soComRegra = ref(false);
const editando = ref(null);
const salvando = ref(false);
const erroForm = ref('');
const form = reactive({ tipo: 'gratis', valor: 0, prazo_dias: '' });
const campoBusca = ref(null);

const dinheiro = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v);
const normalizar = (t) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

function getCookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : null;
}

async function pedir(url, metodo = 'GET', corpo = null) {
    const r = await fetch(url, {
        method: metodo,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') },
        body: corpo ? JSON.stringify(corpo) : null,
    });
    const dados = await r.json().catch(() => ({}));
    if (r.status === 422) throw new Error(Object.values(dados.errors ?? {}).flat().join(' ') || 'Regra inválida.');
    if (!r.ok) throw new Error('Não deu certo agora. Tente de novo em instantes.');

    return dados;
}

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

const comRegra = computed(() => cidades.value.filter((c) => c.regra).length);
const filtradas = computed(() => {
    const termo = normalizar(busca.value.trim());

    return cidades.value.filter((c) => (!soComRegra.value || c.regra) && (!termo || normalizar(c.nome).includes(termo)));
});

function descrever(regra) {
    if (!regra) return 'Correios normal';
    const v = dinheiro(regra.valor);
    const texto = {
        fixo: regra.valor === 0 ? 'Frete grátis' : `Frete fixo de ${v}`,
        acrescimo: `Correios + ${v}`,
        desconto: `Correios − ${v}`,
        teto: `Correios até ${v}`,
    }[regra.tipo_preco] ?? regra.tipo_preco;

    return regra.prazo_dias ? `${texto} · ${regra.prazo_dias} ${regra.prazo_dias === 1 ? 'dia útil' : 'dias úteis'}` : texto;
}

function editar(cidade) {
    const r = cidade.regra;
    form.tipo = !r ? 'gratis' : r.tipo_preco === 'fixo' && r.valor === 0 ? 'gratis' : r.tipo_preco;
    form.valor = r?.valor ?? 0;
    form.prazo_dias = r?.prazo_dias ?? '';
    erroForm.value = '';
    editando.value = cidade.id;
}

async function salvar(cidade) {
    salvando.value = true;
    erroForm.value = '';
    try {
        const gratis = form.tipo === 'gratis';
        const dados = await pedir(`/admin/logistica/fretes-por-estado/cidades/${cidade.id}`, 'PUT', {
            tipo_preco: gratis ? 'fixo' : form.tipo,
            valor: gratis ? 0 : Number(form.valor || 0),
            prazo_dias: form.prazo_dias === '' || form.prazo_dias === null ? null : Number(form.prazo_dias),
            ativo: true,
        });
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
                            {{ cidades.length }} cidades · {{ comRegra }} com regra própria. A regra muda o frete embutido no preço da loja KazaKora para quem tem CEP da cidade.
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
                                </span>
                            </div>

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
