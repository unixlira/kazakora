<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

// Tabela de frete por localidade (pedido do Lira em 2026-10-25): PAC e SEDEX
// salvos por cidade/distrito e categoria de tamanho, preenchidos em segundo
// plano. O site usa esta tabela para os produtos classificados.
const props = defineProps({
    categorias: { type: Array, default: () => [] },
    produtos: { type: Array, default: () => [] },
    resumo: { type: Object, required: true },
});

const resumo = ref(props.resumo);
const categorias = ref(props.categorias.map((c) => ({ ...c })));
const produtos = reactive(props.produtos.map((p) => ({ ...p })));

const dinheiro = (v) => (v === null || v === undefined ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v));
const dias = (v) => (v === null || v === undefined ? '' : `${v} ${v === 1 ? 'dia útil' : 'dias úteis'}`);
const cep = (v) => (v ? `${v.slice(0, 5)}-${v.slice(5)}` : '');
const numero = (v) => new Intl.NumberFormat('pt-BR').format(v ?? 0);
const dataHora = (v) => (v ? new Date(v.replace(' ', 'T')).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '—');
const TIPOS = { M: 'Município', D: 'Distrito', P: 'Povoado' };

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
    if (!r.ok) {
        throw new Error(Object.values(dados.errors ?? {}).flat().join(' ') || dados.message || 'Não deu certo. Tente de novo.');
    }

    return dados;
}

// ---- Andamento ----
const rodando = computed(() => resumo.value.job?.fase && resumo.value.job.fase !== 'parado');
const totalLocalidades = computed(() => Object.values(resumo.value.localidades ?? {}).reduce((s, n) => s + n, 0));
const emDia = computed(() => Math.max(0, (resumo.value.ceps ?? 0) - (resumo.value.pendentes ?? 0)));
const porcentagem = computed(() => (resumo.value.ceps ? Math.round((emDia.value / resumo.value.ceps) * 100) : 0));
const faseTexto = computed(() => {
    const job = resumo.value.job ?? {};
    if (job.fase === 'localidades') return `Baixando as localidades dos Correios${job.uf ? ` (${job.uf})` : ''}`;
    if (job.fase === 'cotacao') return 'Cotando PAC e SEDEX';
    return 'Parado';
});
const iniciando = ref(false);
const erroAndamento = ref('');

async function atualizarStatus() {
    try {
        resumo.value = await pedir('/admin/logistica/frete-por-localidade/status');
    } catch {
        // Sem rede por um instante: tenta no próximo ciclo.
    }
}

async function iniciar(soCotar) {
    iniciando.value = true;
    erroAndamento.value = '';
    try {
        resumo.value = await pedir('/admin/logistica/frete-por-localidade/atualizar', 'POST', { so_cotar: soCotar });
    } catch (e) {
        erroAndamento.value = e.message;
    }
    iniciando.value = false;
}

let relogio = null;
onMounted(() => {
    relogio = window.setInterval(() => { if (rodando.value) atualizarStatus(); }, 10000);
});
onBeforeUnmount(() => window.clearInterval(relogio));

// ---- Categorias ----
const salvandoCategoria = ref('');
const erroCategoria = ref('');
const okCategoria = ref('');

async function salvarCategoria(c) {
    salvandoCategoria.value = c.chave;
    erroCategoria.value = '';
    okCategoria.value = '';
    try {
        const dados = await pedir(`/admin/logistica/frete-por-localidade/categorias/${c.chave}`, 'PUT', {
            peso_g: c.peso_g, comprimento_cm: c.comprimento_cm, largura_cm: c.largura_cm, altura_cm: c.altura_cm, exemplo: c.exemplo,
        });
        categorias.value = dados.categorias.map((x) => ({ ...x }));
        resumo.value = dados.resumo;
        okCategoria.value = `${c.nome} salvo. A cotação dessa categoria recomeçou em segundo plano.`;
    } catch (e) {
        erroCategoria.value = e.message;
    }
    salvandoCategoria.value = '';
}

// ---- Busca ----
const termo = ref('');
const ufBusca = ref('');
const buscando = ref(false);
const erroBusca = ref('');
const resultado = ref(null);
const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

async function buscar() {
    buscando.value = true;
    erroBusca.value = '';
    try {
        const q = new URLSearchParams({ q: termo.value, uf: ufBusca.value });
        resultado.value = await pedir(`/admin/logistica/frete-por-localidade/buscar?${q}`);
        if (!resultado.value.localidades.length) erroBusca.value = 'Nenhuma localidade encontrada.';
    } catch (e) {
        erroBusca.value = e.message;
        resultado.value = null;
    }
    buscando.value = false;
}

// ---- Produtos ----
const filtroProduto = ref('');
const soSemCategoria = ref(false);
const salvandoProduto = ref(null);
const erroProduto = ref('');
const produtosFiltrados = computed(() => {
    const t = filtroProduto.value.trim().toLowerCase();

    return produtos.filter((p) => (!soSemCategoria.value || !p.categoria)
        && (!t || `${p.nome} ${p.sku ?? ''}`.toLowerCase().includes(t)));
});
const semCategoria = computed(() => produtos.filter((p) => p.ativo && !p.categoria).length);
const nomeCategoria = (chave) => categorias.value.find((c) => c.chave === chave)?.nome ?? '';

async function classificar(p, categoria) {
    const anterior = p.categoria;
    p.categoria = categoria || null;
    salvandoProduto.value = p.id;
    erroProduto.value = '';
    try {
        const dados = await pedir(`/admin/logistica/frete-por-localidade/produtos/${p.id}`, 'PUT', { categoria: p.categoria });
        categorias.value = dados.categorias.map((x) => ({ ...x }));
    } catch (e) {
        p.categoria = anterior;
        erroProduto.value = `${p.nome}: ${e.message}`;
    }
    salvandoProduto.value = null;
}

const medidas = (m) => (m ? `${(m.peso_g / 1000).toLocaleString('pt-BR')} kg · ${m.lados.map((x) => x.toLocaleString('pt-BR')).join(' × ')} cm` : 'sem medidas');
</script>

<template>
    <Head title="Frete por localidade" />
    <AdminLayout>
        <h1 class="mb-2 text-2xl font-bold">Frete por localidade</h1>
        <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
            PAC e SEDEX do contrato salvos para cada cidade, distrito e povoado dos Correios, nas 4 categorias de tamanho.
            O site usa esta tabela para os produtos classificados abaixo, sem consultar os Correios na hora. As regras da tela
            Fretes por estado (grátis, teto, acréscimo) continuam valendo por cima. Atualiza sozinha todo dia 1º.
        </p>

        <!-- Andamento -->
        <section class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <div class="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <CardStats compact stat-subtitle="MUNICÍPIOS" :stat-title="numero(resumo.localidades?.M)" stat-icon-name="fas fa-city" variant="primary" />
                <CardStats compact stat-subtitle="DISTRITOS E POVOADOS" :stat-title="numero((resumo.localidades?.D ?? 0) + (resumo.localidades?.P ?? 0))" stat-icon-name="fas fa-house-flag" variant="info" />
                <CardStats compact stat-subtitle="CEPS COTADOS" :stat-title="`${numero(emDia)} de ${numero(resumo.ceps)}`" stat-icon-name="fas fa-circle-check" variant="success" />
                <CardStats compact stat-subtitle="SEM NENHUM SERVIÇO" :stat-title="numero(resumo.com_erro)" stat-icon-name="fas fa-triangle-exclamation" variant="warning" />
            </div>

            <div class="mb-2 h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700" role="progressbar" :aria-valuenow="porcentagem" aria-valuemin="0" aria-valuemax="100" aria-label="CEPs cotados">
                <div class="h-full bg-green-600 transition-all" :style="{ width: `${porcentagem}%` }"></div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                <p class="text-slate-600 dark:text-slate-300">
                    <i :class="rodando ? 'fas fa-spinner fa-spin text-primary' : 'fas fa-pause text-slate-400'" aria-hidden="true"></i>
                    {{ faseTexto }} · {{ porcentagem }}% em dia · última cotação {{ dataHora(resumo.ultima_cotacao) }}
                    <span v-if="resumo.origem"> · saindo do CEP {{ cep(resumo.origem) }}</span>
                </p>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:bg-slate-50 disabled:opacity-50 dark:hover:bg-slate-800"
                        :disabled="iniciando || !totalLocalidades"
                        @click="iniciar(true)"
                    >
                        <i class="fas fa-calculator" aria-hidden="true"></i>Cotar o que falta
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50"
                        :disabled="iniciando"
                        @click="iniciar(false)"
                    >
                        <i :class="iniciando ? 'fas fa-spinner fa-spin' : 'fas fa-rotate'" aria-hidden="true"></i>{{ totalLocalidades ? 'Atualizar tudo' : 'Preencher a tabela' }}
                    </button>
                </div>
            </div>
            <p v-if="resumo.job?.erro" role="alert" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">{{ resumo.job.erro }}</p>
            <p v-if="erroAndamento" role="alert" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">{{ erroAndamento }}</p>
        </section>

        <!-- Categorias -->
        <section class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <h2 class="border-b border-[var(--surface-border)] px-4 py-3 text-lg font-semibold">Categorias de tamanho</h2>
            <p class="px-4 pt-3 text-xs text-slate-500 dark:text-slate-400">
                A caixa de referência de cada categoria é a que vai para a cotação. Limite dos Correios: 30 kg, 100 cm por lado e 200 cm somando os lados.
                Mudou a caixa, a categoria é cotada de novo em segundo plano. Os limites de cada categoria (peso e maior lado) ficam em Fretes por cidade.
            </p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <th scope="col" class="px-4 py-2 font-semibold">Categoria</th>
                            <th scope="col" class="px-2 py-2 font-semibold">Peso (g)</th>
                            <th scope="col" class="px-2 py-2 font-semibold">Comp. (cm)</th>
                            <th scope="col" class="px-2 py-2 font-semibold">Larg. (cm)</th>
                            <th scope="col" class="px-2 py-2 font-semibold">Alt. (cm)</th>
                            <th scope="col" class="px-2 py-2 font-semibold">Exemplo</th>
                            <th scope="col" class="px-2 py-2 text-right font-semibold">Produtos</th>
                            <th scope="col" class="px-4 py-2"><span class="sr-only">Salvar</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in categorias" :key="c.chave" class="border-t border-[var(--surface-border)] align-top">
                            <td class="px-4 py-2 font-medium">
                                {{ c.nome }}
                                <p v-if="c.fora_do_limite" class="mt-1 text-xs font-normal text-amber-700 dark:text-amber-400">{{ c.fora_do_limite }}</p>
                            </td>
                            <td class="px-2 py-2"><input v-model.number="c.peso_g" type="number" min="1" :aria-label="`Peso de ${c.nome}`" class="w-24 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 tabular-nums"></td>
                            <td class="px-2 py-2"><input v-model.number="c.comprimento_cm" type="number" min="1" step="0.1" :aria-label="`Comprimento de ${c.nome}`" class="w-20 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 tabular-nums"></td>
                            <td class="px-2 py-2"><input v-model.number="c.largura_cm" type="number" min="1" step="0.1" :aria-label="`Largura de ${c.nome}`" class="w-20 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 tabular-nums"></td>
                            <td class="px-2 py-2"><input v-model.number="c.altura_cm" type="number" min="1" step="0.1" :aria-label="`Altura de ${c.nome}`" class="w-20 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 tabular-nums"></td>
                            <td class="px-2 py-2"><input v-model="c.exemplo" type="text" maxlength="120" :aria-label="`Exemplo de ${c.nome}`" class="w-56 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1"></td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ c.produtos }}</td>
                            <td class="px-4 py-2 text-right">
                                <button
                                    type="button"
                                    class="rounded-lg border border-[var(--surface-border)] px-3 py-1 text-sm font-medium hover:bg-slate-50 disabled:opacity-50 dark:hover:bg-slate-800"
                                    :disabled="salvandoCategoria === c.chave"
                                    @click="salvarCategoria(c)"
                                >
                                    Salvar
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="okCategoria" class="px-4 pb-3 text-sm text-green-700 dark:text-green-400">{{ okCategoria }}</p>
            <p v-if="erroCategoria" role="alert" class="px-4 pb-3 text-sm text-red-700 dark:text-red-400">{{ erroCategoria }}</p>
        </section>

        <!-- Busca -->
        <section class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <h2 class="border-b border-[var(--surface-border)] px-4 py-3 text-lg font-semibold">Consultar uma localidade</h2>
            <form class="flex flex-wrap items-end gap-3 px-4 py-3" @submit.prevent="buscar">
                <label class="block text-sm">
                    <span class="font-medium">Cidade, distrito ou CEP</span>
                    <input v-model="termo" type="search" placeholder="Ex.: Água Doce do Norte ou 29823-000" class="mt-1 w-72 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
                </label>
                <label class="block text-sm">
                    <span class="font-medium">UF</span>
                    <select v-model="ufBusca" class="mt-1 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
                        <option value="">Todas</option>
                        <option v-for="uf in UFS" :key="uf" :value="uf">{{ uf }}</option>
                    </select>
                </label>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50" :disabled="buscando">
                    <i :class="buscando ? 'fas fa-spinner fa-spin' : 'fas fa-magnifying-glass'" aria-hidden="true"></i>Consultar
                </button>
            </form>
            <p v-if="erroBusca" role="alert" class="px-4 pb-3 text-sm text-slate-600 dark:text-slate-300">{{ erroBusca }}</p>

            <div v-if="resultado?.localidades?.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <th scope="col" class="px-4 py-2 font-semibold">Localidade</th>
                            <th scope="col" class="px-2 py-2 font-semibold">CEP</th>
                            <th v-for="c in categorias" :key="c.chave" scope="col" class="px-2 py-2 font-semibold">{{ c.nome }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="l in resultado.localidades"
                            :key="l.id"
                            class="border-t border-[var(--surface-border)] align-top"
                            :class="resultado.achadas.includes(l.id) ? 'bg-blue-50/60 dark:bg-blue-950/40' : ''"
                        >
                            <td class="px-4 py-2">
                                <span class="font-medium" :class="l.tipo === 'M' ? '' : 'pl-3'">{{ l.nome }}</span>
                                <span class="text-slate-500 dark:text-slate-400"> · {{ l.uf }}</span>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ TIPOS[l.tipo] }}<template v-if="l.tipo !== 'M' && l.municipio"> de {{ l.municipio }}</template></p>
                            </td>
                            <td class="px-2 py-2 tabular-nums">
                                {{ cep(l.cep_cotacao) || '—' }}
                                <p v-if="l.cep_herdado" class="text-xs text-slate-500 dark:text-slate-400">do município</p>
                            </td>
                            <td v-for="c in categorias" :key="c.chave" class="px-2 py-2">
                                <template v-if="l.regras[c.chave]">
                                    <template v-if="l.regras[c.chave].pac !== null || l.regras[c.chave].sedex !== null">
                                        <p class="tabular-nums"><span class="text-xs text-slate-500">PAC</span> {{ dinheiro(l.regras[c.chave].pac) }} <span class="text-xs text-slate-500">{{ dias(l.regras[c.chave].pac_prazo) }}</span></p>
                                        <p class="tabular-nums"><span class="text-xs text-slate-500">SEDEX</span> {{ dinheiro(l.regras[c.chave].sedex) }} <span class="text-xs text-slate-500">{{ dias(l.regras[c.chave].sedex_prazo) }}</span></p>
                                    </template>
                                    <p v-else class="max-w-48 text-xs text-amber-700 dark:text-amber-400">{{ l.regras[c.chave].erro || 'Sem serviço' }}</p>
                                </template>
                                <span v-else class="text-xs text-slate-400">ainda não cotado</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Produtos -->
        <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--surface-border)] px-4 py-3">
                <h2 class="text-lg font-semibold">Produtos <span class="text-sm font-normal text-slate-500 dark:text-slate-400">· {{ semCategoria }} ativos sem categoria</span></h2>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="soSemCategoria" type="checkbox" class="rounded">
                        Só sem categoria
                    </label>
                    <input v-model="filtroProduto" type="search" placeholder="Buscar produto ou SKU" aria-label="Buscar produto ou SKU" class="w-64 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
                </div>
            </div>
            <p class="px-4 pt-3 text-xs text-slate-500 dark:text-slate-400">
                A variação usa a categoria do produto principal. Produto sem categoria continua com o frete pelo peso, consultado nos Correios.
                A sugestão é a categoria pelo peso e pelo maior lado (a mesma das regras em Fretes por cidade); a escolhida aqui vale também para essas regras.
            </p>
            <p v-if="erroProduto" role="alert" class="px-4 pt-2 text-sm text-red-700 dark:text-red-400">{{ erroProduto }}</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <th scope="col" class="px-4 py-2 font-semibold">Produto</th>
                            <th scope="col" class="px-2 py-2 font-semibold">Medidas da caixa</th>
                            <th scope="col" class="px-2 py-2 font-semibold">Sugestão</th>
                            <th scope="col" class="px-4 py-2 font-semibold">Categoria</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in produtosFiltrados" :key="p.id" class="border-t border-[var(--surface-border)]" :class="p.ativo ? '' : 'opacity-60'">
                            <td class="px-4 py-2">
                                {{ p.nome }}
                                <span v-if="p.sku" class="text-xs text-slate-500 dark:text-slate-400"> · {{ p.sku }}</span>
                                <span v-if="!p.ativo" class="text-xs text-slate-500 dark:text-slate-400"> · inativo</span>
                            </td>
                            <td class="px-2 py-2 text-slate-600 tabular-nums dark:text-slate-300">{{ medidas(p.medidas) }}</td>
                            <td class="px-2 py-2">
                                <button
                                    v-if="p.sugestao && p.sugestao !== p.categoria"
                                    type="button"
                                    class="text-sm text-primary underline-offset-2 hover:underline"
                                    :disabled="salvandoProduto === p.id"
                                    @click="classificar(p, p.sugestao)"
                                >
                                    Usar {{ nomeCategoria(p.sugestao) }}
                                </button>
                            </td>
                            <td class="px-4 py-2">
                                <select
                                    :value="p.categoria ?? ''"
                                    :aria-label="`Categoria de ${p.nome}`"
                                    class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 text-sm"
                                    :disabled="salvandoProduto === p.id"
                                    @change="classificar(p, $event.target.value)"
                                >
                                    <option value="">Sem categoria</option>
                                    <option v-for="c in categorias" :key="c.chave" :value="c.chave">{{ c.nome }}</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
