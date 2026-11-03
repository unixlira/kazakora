<script setup>
// Aba Melhor Envio, "Frete de todos os produtos" (pedido do Lira em 2026-11-03):
// o frete salvo no banco para a capital do estado, por categoria de tamanho,
// com todos os produtos cadastrados de cada categoria. Os valores do Melhor
// Envio ficam guardados mesmo com os Correios conectados (o site só usa o
// Melhor Envio quando ele for conectado em Logística).
import { computed, onMounted, ref } from 'vue';

const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

const uf = ref('SP');
const carregando = ref(false);
const erro = ref('');
const dados = ref(null);
const busca = ref('');
const abertas = ref({});

const dinheiro = (v) => (v === null || v === undefined ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v));
const dias = (v) => (v === null || v === undefined ? '—' : `${v} ${v === 1 ? 'dia útil' : 'dias úteis'}`);
const cep = (v) => (v ? `${v.slice(0, 5)}-${v.slice(5)}` : '');
const data = (v) => (v ? new Date(v.replace(' ', 'T')).toLocaleDateString('pt-BR') : '—');

async function carregar() {
    carregando.value = true;
    erro.value = '';
    try {
        const r = await fetch(`/admin/logistica/fretes-por-estado/produtos-melhor-envio?uf=${uf.value}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error();
        dados.value = await r.json();
    } catch {
        erro.value = 'Não consegui carregar a tabela salva agora. Tente de novo em instantes.';
    }
    carregando.value = false;
}

onMounted(carregar);

const correiosMaisBarato = (c) => {
    const v = [c?.barato?.preco, c?.rapido?.preco].filter((x) => x !== null && x !== undefined);
    return v.length ? Math.min(...v) : null;
};

const filtrar = (lista) => {
    const termo = busca.value.trim().toLowerCase();
    return termo ? lista.filter((p) => `${p.nome} ${p.sku ?? ''}`.toLowerCase().includes(termo)) : lista;
};

const total = computed(() => (dados.value?.categorias ?? []).reduce((s, c) => s + c.produtos.length, 0) + (dados.value?.sem_categoria?.length ?? 0));
const pronto = computed(() => {
    const p = dados.value?.preenchimento;
    return p && p.ceps ? Math.floor((p.prontos / p.ceps) * 100) : 0;
});
</script>

<template>
    <section class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--surface-border)] px-4 py-3">
            <div>
                <h2 class="text-base font-semibold">Frete de todos os produtos</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Valor salvo no banco para a capital do estado, por categoria de tamanho.
                    <template v-if="dados">{{ dados.capital }} ({{ dados.uf }}) · CEP {{ cep(dados.cep) }} · {{ total }} produtos.</template>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <input
                    v-model="busca"
                    type="search"
                    placeholder="Buscar produto ou SKU"
                    aria-label="Buscar produto ou SKU"
                    class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm sm:w-56"
                >
                <label class="flex items-center gap-2 text-sm">
                    <span class="text-slate-500 dark:text-slate-400">Estado</span>
                    <select v-model="uf" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm" @change="carregar">
                        <option v-for="u in UFS" :key="u" :value="u">{{ u }}</option>
                    </select>
                </label>
            </div>
        </div>

        <div v-if="dados" class="border-b border-[var(--surface-border)] px-4 py-2 text-xs text-slate-600 dark:text-slate-300">
            <i class="fas fa-circle-info mr-1 text-primary" aria-hidden="true"></i>
            Frete ativo no site: <strong>{{ dados.ativo }}</strong>. Os valores do Melhor Envio ficam guardados e só entram no preço quando ele for conectado em Logística.
            Tabela do Melhor Envio: {{ dados.preenchimento.prontos }} de {{ dados.preenchimento.ceps }} CEPs ({{ pronto }}%), última cotação {{ data(dados.preenchimento.ultima_cotacao) }}.
            <span v-if="dados.preenchimento.andamento.fase !== 'parado'" class="font-semibold text-primary">Preenchendo agora…</span>
            <span v-if="dados.preenchimento.andamento.erro" class="text-red-700 dark:text-red-400">{{ dados.preenchimento.andamento.erro }}</span>
        </div>

        <div v-if="erro" role="alert" class="m-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ erro }}</div>
        <div v-else-if="carregando && !dados" class="px-4 py-10 text-center text-sm text-slate-500 dark:text-slate-400">
            <i class="fas fa-spinner fa-spin mr-2" aria-hidden="true"></i>Carregando a tabela salva…
        </div>

        <div v-if="dados" class="overflow-x-auto" :class="{ 'opacity-60': carregando }">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <th scope="col" class="px-4 py-2 font-semibold">Produto</th>
                        <th scope="col" class="px-4 py-2 font-semibold">Melhor Envio: mais barato</th>
                        <th scope="col" class="px-4 py-2 text-right font-semibold">Preço</th>
                        <th scope="col" class="px-4 py-2 text-right font-semibold">Prazo</th>
                        <th scope="col" class="px-4 py-2 font-semibold">Mais rápido</th>
                        <th scope="col" class="px-4 py-2 text-right font-semibold">Correios contrato</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="c in dados.categorias" :key="c.chave">
                        <tr class="border-t border-[var(--surface-border)] bg-slate-50 dark:bg-slate-900/40">
                            <th scope="rowgroup" class="px-4 py-2 text-left">
                                <button type="button" class="inline-flex items-center gap-2 font-semibold" :aria-expanded="abertas[c.chave] !== false" @click="abertas[c.chave] = abertas[c.chave] === false">
                                    <i :class="abertas[c.chave] === false ? 'fas fa-chevron-right' : 'fas fa-chevron-down'" class="text-xs text-slate-400" aria-hidden="true"></i>
                                    {{ c.nome }}
                                    <span class="rounded-full bg-lightprimary px-2 text-xs text-primary">{{ c.produtos.length }}</span>
                                </button>
                                <span class="block text-xs font-normal text-slate-500 dark:text-slate-400">{{ c.exemplo }}</span>
                            </th>
                            <td class="px-4 py-2">
                                <span v-if="c.melhor_envio?.barato" class="font-semibold">{{ c.melhor_envio.barato.servico }}</span>
                                <span v-else-if="c.melhor_envio?.erro" class="text-xs text-slate-500">{{ c.melhor_envio.erro }}</span>
                                <span v-else class="text-xs text-slate-500">ainda sem cotação</span>
                            </td>
                            <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ dinheiro(c.melhor_envio?.barato?.preco) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ dias(c.melhor_envio?.barato?.prazo) }}</td>
                            <td class="px-4 py-2">
                                <template v-if="c.melhor_envio?.rapido">
                                    {{ c.melhor_envio.rapido.servico }}
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ dinheiro(c.melhor_envio.rapido.preco) }} · {{ dias(c.melhor_envio.rapido.prazo) }}</span>
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ dinheiro(correiosMaisBarato(c.correios)) }}</td>
                        </tr>
                        <template v-if="abertas[c.chave] !== false">
                            <tr v-for="p in filtrar(c.produtos)" :key="p.id" class="border-t border-[var(--surface-border)]">
                                <td class="px-4 py-1.5 pl-10">
                                    {{ p.nome }}
                                    <span class="text-xs text-slate-500 dark:text-slate-400">{{ p.sku ? `(${p.sku})` : '' }}</span>
                                    <span v-if="!p.ativo" class="ml-1 rounded bg-slate-200 px-1 text-[10px] uppercase text-slate-600 dark:bg-slate-700 dark:text-slate-300">inativo</span>
                                </td>
                                <td class="px-4 py-1.5 text-xs text-slate-500 dark:text-slate-400">{{ c.melhor_envio?.barato?.servico ?? '—' }}</td>
                                <td class="px-4 py-1.5 text-right tabular-nums">{{ dinheiro(c.melhor_envio?.barato?.preco) }}</td>
                                <td class="px-4 py-1.5 text-right tabular-nums">{{ dias(c.melhor_envio?.barato?.prazo) }}</td>
                                <td class="px-4 py-1.5 text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ c.melhor_envio?.rapido ? `${dinheiro(c.melhor_envio.rapido.preco)} · ${dias(c.melhor_envio.rapido.prazo)}` : '—' }}</td>
                                <td class="px-4 py-1.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ dinheiro(correiosMaisBarato(c.correios)) }}</td>
                            </tr>
                        </template>
                    </template>
                    <template v-if="dados.sem_categoria.length">
                        <tr class="border-t border-[var(--surface-border)] bg-amber-50 dark:bg-amber-950/40">
                            <th scope="rowgroup" colspan="6" class="px-4 py-2 text-left">
                                Sem categoria de tamanho <span class="rounded-full bg-amber-200 px-2 text-xs text-amber-900">{{ dados.sem_categoria.length }}</span>
                                <span class="block text-xs font-normal text-slate-500 dark:text-slate-400">Sem frete na tabela até ganhar uma categoria (peso e medidas no cadastro).</span>
                            </th>
                        </tr>
                        <tr v-for="p in filtrar(dados.sem_categoria)" :key="p.id" class="border-t border-[var(--surface-border)]">
                            <td colspan="6" class="px-4 py-1.5 pl-10">
                                {{ p.nome }}
                                <span class="text-xs text-slate-500 dark:text-slate-400">{{ p.sku ? `(${p.sku})` : '' }}</span>
                                <span v-if="!p.ativo" class="ml-1 rounded bg-slate-200 px-1 text-[10px] uppercase text-slate-600 dark:bg-slate-700 dark:text-slate-300">inativo</span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p class="border-t border-[var(--surface-border)] px-4 py-2 text-xs text-slate-500 dark:text-slate-400">
            O frete vale pela categoria do produto (caixa de referência dela). "Correios contrato" é o mais barato entre PAC e SEDEX salvos para a mesma categoria. Prazo em dias úteis.
        </p>
    </section>
</template>
