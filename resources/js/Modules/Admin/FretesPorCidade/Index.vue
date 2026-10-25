<script setup>
// Cidades do estado (pedido 2026-10-24) com a regra de cada categoria de
// pacote; a cidade abre a página dela com o PAC e o SEDEX de cada categoria e
// as regras. Desde 2026-10-25 fica dentro de Fretes por estado (sem item
// próprio no menu): o olho do estado abre esta lista.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { descrever, limites, normalizar } from './regras.js';

const props = defineProps({
    site: { type: String, required: true },
    sites: { type: Object, required: true },
    uf: { type: String, default: null },
    estado: { type: String, default: null },
    estados: { type: Array, default: () => [] },
    categorias: { type: Array, default: () => [] },
    cidades: { type: Array, default: () => [] },
});

const POR_PAGINA = 150;
const busca = ref('');
const soComRegra = ref(false);
const mostrar = ref(POR_PAGINA);
watch([busca, soComRegra], () => (mostrar.value = POR_PAGINA));

const colunas = computed(() => [{ chave: 'todas', nome: 'Todas' }, ...props.categorias]);
const temRegra = (c) => Object.values(c.regras).some(Boolean) || c.distritos_com_regra > 0;
const comRegra = computed(() => props.cidades.filter(temRegra).length);
const filtradas = computed(() => {
    const termo = normalizar(busca.value.trim());

    return props.cidades.filter((c) => (!soComRegra.value || temRegra(c)) && (!termo || normalizar(c.nome).includes(termo)));
});

function ir(params) {
    router.get('/admin/logistica/fretes-por-estado/cidades', { site: props.site, uf: props.uf, ...params }, { preserveState: false });
}

const linkCidade = (c) => `/admin/logistica/fretes-por-estado/cidades/${c.id}?site=${props.site}`;

// Limites das categorias (a última fica sem limite).
const editandoCategorias = ref(false);
const formCategorias = reactive({ categorias: [] });
const erroCategorias = ref('');
function editarCategorias() {
    formCategorias.categorias = props.categorias.map((c) => ({ chave: c.chave, nome: c.nome, peso_max_g: c.peso_max_g, lado_max_cm: c.lado_max_cm }));
    erroCategorias.value = '';
    editandoCategorias.value = true;
}
function salvarCategorias() {
    router.put('/admin/logistica/fretes-por-estado/categorias', { categorias: formCategorias.categorias.slice(0, -1) }, {
        preserveScroll: true,
        onSuccess: () => (editandoCategorias.value = false),
        onError: (e) => (erroCategorias.value = Object.values(e).join(' ')),
    });
}
</script>

<template>
    <Head :title="`Fretes por estado - ${estado}`" />

    <AdminLayout>
        <nav class="mb-2 text-sm" aria-label="Caminho">
            <Link href="/admin/logistica/fretes-por-estado" class="font-semibold text-primary hover:underline">
                <i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>Fretes por estado
            </Link>
        </nav>
        <h1 class="mb-2 text-2xl font-bold">
            <span class="mr-2 inline-flex w-10 justify-center rounded bg-lightprimary py-0.5 align-middle text-sm font-bold text-primary">{{ uf }}</span>Cidades de {{ estado }}
        </h1>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            Regra de frete de cada cidade, por tamanho do pacote. Sem regra, vale o frete normal dos Correios.
            Clique na cidade para ver o PAC e o SEDEX de cada categoria e criar ou trocar a regra.
            A regra muda o frete embutido no preço da loja para quem tem CEP da cidade; o custo real continua o dos Correios.
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
                @click="ir({ site: chave })"
            >{{ nome }}</button>
        </div>

        <section class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="categorias-titulo">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 id="categorias-titulo" class="text-base font-bold">Categorias de pacote</h2>
                <button v-if="!editandoCategorias" type="button" class="text-sm font-semibold text-primary hover:underline" @click="editarCategorias">editar limites</button>
            </div>
            <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">
                Peso tarifável (o real, ou o cúbico C x L x A ÷ 6000 quando passa de 5 kg) e maior lado da caixa. O carrinho usa o peso somado e o maior lado dos itens.
            </p>

            <form v-if="editandoCategorias" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" @submit.prevent="salvarCategorias">
                <div v-for="(c, i) in formCategorias.categorias" :key="c.chave" class="rounded-lg border border-[var(--surface-border)] p-3">
                    <p class="mb-2 text-sm font-bold">{{ c.nome }}</p>
                    <p v-if="i === formCategorias.categorias.length - 1" class="text-xs text-slate-500 dark:text-slate-400">Tudo o que passa da anterior.</p>
                    <div v-else class="grid grid-cols-2 gap-2">
                        <label class="block text-xs">
                            <span class="font-medium">Peso até (g)</span>
                            <input v-model.number="c.peso_max_g" type="number" min="1" max="30000" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 text-sm tabular-nums">
                        </label>
                        <label class="block text-xs">
                            <span class="font-medium">Maior lado até (cm)</span>
                            <input v-model.number="c.lado_max_cm" type="number" min="1" max="200" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 text-sm tabular-nums">
                        </label>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2 sm:col-span-2 xl:col-span-4">
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-emphasis">
                        <i class="fas fa-check" aria-hidden="true"></i>Salvar limites
                    </button>
                    <button type="button" class="rounded-lg px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" @click="editandoCategorias = false">Cancelar</button>
                    <p v-if="erroCategorias" role="alert" class="text-sm text-red-700 dark:text-red-300">{{ erroCategorias }}</p>
                </div>
            </form>

            <ul v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <li v-for="c in categorias" :key="c.chave" class="rounded-lg border border-[var(--surface-border)] p-3">
                    <p class="flex items-baseline justify-between gap-2">
                        <span class="text-sm font-bold">{{ c.nome }}</span>
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ c.produtos }} {{ c.produtos === 1 ? 'produto' : 'produtos' }}</span>
                    </p>
                    <p class="text-xs text-slate-600 dark:text-slate-300">{{ limites(c) }}</p>
                    <p v-if="c.exemplos.length" class="mt-1 line-clamp-2 text-xs text-slate-500 dark:text-slate-400" :title="c.exemplos.join(' · ')">Ex.: {{ c.exemplos.join(' · ') }}</p>
                </li>
            </ul>
        </section>

        <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" aria-labelledby="cidades-titulo">
            <div class="flex flex-wrap items-end gap-3 border-b border-[var(--surface-border)] px-4 py-3">
                <label class="block text-sm">
                    <span class="font-medium">Estado</span>
                    <select
                        :value="uf"
                        class="mt-1 block w-56 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm"
                        @change="ir({ uf: $event.target.value })"
                    >
                        <option v-for="e in estados" :key="e.uf" :value="e.uf">{{ e.nome }}{{ e.com_regra ? ` (${e.com_regra} com regra)` : '' }}</option>
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Cidade</span>
                    <input v-model="busca" type="search" placeholder="Buscar cidade" class="mt-1 block w-64 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
                </label>
                <label class="flex items-center gap-2 pb-2 text-sm">
                    <input v-model="soComRegra" type="checkbox" class="rounded">
                    Só cidades com regra
                </label>
                <p id="cidades-titulo" class="ml-auto pb-2 text-xs text-slate-500 dark:text-slate-400">{{ cidades.length }} cidades em {{ estado }} · {{ comRegra }} com regra</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <th scope="col" class="px-4 py-2 font-semibold">Cidade</th>
                            <th v-for="col in colunas" :key="col.chave" scope="col" class="px-3 py-2 font-semibold">{{ col.nome }}</th>
                            <th scope="col" class="px-3 py-2"><span class="sr-only">Abrir</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in filtradas.slice(0, mostrar)" :key="c.id" class="border-t border-[var(--surface-border)]">
                            <td class="px-4 py-2 font-medium">
                                <Link :href="linkCidade(c)" class="hover:text-primary hover:underline">{{ c.nome }}</Link>
                                <span v-if="c.distritos_com_regra" class="ml-2 whitespace-nowrap text-xs font-normal text-green-700 dark:text-green-300">
                                    {{ c.distritos_com_regra }} {{ c.distritos_com_regra === 1 ? 'distrito' : 'distritos' }} com regra
                                </span>
                            </td>
                            <td v-for="col in colunas" :key="col.chave" class="px-3 py-2">
                                <span
                                    v-if="c.regras[col.chave]"
                                    class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="c.regras[col.chave].ativo ? 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300' : 'bg-slate-100 text-slate-500 line-through dark:bg-slate-800'"
                                >{{ descrever(c.regras[col.chave]) }}</span>
                                <span v-else class="text-xs text-slate-400 dark:text-slate-500">{{ col.chave === 'todas' ? 'Correios normal' : '—' }}</span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Link v-if="temRegra(c)" :href="linkCidade(c)" class="whitespace-nowrap text-xs font-semibold text-primary hover:underline">editar</Link>
                            </td>
                        </tr>
                        <tr v-if="!filtradas.length">
                            <td :colspan="colunas.length + 2" class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">Nenhuma cidade encontrada.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="filtradas.length > mostrar" class="border-t border-[var(--surface-border)] px-4 py-3 text-center">
                <button type="button" class="text-sm font-semibold text-primary hover:underline" @click="mostrar += POR_PAGINA">
                    Mostrar mais ({{ filtradas.length - mostrar }} restantes)
                </button>
            </div>
            <p class="border-t border-[var(--surface-border)] px-4 py-2 text-xs text-slate-500 dark:text-slate-400">
                "Todas" vale para qualquer pacote; a regra de uma categoria passa na frente dela. "—" = segue a regra de Todas (ou os Correios).
            </p>
        </section>
    </AdminLayout>
</template>
