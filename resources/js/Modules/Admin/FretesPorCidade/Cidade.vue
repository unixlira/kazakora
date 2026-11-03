<script setup>
// Página da cidade (pedido 2026-10-24): uma regra para todos os pacotes e,
// se precisar, uma por categoria (Pequena, Média, Grande, Extra Grande), e os
// distritos da cidade com regra própria. Desde 2026-10-25 abre a partir de
// Fretes por estado e mostra em cima o PAC e o SEDEX de cada categoria (tabela
// de Frete por localidade) da cidade e dos distritos. Desde 2026-11-03 com as
// abas Correios e Melhor Envio, como na lista dos estados; no Melhor Envio
// também todas as transportadoras da conta, cotadas na hora para a cidade.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import DistritosDaCidade from '../FretesPorEstado/DistritosDaCidade.vue';
import TabelaPorCategoria from './TabelaPorCategoria.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';
import { TIPOS, descrever, limites } from './regras.js';

const props = defineProps({
    site: { type: String, required: true },
    sites: { type: Object, required: true },
    cidade: { type: Object, required: true },
    categorias: { type: Array, default: () => [] },
    regras: { type: Object, default: () => ({}) },
    localidades: { type: Array, default: () => [] },
    aba: { type: String, default: 'correios' },
    conectado: { type: String, default: 'correios' },
});

const ABAS = [
    { chave: 'correios', rotulo: 'Correios (contrato)', icone: 'fas fa-envelope' },
    { chave: 'melhor-envio', rotulo: 'Melhor Envio', icone: 'fas fa-truck-fast' },
];
const pagina = (params) => router.get(`/admin/logistica/fretes-por-estado/cidades/${props.cidade.id}`, { site: props.site, aba: props.aba, ...params }, { preserveScroll: true });

// Todas as transportadoras do Melhor Envio para a cidade, na hora (só consulta).
const cotacaoMe = ref(null);
const carregandoMe = ref(false);
const erroMe = ref('');
function getCookie(nome) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${nome}=([^;]*)`));
    return match ? decodeURIComponent(match[1]) : null;
}
async function cotarMe() {
    carregandoMe.value = true;
    erroMe.value = '';
    try {
        const r = await fetch(`/admin/logistica/fretes-por-estado/cidades/${props.cidade.id}/cotar-melhor-envio`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') },
        });
        if (!r.ok) throw new Error();
        cotacaoMe.value = await r.json();
        erroMe.value = cotacaoMe.value.erro ?? '';
    } catch {
        erroMe.value = 'Não consegui consultar o Melhor Envio agora. Tente de novo em instantes.';
    }
    carregandoMe.value = false;
}
onMounted(() => {
    if (props.aba === 'melhor-envio') cotarMe();
});
const dinheiro = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v);
const cepFormatado = (v) => (v ? `${v.slice(0, 5)}-${v.slice(5)}` : '');

const blocos = computed(() => [
    { chave: 'todas', nome: 'Todos os pacotes', detalhe: 'Vale para qualquer tamanho que não tenha regra própria abaixo.' },
    ...props.categorias.map((c) => ({ chave: c.chave, nome: c.nome, detalhe: `${limites(c)} · ${c.produtos} ${c.produtos === 1 ? 'produto' : 'produtos'}` })),
]);

const editando = ref(null);
const salvando = ref(false);
const erro = ref('');
const form = reactive({ tipo: 'gratis', valor: 0, prazo_dias: '', ativo: true });
const url = `/admin/logistica/fretes-por-estado/cidades/${props.cidade.id}/regra`;

function editar(chave) {
    const r = props.regras[chave];
    form.tipo = !r ? 'gratis' : r.tipo_preco === 'fixo' && r.valor === 0 ? 'gratis' : r.tipo_preco;
    form.valor = r?.valor ?? 0;
    form.prazo_dias = r?.prazo_dias ?? '';
    form.ativo = r?.ativo ?? true;
    erro.value = '';
    editando.value = chave;
}

const opcoes = (fim) => ({
    preserveScroll: true,
    onStart: () => (salvando.value = true),
    onFinish: () => (salvando.value = false),
    onSuccess: fim,
    onError: (e) => (erro.value = Object.values(e).join(' ')),
});

function salvar(chave) {
    const gratis = form.tipo === 'gratis';
    router.put(url, {
        site: props.site,
        categoria: chave === 'todas' ? null : chave,
        tipo_preco: gratis ? 'fixo' : form.tipo,
        valor: gratis ? 0 : Number(form.valor || 0),
        prazo_dias: form.prazo_dias === '' || form.prazo_dias === null ? null : Number(form.prazo_dias),
        ativo: form.ativo,
    }, opcoes(() => (editando.value = null)));
}

function remover(chave, nome) {
    if (!window.confirm(`Tirar a regra de ${nome} em ${props.cidade.nome}? O frete volta para ${chave === 'todas' ? 'os Correios' : 'a regra de todos os pacotes (ou os Correios)'}.`)) return;
    router.delete(url, { data: { site: props.site, categoria: chave === 'todas' ? null : chave }, ...opcoes(() => (editando.value = null)) });
}

function trocarSite(chave) {
    pagina({ site: chave });
}
</script>

<template>
    <Head :title="`Frete em ${cidade.nome} - ${cidade.uf}`" />

    <AdminLayout>
        <nav class="mb-2 text-sm" aria-label="Caminho">
            <Link href="/admin/logistica/fretes-por-estado" class="font-semibold text-primary hover:underline">Fretes por estado</Link>
            <span class="mx-1 text-slate-400" aria-hidden="true">›</span>
            <Link :href="`/admin/logistica/fretes-por-estado/cidades?uf=${cidade.uf}&site=${site}&aba=${aba}`" class="font-semibold text-primary hover:underline">{{ cidade.estado }}</Link>
        </nav>
        <h1 class="mb-1 text-2xl font-bold">
            <span class="mr-2 inline-flex w-10 justify-center rounded bg-lightprimary py-0.5 align-middle text-sm font-bold text-primary">{{ cidade.uf }}</span>{{ cidade.nome }}
        </h1>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            Código IBGE {{ cidade.id }}. A regra muda o frete embutido no preço para quem tem CEP desta cidade; sem regra, vale o frete normal dos Correios.
        </p>

        <section class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" aria-labelledby="frete-titulo">
            <div role="tablist" aria-label="Frete" class="flex gap-1 border-b border-[var(--surface-border)] px-2">
                <button
                    v-for="a in ABAS"
                    :key="a.chave"
                    type="button"
                    role="tab"
                    :aria-selected="aba === a.chave"
                    class="-mb-px inline-flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition"
                    :class="aba === a.chave ? 'border-primary text-primary' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100'"
                    @click="pagina({ aba: a.chave })"
                >
                    <i :class="a.icone" aria-hidden="true"></i>{{ a.rotulo }}
                    <span v-if="conectado === a.chave" class="rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-semibold text-green-800 dark:bg-green-950 dark:text-green-300">conectado</span>
                </button>
            </div>

            <template v-if="aba === 'correios'">
                <h2 id="frete-titulo" class="px-4 pt-3 text-base font-bold">PAC e SEDEX por categoria</h2>
                <p class="px-4 pt-1 text-xs text-slate-500 dark:text-slate-400">
                    Preço e prazo do contrato dos Correios para a caixa de referência de cada categoria, na cidade e nos distritos dela.
                    Vem da tabela de frete por localidade (atualizada todo dia 1º); a regra abaixo muda o que a loja embute no preço.
                </p>
                <TabelaPorCategoria v-if="localidades.length" :categorias="categorias" :localidades="localidades" provedor="correios" />
                <p v-else class="px-4 py-3 text-sm text-slate-500 dark:text-slate-400">Cidade ainda fora da tabela de frete.</p>
            </template>

            <template v-else>
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-3">
                    <h2 id="frete-titulo" class="text-base font-bold">Transportadoras do Melhor Envio agora</h2>
                    <button type="button" :disabled="carregandoMe" class="inline-flex items-center gap-2 text-sm font-semibold text-primary hover:underline disabled:opacity-50" @click="cotarMe">
                        <i :class="carregandoMe ? 'fas fa-spinner fa-spin' : 'fas fa-rotate'" aria-hidden="true"></i>Cotar de novo
                    </button>
                </div>
                <p class="px-4 pt-1 text-xs text-slate-500 dark:text-slate-400">
                    Todas as transportadoras da conta (Jadlog, Loggi, Correios pelo Melhor Envio...) para o CEP {{ cepFormatado(cotacaoMe?.cep) || 'da cidade' }}, na caixa de referência de cada categoria. Só consulta: não muda o frete do site.
                </p>
                <p v-if="carregandoMe && !cotacaoMe" class="px-4 py-3 text-sm text-slate-500 dark:text-slate-400"><i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>Consultando o Melhor Envio...</p>
                <p v-if="erroMe" role="alert" class="mx-4 mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                    {{ erroMe }}
                    <Link v-if="cotacaoMe?.reconectar" href="/admin/logistica" class="ml-1 font-semibold underline">Abrir Logística</Link>
                </p>
                <div v-if="cotacaoMe && cotacaoMe.categorias.length" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div v-for="c in cotacaoMe.categorias" :key="c.chave" class="rounded-lg border border-[var(--surface-border)] p-3">
                        <p class="mb-2 text-sm font-bold">{{ c.nome }}</p>
                        <p v-if="c.opcoes === null" class="text-xs text-amber-700 dark:text-amber-400">Sem resposta da API</p>
                        <p v-else-if="!c.opcoes.length" class="text-xs text-slate-500 dark:text-slate-400">Nenhuma transportadora atende</p>
                        <ul v-else class="space-y-1">
                            <li v-for="(o, i) in c.opcoes" :key="o.servico_id" class="flex items-baseline justify-between gap-2 text-sm">
                                <span>
                                    <span class="font-medium">{{ o.transportadora }}</span>
                                    <span class="text-slate-500 dark:text-slate-400"> {{ o.servico }}</span>
                                    <i v-if="i === 0" class="fas fa-star ml-1 text-[10px] text-amber-500" title="Mais barata" aria-label="mais barata"></i>
                                </span>
                                <span class="whitespace-nowrap text-right tabular-nums">
                                    <span class="font-semibold">{{ dinheiro(o.preco) }}</span>
                                    <span v-if="o.prazo" class="block text-xs text-slate-500 dark:text-slate-400">{{ o.prazo }} {{ o.prazo === 1 ? 'dia útil' : 'dias úteis' }}</span>
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>

                <h3 class="border-t border-[var(--surface-border)] px-4 pt-3 text-sm font-bold">Tabela salva do Melhor Envio</h3>
                <p class="px-4 pt-1 text-xs text-slate-500 dark:text-slate-400">
                    O serviço mais barato e o mais rápido de cada categoria, na cidade e nos distritos. A tabela é preenchida quando o Melhor Envio está conectado em Logística.
                </p>
                <TabelaPorCategoria v-if="localidades.some((l) => Object.keys(l.regras).length)" :categorias="categorias" :localidades="localidades" provedor="melhor-envio" />
                <p v-else class="px-4 py-3 text-sm text-slate-500 dark:text-slate-400">Ainda sem cotação do Melhor Envio salva para esta cidade.</p>
            </template>
        </section>

        <div role="tablist" aria-label="Site" class="mb-4 flex gap-1 border-b border-[var(--surface-border)]">
            <button
                v-for="(nome, chave) in sites"
                :key="chave"
                type="button"
                role="tab"
                :aria-selected="site === chave"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-semibold transition"
                :class="site === chave ? 'border-primary text-primary' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100'"
                @click="trocarSite(chave)"
            >{{ nome }}</button>
        </div>

        <ul class="mb-6 space-y-3">
            <li v-for="b in blocos" :key="b.chave" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-bold">{{ b.nome }}</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ b.detalhe }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                            :class="regras[b.chave] ? (regras[b.chave].ativo ? 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300' : 'bg-slate-100 text-slate-500 line-through dark:bg-slate-800') : 'text-slate-500 dark:text-slate-400'"
                        >{{ regras[b.chave] ? descrever(regras[b.chave]) : (b.chave === 'todas' ? 'Correios normal' : 'segue Todos os pacotes') }}</span>
                        <button v-if="editando !== b.chave" type="button" class="whitespace-nowrap text-sm font-semibold text-primary hover:underline" @click="editar(b.chave)">
                            {{ regras[b.chave] ? 'editar' : 'criar regra' }}
                        </button>
                    </div>
                </div>

                <form v-if="editando === b.chave" class="mt-3 rounded-xl border border-[var(--surface-border)] bg-slate-50 p-3 dark:bg-slate-900/40" @submit.prevent="salvar(b.chave)">
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
                    <label class="mt-3 flex items-center gap-2 text-sm">
                        <input v-model="form.ativo" type="checkbox" class="rounded">
                        Regra ligada
                    </label>
                    <p v-if="erro" role="alert" class="mt-2 text-sm text-red-700 dark:text-red-300">{{ erro }}</p>
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
                            v-if="regras[b.chave]"
                            type="button"
                            :disabled="salvando"
                            class="ml-auto rounded-lg px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950"
                            @click="remover(b.chave, b.nome)"
                        >Tirar regra</button>
                    </div>
                </form>
            </li>
        </ul>

        <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] py-4 shadow-sm" aria-labelledby="distritos-titulo">
            <h2 id="distritos-titulo" class="mb-2 px-5 text-base font-bold">Distritos de {{ cidade.nome }}</h2>
            <DistritosDaCidade v-if="site === 'kazakora'" :cidade="cidade" />
            <p v-else class="px-5 text-sm text-slate-500 dark:text-slate-400">Regra por distrito por enquanto só na KazaKora.</p>
        </section>
    </AdminLayout>
</template>
