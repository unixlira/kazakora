<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

// Oportunidades (pedido do Lira 2026-10-12): curva ABC de um marketplace com
// lucro pela conta oficial (ContributionMargin) e tendência contra o período
// anterior. Ver OportunidadesController e CurvaAbc.
const props = defineProps({
    canal: { type: Object, required: true },
    filtros: { type: Object, required: true },
    curva: { type: Object, required: true },
    limites: { type: Object, required: true },
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
const formatPercent = (value) => `${(value ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
const formatNumber = (value) => (value ?? 0).toLocaleString('pt-BR');
const formatDate = (iso) => iso.split('-').reverse().join('/');

const CLASSES = {
    A: { color: 'var(--color-success)', description: `Somam até ${props.limites.a}% do faturamento` },
    B: { color: 'var(--color-info)', description: `Os próximos ${props.limites.b - props.limites.a}% do faturamento` },
    C: { color: 'var(--color-warning)', description: `Os últimos ${100 - props.limites.b}% do faturamento` },
};

// Período: atalhos e datas livres. Datas no fuso da loja (São Paulo).
const hojeSp = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Sao_Paulo' }).format(new Date());
const somarDias = (iso, dias) => {
    const d = new Date(`${iso}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + dias);
    return d.toISOString().slice(0, 10);
};
const atalhos = computed(() => {
    const hoje = hojeSp();
    const inicioMes = `${hoje.slice(0, 8)}01`;
    const fimMesPassado = somarDias(inicioMes, -1);
    return [
        { label: '7 dias', de: somarDias(hoje, -6), ate: hoje },
        { label: '30 dias', de: somarDias(hoje, -29), ate: hoje },
        { label: '90 dias', de: somarDias(hoje, -89), ate: hoje },
        { label: 'Este mês', de: inicioMes, ate: hoje },
        { label: 'Mês passado', de: `${fimMesPassado.slice(0, 8)}01`, ate: fimMesPassado },
    ];
});
const periodo = reactive({ de: props.filtros.de, ate: props.filtros.ate });
const carregando = ref(false);
const aplicar = (de = periodo.de, ate = periodo.ate) => {
    router.get(`/admin/oportunidades/${props.canal.slug}`, { de, ate }, {
        preserveScroll: true,
        onStart: () => { carregando.value = true; },
        onFinish: () => { carregando.value = false; },
    });
};
const atalhoAtivo = (a) => a.de === props.filtros.de && a.ate === props.filtros.ate;

// Filtro da tabela (só na tela, sem ir ao servidor).
const classe = ref('');
const busca = ref('');
const linhas = computed(() => {
    const termo = busca.value.trim().toLocaleLowerCase('pt-BR');
    return props.curva.produtos.filter((p) => (!classe.value || p.classe === classe.value)
        && (!termo || p.nome.toLocaleLowerCase('pt-BR').includes(termo)));
});

const lucroClasse = (valor) => (valor < 0 ? 'text-error' : '');
const tendenciaTexto = (p) => {
    if (p.tendencia === null) return 'Novo';
    const seta = p.tendencia > 0 ? '▲' : p.tendencia < 0 ? '▼' : '■';
    return `${seta} ${formatPercent(Math.abs(p.tendencia))}`;
};
const tendenciaCor = (p) => {
    if (p.tendencia === null) return 'text-info';
    if (p.tendencia >= 10) return 'text-success';
    if (p.tendencia <= -10) return 'text-error';
    return 'text-[var(--texto-suave)]';
};
</script>

<template>
    <Head :title="`Oportunidades · ${canal.nome}`" />

    <AdminLayout>
        <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-[var(--texto-suave)]">Oportunidades</p>
                <h1 class="text-2xl font-bold">Curva ABC · {{ canal.nome }}</h1>
                <p class="mt-1 text-sm text-[var(--texto-suave)]">
                    Vendas efetivas de {{ formatDate(filtros.de) }} a {{ formatDate(filtros.ate) }} · variações do mesmo produto juntas · lucro pela margem de contribuição
                </p>
            </div>
        </div>

        <!-- Período -->
        <form class="mb-6 flex flex-wrap items-end gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" @submit.prevent="aplicar()">
            <div class="flex flex-wrap gap-2" role="group" aria-label="Atalhos de período">
                <button v-for="a in atalhos" :key="a.label" type="button"
                    class="rounded-full border px-3 py-1.5 text-sm transition"
                    :class="atalhoAtivo(a) ? 'border-transparent bg-[var(--primary-solido)] text-white' : 'border-[var(--surface-border)] hover:bg-[var(--surface-muted)]'"
                    :aria-pressed="atalhoAtivo(a)"
                    @click="periodo.de = a.de; periodo.ate = a.ate; aplicar(a.de, a.ate)">
                    {{ a.label }}
                </button>
            </div>
            <div class="ml-auto flex flex-wrap items-end gap-2">
                <label class="text-xs text-[var(--texto-suave)]">De
                    <input v-model="periodo.de" type="date" name="de" required class="mt-1 block rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
                </label>
                <label class="text-xs text-[var(--texto-suave)]">Até
                    <input v-model="periodo.ate" type="date" name="ate" required class="mt-1 block rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
                </label>
                <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50" :disabled="carregando">
                    {{ carregando ? 'Carregando…' : 'Aplicar' }}
                </button>
            </div>
        </form>

        <!-- Resumo -->
        <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <button v-for="(info, key) in CLASSES" :key="key" type="button"
                class="overflow-hidden rounded-xl border bg-[var(--surface)] p-4 text-left shadow-sm transition hover:shadow-md"
                :class="classe === key ? 'border-[var(--destaque)] ring-2 ring-[var(--destaque)]' : 'border-[var(--surface-border)]'"
                :style="{ background: `linear-gradient(135deg, color-mix(in oklab, ${info.color} 10%, var(--surface)) 0%, var(--surface) 75%)` }"
                :aria-pressed="classe === key"
                @click="classe = classe === key ? '' : key">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-base font-bold text-white" :style="{ background: info.color }">{{ key }}</span>
                    <div class="min-w-0">
                        <p class="font-semibold">Curva {{ key }} · {{ curva.classes[key].produtos }} produto(s)</p>
                        <p class="text-xs text-[var(--texto-suave)]">{{ info.description }}</p>
                    </div>
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                    <div><dt class="text-xs text-[var(--texto-suave)]">Faturamento</dt><dd class="font-semibold tabular-nums">{{ formatPrice(curva.classes[key].receita) }}</dd></div>
                    <div><dt class="text-xs text-[var(--texto-suave)]">Lucro antes do ADS</dt><dd class="font-semibold tabular-nums" :class="lucroClasse(curva.classes[key].lucro)">{{ formatPrice(curva.classes[key].lucro) }}</dd></div>
                </dl>
            </button>

            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-label="Lucro do canal">
                <p class="font-semibold">Lucro do {{ canal.nome }}</p>
                <dl class="mt-2 space-y-1 text-sm tabular-nums">
                    <div class="flex justify-between gap-3"><dt class="text-[var(--texto-suave)]">Faturamento</dt><dd>{{ formatPrice(curva.total) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-[var(--texto-suave)]">Lucro dos produtos</dt><dd :class="lucroClasse(curva.lucro_produtos)">{{ formatPrice(curva.lucro_produtos) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-[var(--texto-suave)]">− ADS do canal</dt><dd>{{ formatPrice(curva.ads) }}</dd></div>
                    <div v-if="curva.flex > 0 || curva.bonificacoes > 0" class="flex justify-between gap-3"><dt class="text-[var(--texto-suave)]">− Flex + bônus</dt><dd>{{ formatPrice(curva.flex - curva.bonificacoes) }}</dd></div>
                    <div class="flex justify-between gap-3 border-t border-[var(--surface-border)] pt-1 font-semibold"><dt>Lucro do canal</dt><dd :class="lucroClasse(curva.lucro)">{{ formatPrice(curva.lucro) }}</dd></div>
                </dl>
                <p class="mt-2 text-xs text-[var(--texto-suave)]">
                    Período anterior ({{ formatDate(curva.anterior.desde) }} a {{ formatDate(curva.anterior.ate) }}): {{ formatPrice(curva.anterior.total) }}
                </p>
            </section>
        </div>

        <!-- Tabela -->
        <section class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" style="border-top: 3px solid var(--faixa-card)">
            <div class="flex flex-wrap items-center gap-3 border-b border-[var(--surface-border)] px-4 py-3">
                <h2 class="text-base font-semibold">Produtos ({{ linhas.length }})</h2>
                <span v-if="classe" class="rounded-full bg-[var(--surface-muted)] px-2 py-0.5 text-xs">
                    Só curva {{ classe }}
                    <button type="button" class="ml-1 underline" @click="classe = ''">mostrar todas</button>
                </span>
                <label class="ml-auto w-full sm:w-64">
                    <span class="sr-only">Buscar produto</span>
                    <input v-model="busca" type="search" name="busca" autocomplete="off" placeholder="Buscar produto…"
                        class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-1.5 text-sm">
                </label>
            </div>

            <p v-if="curva.produtos.length === 0" class="px-4 py-8 text-center text-sm text-[var(--texto-suave)]">
                Nenhuma venda efetiva do {{ canal.nome }} neste período.
            </p>
            <p v-else-if="linhas.length === 0" class="px-4 py-8 text-center text-sm text-[var(--texto-suave)]">Nenhum produto com esse filtro.</p>

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[860px] text-left text-sm">
                    <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-[var(--texto-suave)]">
                        <tr>
                            <th scope="col" class="px-4 py-2">#</th>
                            <th scope="col" class="py-2 pr-3">Produto</th>
                            <th scope="col" class="py-2 pr-3 text-center">Classe</th>
                            <th scope="col" class="py-2 pr-3 text-right">Unidades</th>
                            <th scope="col" class="py-2 pr-3 text-right">Faturamento</th>
                            <th scope="col" class="py-2 pr-3 text-right">%</th>
                            <th scope="col" class="py-2 pr-3 text-right">% acum.</th>
                            <th scope="col" class="py-2 pr-3 text-right" title="Receita − custo do produto − taxa da plataforma − frete pago pela loja. ADS e Flex ficam no lucro do canal.">Lucro</th>
                            <th scope="col" class="py-2 pr-4 text-right" title="Faturamento contra o período anterior de mesmo tamanho">Tendência</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(p, i) in linhas" :key="p.chave" class="border-b border-[var(--surface-border)] last:border-0 hover:bg-[var(--surface-muted)]">
                            <td class="px-4 py-2 tabular-nums text-[var(--texto-suave)]">{{ curva.produtos.indexOf(p) + 1 }}</td>
                            <td class="max-w-[360px] py-2 pr-3">
                                <Link v-if="p.id" :href="`/admin/produtos/${p.id}/editar`" class="line-clamp-2 hover:text-[var(--destaque)] hover:underline">{{ p.nome }}</Link>
                                <span v-else class="line-clamp-2">{{ p.nome }}</span>
                                <span v-if="p.variacoes.length" class="block truncate text-xs text-[var(--texto-suave)]" :title="p.variacoes.join(', ')">
                                    {{ p.variacoes.length > 1 ? `${p.variacoes.length} cores: ` : '' }}{{ p.variacoes.join(', ') }}
                                </span>
                            </td>
                            <td class="py-2 pr-3 text-center">
                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold text-white" :style="{ background: CLASSES[p.classe].color }">{{ p.classe }}</span>
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ formatNumber(p.unidades) }}</td>
                            <td class="py-2 pr-3 text-right font-semibold tabular-nums">{{ formatPrice(p.receita) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ formatPercent(p.participacao) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ formatPercent(p.acumulado) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">
                                <span class="block font-semibold" :class="lucroClasse(p.lucro)">{{ formatPrice(p.lucro) }}</span>
                                <span v-if="p.margem_pct !== null" class="block text-xs text-[var(--texto-suave)]">{{ formatPercent(p.margem_pct) }} da venda</span>
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">
                                <span class="block font-semibold" :class="tendenciaCor(p)">{{ tendenciaTexto(p) }}</span>
                                <span class="block text-xs text-[var(--texto-suave)]">antes {{ formatPrice(p.receita_anterior) }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="border-t border-[var(--surface-border)] px-4 py-3 text-xs text-[var(--texto-suave)]">
                Lucro por produto = receita − custo do produto − taxa da plataforma − frete pago pela loja, com a taxa e o frete do pedido divididos pelo valor de cada item.
                ADS{{ curva.flex > 0 || curva.bonificacoes > 0 ? ', Flex e bônus do Flex' : '' }} são do canal e entram só no lucro do canal. Produto sem custo cadastrado aparece com lucro maior que o real.
            </p>
        </section>
    </AdminLayout>
</template>
