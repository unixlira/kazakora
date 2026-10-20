<script setup>
/**
 * Aba "Sugestões do dia" de Indicadores (pedido do Lira 2026-10-20): o board
 * que a rotina da madrugada (sugestoes:gerar) monta lendo o Meta Ads e o
 * preço dos concorrentes. Uma coluna por área (preço, criativo, página e
 * checkout, orçamento). Feito/Ignorar só registram; nada aqui muda preço
 * nem anúncio. Ignorada não volta por 7 dias.
 */
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    sugestoes: { type: Object, default: () => ({ dia: null, rodada: null, areas: [] }) },
});

const PRIORIDADES = {
    1: { rotulo: 'Urgente', cor: '#dc2626' },
    2: { rotulo: 'Atenção', cor: '#d97706' },
    3: { rotulo: 'Melhorar', cor: '#0d6efd' },
};
const ICONES = {
    preco: 'fas fa-tag',
    criativo: 'fas fa-photo-film',
    pagina: 'fas fa-cart-shopping',
    orcamento: 'fas fa-sack-dollar',
};

const ehAdmin = computed(() => usePage().props.auth?.user?.role === 'admin');
const enviando = ref(null);
const abertos = ref({});

const colunas = computed(() => (props.sugestoes?.areas ?? []).map((a) => ({
    ...a,
    abertas: a.sugestoes.filter((s) => s.status === 'aberta'),
    resolvidas: a.sugestoes.filter((s) => s.status !== 'aberta'),
})));
const totalAbertas = computed(() => colunas.value.reduce((n, c) => n + c.abertas.length, 0));
const urgentes = computed(() => colunas.value.reduce((n, c) => n + c.abertas.filter((s) => s.prioridade === 1).length, 0));

const dataBr = (iso) => (iso ? new Date(`${iso}T12:00:00`).toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: '2-digit' }) : '');
const horaBr = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' }) : '');
const reais = (v) => Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

function marcar(s, status) {
    enviando.value = s.id;
    router.post(`/admin/sugestoes/${s.id}/status`, { status }, {
        preserveScroll: true,
        only: ['sugestoes'],
        onFinish: () => { enviando.value = null; },
    });
}

function rodarAgora() {
    router.post('/admin/sugestoes/rodar', {}, { preserveScroll: true });
}
</script>

<template>
    <div>
        <section class="mb-4 flex flex-wrap items-start justify-between gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <div>
                <h2 class="text-lg font-bold">
                    Sugestões para {{ sugestoes?.dia ? dataBr(sugestoes.dia) : 'hoje' }}
                </h2>
                <p class="mt-0.5 text-sm text-[var(--texto-suave)]">
                    Toda madrugada a rotina lê o Meta Ads dos últimos 7 dias, pesquisa o preço dos concorrentes e cruza com a loja.
                    <template v-if="totalAbertas"> {{ totalAbertas }} {{ totalAbertas === 1 ? 'ação aberta' : 'ações abertas' }}<template v-if="urgentes">, {{ urgentes }} {{ urgentes === 1 ? 'urgente' : 'urgentes' }}</template>.</template>
                </p>
                <div v-if="sugestoes?.rodada" class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-[var(--texto-suave)]">Última rodada {{ horaBr(sugestoes.rodada.terminada_em ?? sugestoes.rodada.iniciada_em) }}:</span>
                    <span v-for="f in sugestoes.rodada.fontes" :key="f.nome"
                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold"
                        :class="f.ok ? 'bg-success/10 text-success' : 'bg-error/10 text-error'"
                        :title="f.erro ?? `${f.consultas} consultas, ${f.ofertas} resultados`">
                        <i :class="f.ok ? 'fas fa-check' : 'fas fa-triangle-exclamation'" aria-hidden="true"></i>
                        {{ f.nome }}<template v-if="f.ok"> · {{ f.ofertas }}</template><template v-else> · {{ f.erro }}</template>
                    </span>
                    <span v-if="sugestoes.rodada.erro" class="rounded-full bg-error/10 px-2 py-0.5 font-semibold text-error">Rodada falhou: {{ sugestoes.rodada.erro }}</span>
                </div>
            </div>
            <button v-if="ehAdmin" type="button"
                class="inline-flex items-center gap-2 rounded-lg border border-[var(--color-primary)] px-3 py-1.5 text-sm font-semibold text-[var(--color-primary)] transition hover:bg-[var(--color-primary)] hover:text-white"
                @click="rodarAgora">
                <i class="fas fa-rotate" aria-hidden="true"></i> Rodar agora
            </button>
        </section>

        <p v-if="!sugestoes?.dia" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 text-sm text-[var(--texto-suave)]">
            A primeira rodada sai na madrugada (02h30).
        </p>

        <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <section v-for="c in colunas" :key="c.chave" class="flex flex-col rounded-xl border border-[var(--surface-border)] bg-[var(--surface-muted)]/40">
                <header class="flex items-center justify-between gap-2 border-b border-[var(--surface-border)] px-3 py-2.5">
                    <h3 class="flex items-center gap-2 text-sm font-bold">
                        <i :class="ICONES[c.chave]" class="text-xs text-[var(--texto-suave)]" aria-hidden="true"></i>{{ c.rotulo }}
                    </h3>
                    <span class="rounded-full bg-[var(--surface)] px-2 text-xs font-semibold">{{ c.abertas.length }}</span>
                </header>

                <div class="flex flex-1 flex-col gap-3 p-3">
                    <p v-if="!c.abertas.length" class="text-sm text-[var(--texto-suave)]">Nada para ajustar aqui hoje.</p>

                    <article v-for="s in c.abertas" :key="s.id" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-3 shadow-sm"
                        :style="{ borderLeft: `4px solid ${PRIORIDADES[s.prioridade]?.cor}` }">
                        <div class="flex items-start gap-2">
                            <img v-if="s.miniatura" :src="s.miniatura" alt="" width="40" height="40" class="h-10 w-10 shrink-0 rounded object-cover" loading="lazy">
                            <div class="min-w-0 flex-1">
                                <span class="rounded px-1.5 text-[10px] font-bold text-white" :style="{ background: PRIORIDADES[s.prioridade]?.cor }">{{ PRIORIDADES[s.prioridade]?.rotulo }}</span>
                                <p class="mt-1 line-clamp-2 text-xs font-semibold text-[var(--texto-suave)]" :title="s.titulo">{{ s.titulo }}</p>
                            </div>
                        </div>
                        <p class="mt-2 text-sm font-semibold leading-snug">{{ s.acao }}</p>
                        <p v-if="s.motivo" class="mt-1 text-xs text-[var(--texto-suave)]">{{ s.motivo }}</p>

                        <button v-if="Object.keys(s.metricas).length || s.concorrentes.length" type="button"
                            class="mt-2 text-xs font-semibold text-primary hover:underline"
                            :aria-expanded="!!abertos[s.id]" @click="abertos[s.id] = !abertos[s.id]">
                            {{ abertos[s.id] ? 'Esconder números' : `Ver números${s.concorrentes.length ? ` e ${s.concorrentes.length} concorrentes` : ''}` }}
                        </button>
                        <div v-if="abertos[s.id]" class="mt-2 space-y-2">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                                <template v-for="(v, k) in s.metricas" :key="k">
                                    <dt class="text-[var(--texto-suave)]">{{ k }}</dt>
                                    <dd class="text-right font-semibold">{{ v }}</dd>
                                </template>
                            </dl>
                            <ul v-if="s.concorrentes.length" class="divide-y divide-[var(--surface-border)] rounded border border-[var(--surface-border)] text-xs">
                                <li v-for="(o, i) in s.concorrentes" :key="i" class="flex items-center justify-between gap-2 px-2 py-1">
                                    <a v-if="o.link" :href="o.link" target="_blank" rel="noopener" class="min-w-0 truncate hover:text-primary hover:underline" :title="o.titulo">{{ o.loja }}</a>
                                    <span v-else class="min-w-0 truncate" :title="o.titulo">{{ o.loja }}</span>
                                    <span class="shrink-0 font-semibold">{{ reais(o.preco) }}</span>
                                </li>
                            </ul>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <button type="button" :disabled="enviando === s.id"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-success px-2.5 py-1 text-xs font-semibold text-white transition hover:opacity-90 disabled:opacity-50"
                                @click="marcar(s, 'feita')">
                                <i class="fas fa-check" aria-hidden="true"></i> Feito
                            </button>
                            <button type="button" :disabled="enviando === s.id"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--surface-border)] px-2.5 py-1 text-xs font-semibold text-[var(--texto-suave)] transition hover:text-[var(--surface-foreground)] disabled:opacity-50"
                                title="Some por 7 dias" @click="marcar(s, 'ignorada')">
                                Ignorar
                            </button>
                            <a v-for="l in s.links" :key="l.href" :href="l.href" :target="l.externo ? '_blank' : undefined" :rel="l.externo ? 'noopener' : undefined"
                                class="text-xs font-semibold text-primary hover:underline">{{ l.rotulo }}</a>
                        </div>
                    </article>

                    <details v-if="c.resolvidas.length" class="mt-auto text-xs">
                        <summary class="cursor-pointer font-semibold text-[var(--texto-suave)]">{{ c.resolvidas.length }} {{ c.resolvidas.length === 1 ? 'resolvida' : 'resolvidas' }}</summary>
                        <ul class="mt-2 space-y-2">
                            <li v-for="s in c.resolvidas" :key="s.id" class="rounded border border-[var(--surface-border)] bg-[var(--surface)] p-2">
                                <p class="line-through opacity-70">{{ s.acao }}</p>
                                <p class="mt-1 flex items-center justify-between gap-2 text-[var(--texto-suave)]">
                                    <span>{{ s.status === 'feita' ? 'Feita' : 'Ignorada' }}<template v-if="s.resolvida_por"> por {{ s.resolvida_por }}</template> {{ horaBr(s.resolvida_em) }}</span>
                                    <button type="button" class="font-semibold text-primary hover:underline" :disabled="enviando === s.id" @click="marcar(s, 'aberta')">Desfazer</button>
                                </p>
                            </li>
                        </ul>
                    </details>
                </div>
            </section>
        </div>
    </div>
</template>
