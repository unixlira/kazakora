<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    produtos: { type: Array, default: () => [] },
    pacotePadrao: { type: Object, required: true },
});

const REGIOES = ['Sudeste', 'Sul', 'Centro-Oeste', 'Nordeste', 'Norte'];

const pacote = reactive({ ...props.pacotePadrao });
const produtoId = ref('');
const busca = ref('');
const ordem = ref('regiao');
const carregando = ref(false);
const erro = ref('');
const resultado = ref(null);

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
    consultar();
}

async function consultar() {
    carregando.value = true;
    erro.value = '';
    try {
        const r = await fetch('/admin/logistica/fretes-por-estado/cotar', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') },
            body: JSON.stringify(pacote),
        });
        const dados = await r.json();
        if (r.status === 422) {
            erro.value = Object.values(dados.errors ?? {}).flat().join(' ') || 'Medidas inválidas.';
            return;
        }
        if (!r.ok) throw new Error();
        resultado.value = dados;
        erro.value = dados.erro ?? '';
    } catch {
        erro.value = 'Não consegui consultar os Correios agora. Tente de novo em instantes.';
    } finally {
        carregando.value = false;
    }
}

onMounted(consultar);

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

const pacoteTexto = computed(() => `${new Intl.NumberFormat('pt-BR').format(pacote.peso_g)} g · ${pacote.comprimento} × ${pacote.largura} × ${pacote.altura} cm`);
const campos = [
    { chave: 'peso_g', rotulo: 'Peso (g)', min: 1, max: 30000, passo: 1 },
    { chave: 'comprimento', rotulo: 'Comprimento (cm)', min: 16, max: 100, passo: 0.5 },
    { chave: 'largura', rotulo: 'Largura (cm)', min: 11, max: 100, passo: 0.5 },
    { chave: 'altura', rotulo: 'Altura (cm)', min: 2, max: 100, passo: 0.5 },
];
</script>

<template>
    <Head title="Fretes por estado" />

    <AdminLayout>
        <div class="mb-2 flex items-center justify-between">
            <h1 class="text-2xl font-bold">Fretes por estado</h1>
        </div>
        <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
            Preço e prazo do PAC e do SEDEX com o contrato da KazaKora, saindo do CEP da empresa{{ resultado?.origem ? ` (${cep(resultado.origem)})` : '' }}
            para a capital de cada estado. No interior o valor e o prazo podem mudar.
        </p>

        <form class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" @submit.prevent="consultar">
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
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
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
                    :disabled="carregando"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50"
                >
                    <i :class="carregando ? 'fas fa-spinner fa-spin' : 'fas fa-magnifying-glass-dollar'" aria-hidden="true"></i>
                    {{ carregando ? 'Consultando…' : 'Consultar' }}
                </button>
            </div>
            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                Pacote: {{ pacoteTexto }}. Ao escolher um produto, o peso e as medidas vêm do cadastro fiscal dele.
            </p>
        </form>

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
    </AdminLayout>
</template>
