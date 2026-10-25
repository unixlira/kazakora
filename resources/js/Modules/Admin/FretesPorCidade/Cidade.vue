<script setup>
// Página da cidade em Fretes por cidade (pedido 2026-10-24): uma regra para
// todos os pacotes e, se precisar, uma por categoria (Pequena, Média, Grande,
// Extra Grande), e os distritos da cidade com regra própria.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import DistritosDaCidade from '../FretesPorEstado/DistritosDaCidade.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { TIPOS, descrever, limites } from './regras.js';

const props = defineProps({
    site: { type: String, required: true },
    sites: { type: Object, required: true },
    cidade: { type: Object, required: true },
    categorias: { type: Array, default: () => [] },
    regras: { type: Object, default: () => ({}) },
});

const blocos = computed(() => [
    { chave: 'todas', nome: 'Todos os pacotes', detalhe: 'Vale para qualquer tamanho que não tenha regra própria abaixo.' },
    ...props.categorias.map((c) => ({ chave: c.chave, nome: c.nome, detalhe: `${limites(c)} · ${c.produtos} ${c.produtos === 1 ? 'produto' : 'produtos'}` })),
]);

const editando = ref(null);
const salvando = ref(false);
const erro = ref('');
const form = reactive({ tipo: 'gratis', valor: 0, prazo_dias: '', ativo: true });
const url = `/admin/logistica/fretes-por-cidade/${props.cidade.id}/regra`;

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
    router.get(`/admin/logistica/fretes-por-cidade/${props.cidade.id}`, { site: chave });
}
</script>

<template>
    <Head :title="`Frete em ${cidade.nome} - ${cidade.uf}`" />

    <AdminLayout>
        <nav class="mb-2 text-sm" aria-label="Caminho">
            <Link :href="`/admin/logistica/fretes-por-cidade?uf=${cidade.uf}&site=${site}`" class="font-semibold text-primary hover:underline">
                <i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>Fretes por cidade
            </Link>
        </nav>
        <h1 class="mb-1 text-2xl font-bold">
            <span class="mr-2 inline-flex w-10 justify-center rounded bg-lightprimary py-0.5 align-middle text-sm font-bold text-primary">{{ cidade.uf }}</span>{{ cidade.nome }}
        </h1>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            Código IBGE {{ cidade.id }}. A regra muda o frete embutido no preço para quem tem CEP desta cidade; sem regra, vale o frete normal dos Correios.
        </p>

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
