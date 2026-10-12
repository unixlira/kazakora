<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    produtos: { type: Array, default: () => [] },
    pacotePadrao: { type: Object, required: true },
    melhorEnvio: { type: Object, default: () => ({ conectado: false, ambiente: 'sandbox' }) },
});

// Duas abas (2026-10-12): Correios (contrato próprio) e Melhor Envio. A aba
// fica na URL (?aba=melhor-envio) para dar F5 sem voltar para a primeira.
const ABAS = [
    { chave: 'correios', rotulo: 'Correios (contrato)', icone: 'fas fa-envelope' },
    { chave: 'melhor-envio', rotulo: 'Melhor Envio', icone: 'fas fa-truck-fast' },
];
const abaInicial = new URLSearchParams(window.location.search).get('aba');
const aba = ref(ABAS.some((a) => a.chave === abaInicial) ? abaInicial : 'correios');
function trocarAba(chave) {
    aba.value = chave;
    const url = new URL(window.location.href);
    url.searchParams.set('aba', chave);
    window.history.replaceState(window.history.state, '', url);
    if (chave === 'melhor-envio' && !resultadoMe.value && !carregandoMe.value) consultarMe();
}

const REGIOES = ['Sudeste', 'Sul', 'Centro-Oeste', 'Nordeste', 'Norte'];

const pacote = reactive({ ...props.pacotePadrao });
const produtoId = ref('');
const busca = ref('');
const ordem = ref('regiao');
const carregando = ref(false);
const erro = ref('');
const resultado = ref(null);
const carregandoMe = ref(false);
const erroMe = ref('');
const resultadoMe = ref(null);
const abertos = ref({});

const dinheiro = (v) => (v === null || v === undefined ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v));
const dias = (v) => (v === null || v === undefined ? '—' : `${v} ${v === 1 ? 'dia útil' : 'dias úteis'}`);
const cep = (v) => (v ? `${v.slice(0, 5)}-${v.slice(5)}` : '');

function getCookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : null;
}

function escolherProduto() {
    const p = props.produtos.find((x) => String(x.id) === String(produtoId.value));
    if (p) Object.assign(pacote, p.pacote);
    consultarTudo();
}

// Pede a tabela ao servidor; devolve [dados, erro].
async function pedir(url, nome) {
    try {
        const r = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') },
            body: JSON.stringify(pacote),
        });
        const dados = await r.json();
        if (r.status === 422) return [null, Object.values(dados.errors ?? {}).flat().join(' ') || 'Medidas inválidas.'];
        if (!r.ok) throw new Error();
        return [dados, dados.erro ?? ''];
    } catch {
        return [null, `Não consegui consultar ${nome} agora. Tente de novo em instantes.`];
    }
}

async function consultar() {
    carregando.value = true;
    const [dados, falha] = await pedir('/admin/logistica/fretes-por-estado/cotar', 'os Correios');
    if (dados) resultado.value = dados;
    erro.value = falha;
    carregando.value = false;
}

async function consultarMe() {
    carregandoMe.value = true;
    abertos.value = {};
    const [dados, falha] = await pedir('/admin/logistica/fretes-por-estado/cotar-melhor-envio', 'o Melhor Envio');
    if (dados) resultadoMe.value = dados;
    erroMe.value = falha;
    carregandoMe.value = false;
}

// A comparação da aba Melhor Envio usa a tabela dos Correios do mesmo pacote.
function consultarTudo() {
    consultar();
    if (aba.value === 'melhor-envio' || resultadoMe.value) consultarMe();
}

onMounted(consultarTudo);

const linhas = computed(() => resultado.value?.linhas ?? []);

const filtradas = computed(() => {
    const termo = busca.value.trim().toLowerCase();
    const lista = linhas.value.filter((l) => !termo || `${l.uf} ${l.estado} ${l.capital}`.toLowerCase().includes(termo));
    if (ordem.value === 'pac') return [...lista].sort((a, b) => (a.pac.preco ?? 1e9) - (b.pac.preco ?? 1e9));
    if (ordem.value === 'sedex') return [...lista].sort((a, b) => (a.sedex.preco ?? 1e9) - (b.sedex.preco ?? 1e9));
    if (ordem.value === 'uf') return [...lista].sort((a, b) => a.uf.localeCompare(b.uf));
    return [...lista].sort((a, b) => REGIOES.indexOf(a.regiao) - REGIOES.indexOf(b.regiao) || a.uf.localeCompare(b.uf));
});

// Faixa de preço de cada serviço, para pintar do mais barato (verde) ao mais caro (vermelho).
const faixa = (servico) => {
    const v = linhas.value.map((l) => l[servico].preco).filter((x) => x !== null);
    return v.length ? { min: Math.min(...v), max: Math.max(...v), media: v.reduce((s, x) => s + x, 0) / v.length } : null;
};
const faixaPac = computed(() => faixa('pac'));
const faixaSedex = computed(() => faixa('sedex'));

function corPreco(servico, valor) {
    const f = servico === 'pac' ? faixaPac.value : faixaSedex.value;
    if (valor === null || !f || f.max === f.min) return '';
    const t = (valor - f.min) / (f.max - f.min);
    if (t < 0.34) return 'text-green-700 dark:text-green-400';
    if (t < 0.67) return 'text-amber-700 dark:text-amber-400';
    return 'text-red-700 dark:text-red-400';
}

// Qual dos dois sai mais barato para o estado (às vezes o SEDEX do contrato sai mais em conta).
const maisBarato = (l) => {
    if (l.pac.preco === null || l.sedex.preco === null) return null;
    return l.sedex.preco <= l.pac.preco ? 'sedex' : 'pac';
};
const sedexMaisBarato = computed(() => linhas.value.filter((l) => maisBarato(l) === 'sedex').map((l) => l.uf));

// ---- Aba Melhor Envio ----
const linhasMe = computed(() => resultadoMe.value?.linhas ?? []);
const correiosPorUf = computed(() => Object.fromEntries(linhas.value.map((l) => {
    const precos = [l.pac.preco, l.sedex.preco].filter((x) => x !== null);
    return [l.uf, precos.length ? Math.min(...precos) : null];
})));
const filtradasMe = computed(() => {
    const termo = busca.value.trim().toLowerCase();
    const lista = linhasMe.value.filter((l) => !termo || `${l.uf} ${l.estado} ${l.capital}`.toLowerCase().includes(termo));
    if (ordem.value === 'uf') return [...lista].sort((a, b) => a.uf.localeCompare(b.uf));
    if (ordem.value !== 'regiao') return [...lista].sort((a, b) => (a.opcoes[0]?.preco ?? 1e9) - (b.opcoes[0]?.preco ?? 1e9));
    return [...lista].sort((a, b) => REGIOES.indexOf(a.regiao) - REGIOES.indexOf(b.regiao) || a.uf.localeCompare(b.uf));
});
const faixaMe = computed(() => {
    const v = linhasMe.value.map((l) => l.opcoes[0]?.preco).filter((x) => x !== undefined);
    return v.length ? { min: Math.min(...v), max: Math.max(...v), media: v.reduce((s, x) => s + x, 0) / v.length } : null;
});
const estadosMaisBaratosNoMe = computed(() => linhasMe.value.filter((l) => {
    const c = correiosPorUf.value[l.uf];
    return l.opcoes[0] && c !== null && c !== undefined && l.opcoes[0].preco < c;
}).length);
function diferenca(l) {
    const c = correiosPorUf.value[l.uf];
    if (!l.opcoes[0] || c === null || c === undefined) return null;
    return l.opcoes[0].preco - c;
}
const ambienteTeste = computed(() => (resultadoMe.value?.ambiente ?? props.melhorEnvio.ambiente) === 'sandbox');
const precisaConectar = computed(() => !props.melhorEnvio.conectado || resultadoMe.value?.reconectar);

const pacoteTexto = computed(() => `${new Intl.NumberFormat('pt-BR').format(pacote.peso_g)} g · ${pacote.comprimento} × ${pacote.largura} × ${pacote.altura} cm · valor declarado ${dinheiro(pacote.valor || 0)}`);
const campos = [
    { chave: 'peso_g', rotulo: 'Peso (g)', min: 1, max: 30000, passo: 1 },
    { chave: 'comprimento', rotulo: 'Comprimento (cm)', min: 16, max: 100, passo: 0.5 },
    { chave: 'largura', rotulo: 'Largura (cm)', min: 11, max: 100, passo: 0.5 },
    { chave: 'altura', rotulo: 'Altura (cm)', min: 2, max: 100, passo: 0.5 },
    { chave: 'valor', rotulo: 'Valor (R$)', min: 0, max: 100000, passo: 0.01 },
];
</script>

<template>
    <Head title="Fretes por estado" />

    <AdminLayout>
        <div class="mb-2 flex items-center justify-between">
            <h1 class="text-2xl font-bold">Fretes por estado</h1>
        </div>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            Quanto custa mandar o pacote da empresa{{ resultado?.origem ? ` (CEP ${cep(resultado.origem)})` : '' }} para a capital de cada estado.
            No interior o valor e o prazo podem mudar.
        </p>

        <div role="tablist" aria-label="Fonte do frete" class="mb-4 flex gap-1 border-b border-[var(--surface-border)]">
            <button
                v-for="a in ABAS"
                :key="a.chave"
                type="button"
                role="tab"
                :aria-selected="aba === a.chave"
                class="-mb-px inline-flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-semibold transition"
                :class="aba === a.chave ? 'border-primary text-primary' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100'"
                @click="trocarAba(a.chave)"
            >
                <i :class="a.icone" aria-hidden="true"></i>{{ a.rotulo }}
            </button>
        </div>

        <form class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" @submit.prevent="consultarTudo">
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)_auto] lg:items-end">
                <label class="block text-sm">
                    <span class="font-medium">Produto</span>
                    <select
                        v-model="produtoId"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm"
                        @change="escolherProduto"
                    >
                        <option value="">Medidas digitadas</option>
                        <option v-for="p in produtos" :key="p.id" :value="p.id">{{ p.nome }}{{ p.sku ? ` (${p.sku})` : '' }}</option>
                    </select>
                </label>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <label v-for="c in campos" :key="c.chave" class="block text-sm">
                        <span class="font-medium">{{ c.rotulo }}</span>
                        <input
                            v-model.number="pacote[c.chave]"
                            type="number"
                            :min="c.min"
                            :max="c.max"
                            :step="c.passo"
                            required
                            class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm tabular-nums"
                            @input="produtoId = ''"
                        >
                    </label>
                </div>
                <button
                    type="submit"
                    :disabled="carregando || carregandoMe"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50"
                >
                    <i :class="carregando || carregandoMe ? 'fas fa-spinner fa-spin' : 'fas fa-magnifying-glass-dollar'" aria-hidden="true"></i>
                    {{ carregando || carregandoMe ? 'Consultando…' : 'Consultar' }}
                </button>
            </div>
            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                Pacote: {{ pacoteTexto }}. Ao escolher um produto, o peso, as medidas e o valor vêm do cadastro dele. O valor declarado só entra no Melhor Envio (seguro).
            </p>
        </form>

        <div v-show="aba === 'correios'">
        <div v-if="erro" role="alert" class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            {{ erro }}
        </div>

        <template v-if="linhas.length">
            <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <CardStats compact stat-subtitle="PAC MAIS BARATO" :stat-title="dinheiro(faixaPac?.min)" stat-icon-name="fas fa-box" variant="success" />
                <CardStats compact stat-subtitle="PAC MAIS CARO" :stat-title="dinheiro(faixaPac?.max)" stat-icon-name="fas fa-box" variant="error" />
                <CardStats compact stat-subtitle="SEDEX MAIS BARATO" :stat-title="dinheiro(faixaSedex?.min)" stat-icon-name="fas fa-truck-fast" variant="success" />
                <CardStats compact stat-subtitle="SEDEX MAIS CARO" :stat-title="dinheiro(faixaSedex?.max)" stat-icon-name="fas fa-truck-fast" variant="error" />
            </div>

            <p v-if="sedexMaisBarato.length" class="mb-4 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-100">
                <i class="fas fa-lightbulb mr-1" aria-hidden="true"></i>
                Nesse pacote o SEDEX sai pelo mesmo preço ou mais barato que o PAC para: <strong>{{ sedexMaisBarato.join(', ') }}</strong>.
            </p>

            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--surface-border)] px-4 py-3">
                    <input
                        v-model="busca"
                        type="search"
                        placeholder="Buscar estado ou UF"
                        aria-label="Buscar estado ou UF"
                        class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm sm:w-64"
                    >
                    <label class="flex items-center gap-2 text-sm">
                        <span class="text-slate-500 dark:text-slate-400">Ordenar por</span>
                        <select v-model="ordem" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
                            <option value="regiao">Região</option>
                            <option value="uf">UF</option>
                            <option value="pac">PAC mais barato</option>
                            <option value="sedex">SEDEX mais barato</option>
                        </select>
                    </label>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                <th scope="col" class="px-4 py-2 font-semibold">Estado</th>
                                <th scope="col" class="px-4 py-2 font-semibold">Região</th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold">PAC</th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold">Prazo PAC</th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold">SEDEX</th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold">Prazo SEDEX</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in filtradas" :key="l.uf" class="border-t border-[var(--surface-border)]">
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="inline-flex w-9 justify-center rounded bg-lightprimary py-0.5 text-xs font-bold text-primary">{{ l.uf }}</span>
                                        <span>
                                            <span class="font-semibold">{{ l.estado }}</span>
                                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ l.capital }} · {{ cep(l.cep) }}</span>
                                        </span>
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-slate-600 dark:text-slate-300">{{ l.regiao }}</td>
                                <td class="px-4 py-2 text-right font-semibold tabular-nums" :class="corPreco('pac', l.pac.preco)">
                                    <span v-if="l.pac.erro" class="text-xs font-normal text-slate-500" :title="l.pac.erro">indisponível</span>
                                    <template v-else>
                                        {{ dinheiro(l.pac.preco) }}
                                        <i v-if="maisBarato(l) === 'pac'" class="fas fa-star ml-1 text-[10px] text-amber-500" title="Mais barato para este estado" aria-label="mais barato"></i>
                                    </template>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ dias(l.pac.prazo) }}</td>
                                <td class="px-4 py-2 text-right font-semibold tabular-nums" :class="corPreco('sedex', l.sedex.preco)">
                                    <span v-if="l.sedex.erro" class="text-xs font-normal text-slate-500" :title="l.sedex.erro">indisponível</span>
                                    <template v-else>
                                        {{ dinheiro(l.sedex.preco) }}
                                        <i v-if="maisBarato(l) === 'sedex'" class="fas fa-star ml-1 text-[10px] text-amber-500" title="Mais barato para este estado" aria-label="mais barato"></i>
                                    </template>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ dias(l.sedex.prazo) }}</td>
                            </tr>
                            <tr v-if="!filtradas.length">
                                <td colspan="6" class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">Nenhum estado encontrado.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-[var(--surface-border)] px-4 py-2 text-xs text-slate-500 dark:text-slate-400">
                    <i class="fas fa-star text-amber-500" aria-hidden="true"></i> = serviço mais barato para o estado.
                    Cor do preço: verde é dos mais baratos do país, vermelho dos mais caros. Média: PAC {{ dinheiro(faixaPac?.media) }}, SEDEX {{ dinheiro(faixaSedex?.media) }}.
                    Prazo em dias úteis depois da postagem. Valores guardados por 6 horas.
                </p>
            </section>
        </template>

        <div v-else-if="carregando" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-10 text-center text-sm text-slate-500 dark:text-slate-400">
            <i class="fas fa-spinner fa-spin mr-2" aria-hidden="true"></i>Consultando os Correios para os 27 estados…
        </div>
        </div>

        <!-- Aba Melhor Envio -->
        <div v-show="aba === 'melhor-envio'">
            <div
                v-if="ambienteTeste"
                class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100"
            >
                <i class="fas fa-flask mr-1" aria-hidden="true"></i>
                <strong>Ambiente de teste (sandbox) do Melhor Envio.</strong> Os preços daqui servem para testar a tela, não são os da conta real.
            </div>

            <div v-if="precisaConectar" class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-3 text-sm shadow-sm">
                <span>{{ erroMe || 'Nenhuma conta do Melhor Envio está conectada.' }}</span>
                <a href="/api/melhorenvio/auth" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 font-medium text-white hover:bg-primary-emphasis">
                    <i class="fas fa-plug" aria-hidden="true"></i>Conectar Melhor Envio
                </a>
            </div>
            <div v-else-if="erroMe" role="alert" class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                {{ erroMe }}
            </div>

            <template v-if="linhasMe.length && !resultadoMe?.reconectar">
                <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <CardStats compact stat-subtitle="MAIS BARATO DO PAÍS" :stat-title="dinheiro(faixaMe?.min)" stat-icon-name="fas fa-arrow-down" variant="success" />
                    <CardStats compact stat-subtitle="MAIS CARO DO PAÍS" :stat-title="dinheiro(faixaMe?.max)" stat-icon-name="fas fa-arrow-up" variant="error" />
                    <CardStats compact stat-subtitle="MÉDIA (MAIS BARATO)" :stat-title="dinheiro(faixaMe?.media)" stat-icon-name="fas fa-scale-balanced" variant="primary" />
                    <CardStats compact stat-subtitle="ESTADOS MAIS BARATOS QUE O CONTRATO" :stat-title="`${estadosMaisBaratosNoMe} de ${linhasMe.length}`" stat-icon-name="fas fa-piggy-bank" variant="info" />
                </div>

                <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--surface-border)] px-4 py-3">
                        <input
                            v-model="busca"
                            type="search"
                            placeholder="Buscar estado ou UF"
                            aria-label="Buscar estado ou UF"
                            class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm sm:w-64"
                        >
                        <label class="flex items-center gap-2 text-sm">
                            <span class="text-slate-500 dark:text-slate-400">Ordenar por</span>
                            <select v-model="ordem" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
                                <option value="regiao">Região</option>
                                <option value="uf">UF</option>
                                <option value="pac">Mais barato</option>
                            </select>
                        </label>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                    <th scope="col" class="px-4 py-2 font-semibold">Estado</th>
                                    <th scope="col" class="px-4 py-2 font-semibold">Mais barato</th>
                                    <th scope="col" class="px-4 py-2 text-right font-semibold">Preço</th>
                                    <th scope="col" class="px-4 py-2 text-right font-semibold">Prazo</th>
                                    <th scope="col" class="px-4 py-2 text-right font-semibold">Correios contrato</th>
                                    <th scope="col" class="px-4 py-2 text-right font-semibold">Diferença</th>
                                    <th scope="col" class="px-4 py-2"><span class="sr-only">Opções</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="l in filtradasMe" :key="l.uf">
                                    <tr class="border-t border-[var(--surface-border)]">
                                        <td class="px-4 py-2">
                                            <span class="inline-flex items-center gap-2">
                                                <span class="inline-flex w-9 justify-center rounded bg-lightprimary py-0.5 text-xs font-bold text-primary">{{ l.uf }}</span>
                                                <span>
                                                    <span class="font-semibold">{{ l.estado }}</span>
                                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ l.capital }} · {{ cep(l.cep) }}</span>
                                                </span>
                                            </span>
                                        </td>
                                        <td class="px-4 py-2">
                                            <template v-if="l.opcoes[0]">
                                                <span class="font-semibold">{{ l.opcoes[0].transportadora }}</span>
                                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ l.opcoes[0].servico }}</span>
                                            </template>
                                            <span v-else class="text-xs text-slate-500">sem cotação</span>
                                        </td>
                                        <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ dinheiro(l.opcoes[0]?.preco) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ dias(l.opcoes[0]?.prazo) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ dinheiro(correiosPorUf[l.uf]) }}</td>
                                        <td
                                            class="px-4 py-2 text-right font-semibold tabular-nums"
                                            :class="diferenca(l) === null ? '' : diferenca(l) < 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'"
                                        >
                                            <template v-if="diferenca(l) !== null">{{ diferenca(l) < 0 ? '−' : '+' }}{{ dinheiro(Math.abs(diferenca(l))) }}</template>
                                            <template v-else>—</template>
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            <button
                                                v-if="l.opcoes.length > 1"
                                                type="button"
                                                class="whitespace-nowrap text-xs font-semibold text-primary hover:underline"
                                                :aria-expanded="!!abertos[l.uf]"
                                                @click="abertos[l.uf] = !abertos[l.uf]"
                                            >
                                                {{ abertos[l.uf] ? 'ocultar' : `ver todas (${l.opcoes.length})` }}
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="abertos[l.uf]" class="bg-slate-50 dark:bg-slate-900/40">
                                        <td colspan="7" class="px-4 py-2">
                                            <ul class="grid grid-cols-1 gap-1 sm:grid-cols-2 lg:grid-cols-3">
                                                <li v-for="(o, i) in l.opcoes" :key="i" class="flex justify-between gap-3 rounded-lg px-2 py-1 text-xs">
                                                    <span><strong>{{ o.transportadora }}</strong> {{ o.servico }}</span>
                                                    <span class="tabular-nums">{{ dinheiro(o.preco) }} · {{ dias(o.prazo) }}</span>
                                                </li>
                                            </ul>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="!filtradasMe.length">
                                    <td colspan="7" class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">Nenhum estado encontrado.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="border-t border-[var(--surface-border)] px-4 py-2 text-xs text-slate-500 dark:text-slate-400">
                        "Correios contrato" é o mais barato entre PAC e SEDEX da aba Correios para o mesmo pacote. Diferença em verde: o Melhor Envio sai mais barato.
                        Prazo em dias úteis. Valores guardados por 6 horas.
                    </p>
                </section>
            </template>

            <div v-else-if="carregandoMe" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                <i class="fas fa-spinner fa-spin mr-2" aria-hidden="true"></i>Consultando o Melhor Envio para os 27 estados…
            </div>
        </div>
    </AdminLayout>
</template>
