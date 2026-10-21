<script setup>
// "O que fazer agora" (pedido do Lira 2026-10-21; textos do Jonathan). As
// regras rodam no servidor (MetaAdsDiagnostico) sobre o espelho da Meta.
// "Editar" abre o modal que já existe, "Abrir no Gerenciador" leva para a
// Meta e "Pausar criativo" abre a confirmação da tabela (nada sem clique).
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatarNumero, formatarReais, PRIORIDADES } from './metaAds';

const props = defineProps({
    diagnostico: { type: Object, default: null },
    periodos: { type: Object, default: () => ({}) },
    titulo: { type: String, default: 'O que fazer agora' },
    // Quantos cartões antes do "Ver todas".
    limite: { type: Number, default: 5 },
    // Tela resumo: sem Ver na tabela / Editar; "Ver todas" vira link.
    compacto: { type: Boolean, default: false },
    linkVerTodas: { type: String, default: '/admin/publicidade/meta-ads/campanhas' },
    podeEditar: { type: Boolean, default: false },
});

const emit = defineEmits(['ver', 'editar', 'pausar', 'ultimos7']);

const idTitulo = `diagnostico-titulo-${Math.random().toString(36).slice(2, 8)}`;
const todas = ref(false);
const cartoes = computed(() => props.diagnostico?.cartoes ?? []);
const visiveis = computed(() => (todas.value ? cartoes.value : cartoes.value.slice(0, props.limite)));
const hora = computed(() => {
    const em = props.diagnostico?.gerado_em;
    return em ? new Date(em).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' }) : null;
});
const periodoRotulo = computed(() => (props.periodos[props.diagnostico?.periodo] ?? 'Últimos 7 dias').toLowerCase());
const roasMinimo = computed(() => props.diagnostico?.roas_minimo ?? null);
const decimal = (v) => Number(v).toFixed(2).replace('.', ',');
const tipoRotulo = { anuncio: 'Anúncio', conjunto: 'Conjunto' };
</script>

<template>
    <section v-if="diagnostico" class="mb-5 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" :aria-labelledby="idTitulo">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
            <div class="min-w-0">
                <h3 :id="idTitulo" class="text-base font-bold">{{ titulo }}</h3>
                <p class="text-xs text-slate-500">
                    Baseado em {{ periodoRotulo }}.<template v-if="hora"> Atualizado às {{ hora }}.</template>
                    <template v-if="roasMinimo">
                        ROAS mínimo para não perder dinheiro: <strong class="tabular-nums">{{ decimal(roasMinimo.valor) }}</strong>
                        <span :title="roasMinimo.margem ? `Margem antes do ADS: ${(roasMinimo.margem * 100).toFixed(0)}%` : undefined">({{ roasMinimo.origem_rotulo }})</span>.
                    </template>
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    ROAS da Meta = vendas que a Meta atribui ao anúncio ÷ gasto. Lucro de verdade fica no
                    <Link href="/admin/dashboard-financeiro" class="font-medium text-primary hover:underline">Dashboard financeiro</Link>
                    (margem de contribuição).
                </p>
            </div>
            <span v-if="diagnostico.total" class="shrink-0 text-xs font-semibold text-slate-500">{{ diagnostico.total }} {{ diagnostico.total === 1 ? 'sugestão' : 'sugestões' }}</span>
        </div>

        <p v-if="diagnostico.erro" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="alert">
            Não deu para ler a Meta agora: {{ diagnostico.erro }}
        </p>

        <div v-if="diagnostico.periodo_longo && !compacto" class="mb-3 flex flex-wrap items-center gap-3 rounded-lg bg-slate-500/5 px-3 py-2 text-sm" role="note">
            <i class="fas fa-clock-rotate-left text-slate-400" aria-hidden="true"></i>
            <span class="text-slate-600 dark:text-slate-300">Período longo mistura resultados antigos. Para decidir, prefira os últimos 7 dias.</span>
            <button type="button" class="rounded-md border border-primary/40 px-2 py-0.5 text-xs font-medium text-primary hover:bg-primary/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                @click="emit('ultimos7')">Ver últimos 7 dias</button>
        </div>

        <div v-if="!diagnostico.erro && diagnostico.cedo" class="mb-3 rounded-lg bg-slate-500/5 p-3 text-sm" role="status">
            <p class="font-semibold">Ainda é cedo para diagnosticar.</p>
            <p class="text-slate-600 dark:text-slate-300">
                Com <span class="tabular-nums">{{ formatarReais(diagnostico.escopo.gasto) }}</span> gastos e
                <span class="tabular-nums">{{ formatarNumero(diagnostico.escopo.impressoes) }}</span> impressões, qualquer conclusão seria chute.
                Deixe rodar até passar de R$ 30 ou 1.000 impressões.
            </p>
        </div>
        <div v-else-if="!diagnostico.erro && diagnostico.estado === 'vazio'" class="rounded-lg bg-emerald-500/5 p-3 text-sm" role="status">
            <p class="font-semibold">Nada urgente por aqui.</p>
            <p class="text-slate-600 dark:text-slate-300">Nenhum anúncio está gastando sem retorno no período. Confira de novo em 2 ou 3 dias.</p>
        </div>

        <ul v-if="visiveis.length" class="space-y-2.5">
            <li v-for="cartao in visiveis" :key="cartao.id"
                class="relative overflow-hidden rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] py-3 pl-5 pr-4">
                <span class="absolute inset-y-0 left-0 w-1" :class="PRIORIDADES[cartao.prioridade][2]" aria-hidden="true"></span>
                <div class="flex flex-wrap items-start gap-3">
                    <img v-if="cartao.alvo.miniatura" :src="cartao.alvo.miniatura" alt="" width="40" height="40" class="h-10 w-10 shrink-0 rounded object-cover" loading="lazy" referrerpolicy="no-referrer">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide" :class="PRIORIDADES[cartao.prioridade][1]">{{ PRIORIDADES[cartao.prioridade][0] }}</span>
                            <strong class="text-sm">{{ cartao.titulo }}</strong>
                        </p>
                        <p class="mt-1 text-xs tabular-nums text-slate-500">{{ cartao.prova.join(' · ') }}</p>
                        <p class="mt-1 text-sm text-slate-700 dark:text-slate-200">{{ cartao.acao }}</p>
                        <p class="mt-1 truncate text-xs text-slate-500" :title="`${cartao.alvo.campanha} › ${cartao.alvo.conjunto}`">
                            {{ tipoRotulo[cartao.alvo.tipo] }} · {{ cartao.alvo.nome }}
                            <span v-if="compacto || cartao.alvo.tipo === 'anuncio'" class="text-slate-400"> · {{ cartao.alvo.campanha }}</span>
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button v-if="!compacto" type="button"
                                class="inline-flex items-center gap-1 rounded-md border border-[var(--surface-border)] px-2 py-0.5 text-xs font-medium hover:bg-[var(--surface-muted)] focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                                @click="emit('ver', cartao.alvo)">
                                <i class="fas fa-table-list text-[10px]" aria-hidden="true"></i> Ver na tabela
                            </button>
                            <a v-if="cartao.alvo.gerenciador" :href="cartao.alvo.gerenciador" target="_blank" rel="noopener"
                                class="inline-flex items-center gap-1 rounded-md border border-[var(--surface-border)] px-2 py-0.5 text-xs font-medium text-primary hover:bg-primary/10">
                                <i class="fab fa-meta text-[10px]" aria-hidden="true"></i> Abrir no Gerenciador
                                <span class="sr-only">(abre em outra aba)</span>
                            </a>
                            <button v-if="!compacto && podeEditar" type="button"
                                class="inline-flex items-center gap-1 rounded-md border border-primary/40 px-2 py-0.5 text-xs font-medium text-primary hover:bg-primary/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                                @click="emit('editar', cartao.alvo)">
                                <i class="fas fa-pen text-[10px]" aria-hidden="true"></i> Editar
                            </button>
                            <!-- Pausar criativo (Lira 2026-10-21): o servidor confere se o anúncio ainda está ativo. -->
                            <button v-if="!compacto && podeEditar && cartao.pausar" type="button"
                                class="inline-flex items-center gap-1 rounded-md border border-amber-400 px-2 py-0.5 text-xs font-medium text-amber-800 hover:bg-amber-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300 dark:text-amber-300 dark:hover:bg-amber-500/10"
                                @click="emit('pausar', cartao.alvo)">
                                <i class="fas fa-pause text-[10px]" aria-hidden="true"></i> Pausar criativo
                            </button>
                        </div>
                    </div>
                </div>
            </li>
        </ul>

        <div v-if="cartoes.length > limite" class="mt-3">
            <Link v-if="compacto" :href="linkVerTodas" class="text-sm font-medium text-primary hover:underline">Ver todas na tela de campanhas ({{ cartoes.length }})</Link>
            <button v-else type="button" class="text-sm font-medium text-primary hover:underline" :aria-expanded="todas" @click="todas = !todas">
                {{ todas ? 'Mostrar menos' : `Ver todas (${cartoes.length})` }}
            </button>
        </div>
        <Link v-else-if="compacto && cartoes.length" :href="linkVerTodas" class="mt-3 inline-block text-sm font-medium text-primary hover:underline">Ver todas na tela de campanhas</Link>

        <p v-if="diagnostico.sem_dados" class="mt-3 text-xs text-slate-500">
            {{ diagnostico.sem_dados }} {{ diagnostico.sem_dados === 1 ? 'anúncio ainda sem dados suficientes, fora' : 'anúncios ainda sem dados suficientes, fora' }} da análise.
        </p>
    </section>
</template>
