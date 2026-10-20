<script setup>
/**
 * Aba "Saldos nos canais" (pedido do Lira em 2026-10-19): saldo disponível e
 * repasses a receber de Shopee, Mercado Livre, TikTok e Amazon, por empresa.
 * Os números vêm das fotos de hora em hora (financeiro:coletar-saldos,
 * SaldosCanais::painel); a tela não chama API de marketplace. Fonte com
 * erro ou foto com mais de 2 h aparece marcada. Nada é inventado: canal sem
 * dado mostra "sem dado" e o motivo.
 */
import { formatPrice, useTemaEscuro } from '@/Shared/graficos';
import { computed } from 'vue';
import KpiResultado from './KpiResultado.vue';
import SparkSaldo from './SparkSaldo.vue';

const props = defineProps({
    saldos: { type: Object, required: true },
});

const { channelColor } = useTemaEscuro();

const fmtHora = new Intl.DateTimeFormat('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
const quando = (iso) => (iso ? fmtHora.format(new Date(iso)).replace(',', ' às') : 'nunca');
const diaMes = (iso) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}`;
const hoje = new Intl.DateTimeFormat('sv-SE', { timeZone: 'America/Sao_Paulo' }).format(new Date());

const geral = computed(() => props.saldos.geral);
const integradas = computed(() => props.saldos.empresas.filter((e) => e.tem_integracao));

// Fontes (um por card integrado) para a faixa de "última coleta".
const fontes = computed(() => props.saldos.empresas.flatMap((e) => e.cards.filter((c) => c.integrado).map((c) => ({ ...c, empresa: e.nome }))));
const comProblema = computed(() => fontes.value.filter((f) => f.erro || f.desatualizado).length);

const situacao = (c) => {
    if (c.erro) return { texto: 'erro na coleta', classe: 'selo-erro', icone: 'fa-circle-exclamation' };
    if (c.desatualizado) return { texto: `desatualizado (mais de ${props.saldos.horasDesatualizado} h)`, classe: 'selo-alerta', icone: 'fa-clock' };
    if (c.detalhe?.estimado) return { texto: 'estimado', classe: 'selo-neutro', icone: 'fa-wand-magic-sparkles' };
    if (c.status === 'parcial') return { texto: 'parcial', classe: 'selo-neutro', icone: 'fa-circle-half-stroke' };
    return { texto: 'atualizado', classe: 'selo-ok', icone: 'fa-circle-check' };
};

const repassesVisiveis = (c) => (c.repasses ?? []).slice(0, 4);
const repassesExtras = (c) => (c.repasses ?? []).slice(4);

// Linhas de apoio de cada canal, só com o que a coleta trouxe.
const apoio = (c) => {
    const d = c.detalhe ?? {};
    const linhas = [];
    if (c.canal === 'mercado_livre') {
        linhas.push(`A liberar: ML ${formatPrice(d.a_receber_ml)} · site ${formatPrice(d.a_receber_site)}${d.a_receber_outros ? ` · outros ${formatPrice(d.a_receber_outros)}` : ''}`);
        linhas.push('Saldo único da conta Mercado Pago (ML e site juntos).');
    }
    if (c.canal === 'shopee' && d.pedidos_pendentes !== undefined) {
        linhas.push(`${d.pedidos_pendentes} pedidos com repasse pendente${d.em_devolucao ? ` · ${formatPrice(d.em_devolucao)} em devolução` : ''}`);
    }
    if (c.canal === 'amazon' && d.em_transferencia !== undefined) {
        linhas.push(`Na conta: ${formatPrice(d.acumulando)} acumulando · ${formatPrice(d.em_transferencia)} em transferência`);
        linhas.push(`${d.pedidos_retidos} ${d.pedidos_retidos === 1 ? 'venda retida' : 'vendas retidas'} até a entrega`);
    }
    if (c.canal === 'tiktok_shop' && d.pedidos_sem_repasse !== undefined) {
        linhas.push(`${d.pedidos_sem_repasse} pedidos fora do demonstrativo: receita ${formatPrice(d.receita)} − taxa estimada ${formatPrice(d.taxa_estimada)}`);
        if (d.ultimo_demonstrativo) linhas.push(`Último demonstrativo recebido: ${quando(d.ultimo_demonstrativo.replace(' ', 'T') + '-03:00')}`);
    }
    return linhas;
};

const semDado = (c, campo) => {
    if (campo === 'disponivel' && c.canal === 'tiktok_shop') return 'sem API';
    return 'sem dado';
};
</script>

<template>
    <div>
        <!-- Total consolidado -->
        <section class="totais mb-4 grid gap-3 sm:gap-4" aria-label="Total nos canais">
            <KpiResultado class="total-hero" hero rotulo="Total nos marketplaces" :valor="formatPrice(geral.disponivel + geral.a_receber)"
                :apoio="[`Disponível ${formatPrice(geral.disponivel)} · a receber ${formatPrice(geral.a_receber)}`]"
                ajuda="Saldo disponível mais o que ainda vai ser repassado, somando todos os canais de todas as empresas. Usa a última coleta boa de cada canal." />
            <KpiResultado rotulo="Disponível agora" :valor="formatPrice(geral.disponivel)"
                :apoio="['Já liberado nas contas dos canais']" />
            <KpiResultado rotulo="A receber" :valor="formatPrice(geral.a_receber)"
                :apoio="['Vendas pagas que o canal ainda não liberou']" />
        </section>

        <!-- Por empresa (resumo) e última coleta -->
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="saldos-empresas">
                <h2 id="saldos-empresas" class="font-semibold">Por empresa</h2>
                <table class="mt-2 w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-[var(--texto-suave)]">
                            <th scope="col" class="py-1.5 font-semibold">Empresa</th>
                            <th scope="col" class="py-1.5 text-right font-semibold">Disponível</th>
                            <th scope="col" class="py-1.5 text-right font-semibold">A receber</th>
                            <th scope="col" class="py-1.5 text-right font-semibold">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in saldos.empresas" :key="e.empresa" class="border-t border-[var(--surface-muted)]">
                            <th scope="row" class="py-2 text-left font-medium">{{ e.nome }}</th>
                            <template v-if="e.tem_integracao">
                                <td class="py-2 text-right tabular-nums">{{ formatPrice(e.disponivel) }}</td>
                                <td class="py-2 text-right tabular-nums">{{ formatPrice(e.a_receber) }}</td>
                                <td class="py-2 text-right font-semibold tabular-nums">{{ formatPrice(e.disponivel + e.a_receber) }}</td>
                            </template>
                            <td v-else colspan="3" class="py-2 text-right text-xs text-[var(--texto-suave)]">sem conta própria em marketplace</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="saldos-coleta">
                <h2 id="saldos-coleta" class="font-semibold">Última coleta</h2>
                <p class="text-xs text-pretty text-[var(--texto-suave)]">
                    De hora em hora, aos 10 minutos.
                    <template v-if="comProblema">{{ comProblema }} {{ comProblema === 1 ? 'fonte precisa' : 'fontes precisam' }} de atenção.</template>
                    <template v-else>Todas as fontes em dia.</template>
                </p>
                <ul class="mt-2">
                    <li v-for="f in fontes" :key="f.empresa + f.canal" class="flex items-center justify-between gap-3 border-t border-[var(--surface-muted)] py-2 text-sm first:border-0">
                        <span class="flex min-w-0 items-center gap-2">
                            <span class="size-2.5 shrink-0 rounded-full" :style="{ background: channelColor(f.canal) }" aria-hidden="true"></span>
                            <span class="truncate">{{ f.nome }}</span>
                        </span>
                        <span class="flex shrink-0 items-center gap-2 tabular-nums">
                            <span class="text-xs text-[var(--texto-suave)]">{{ quando(f.coletado_em) }}</span>
                            <span class="selo" :class="situacao(f).classe"><i class="fas" :class="situacao(f).icone" aria-hidden="true"></i> {{ situacao(f).texto }}</span>
                        </span>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Uma seção por empresa -->
        <section v-for="e in saldos.empresas" :key="e.empresa" class="mb-6" :aria-labelledby="`saldos-${e.empresa}`">
            <header class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 :id="`saldos-${e.empresa}`" class="text-lg font-bold">{{ e.nome }}</h2>
                <p v-if="e.tem_integracao" class="text-sm text-[var(--texto-suave)] tabular-nums">
                    Disponível <b class="text-[var(--surface-foreground)]">{{ formatPrice(e.disponivel) }}</b>
                    · a receber <b class="text-[var(--surface-foreground)]">{{ formatPrice(e.a_receber) }}</b>
                </p>
            </header>

            <p v-if="!e.tem_integracao" class="rounded-xl border border-dashed border-[var(--surface-border)] px-4 py-3 text-sm text-pretty text-[var(--texto-suave)]">
                {{ e.nome }} não tem conta própria na Shopee, no Mercado Livre, no TikTok Shop nem na Amazon. Hoje as contas desses canais são todas da KazaKora; quando houver conta própria, os cards aparecem aqui.
            </p>

            <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <template v-for="c in e.cards" :key="c.canal">
                    <article v-if="!c.integrado" class="flex min-h-24 items-center gap-2 rounded-xl border border-dashed border-[var(--surface-border)] px-4 py-3 text-sm text-[var(--texto-suave)]">
                        <span class="size-2.5 shrink-0 rounded-full" :style="{ background: channelColor(c.canal) }" aria-hidden="true"></span>
                        {{ c.nome }}: sem integração
                    </article>

                    <article v-else class="card-saldo flex min-w-0 flex-col rounded-xl border bg-[var(--surface)] p-4 shadow-sm"
                        :class="c.erro ? 'border-error' : c.desatualizado ? 'border-warning' : 'border-[var(--surface-border)]'"
                        :aria-labelledby="`saldo-${e.empresa}-${c.canal}`">
                        <header class="flex items-start justify-between gap-2">
                            <h3 :id="`saldo-${e.empresa}-${c.canal}`" class="flex items-center gap-2 font-semibold">
                                <span class="size-2.5 rounded-full" :style="{ background: channelColor(c.canal) }" aria-hidden="true"></span>
                                {{ c.nome }}
                            </h3>
                            <span class="selo" :class="situacao(c).classe"><i class="fas" :class="situacao(c).icone" aria-hidden="true"></i> {{ situacao(c).texto }}</span>
                        </header>

                        <dl class="mt-3 grid grid-cols-2 gap-3">
                            <div class="min-w-0">
                                <dt class="text-xs font-semibold text-[var(--texto-suave)]">Disponível</dt>
                                <dd class="text-xl font-bold tabular-nums whitespace-nowrap">
                                    <template v-if="c.disponivel !== null">{{ formatPrice(c.disponivel) }}</template>
                                    <span v-else class="text-sm font-semibold text-[var(--texto-suave)]">{{ semDado(c, 'disponivel') }}</span>
                                </dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="text-xs font-semibold text-[var(--texto-suave)]">A receber<span v-if="c.detalhe?.estimado" class="ml-1 font-normal">(estimado)</span></dt>
                                <dd class="text-xl font-bold tabular-nums whitespace-nowrap">
                                    <template v-if="c.a_receber !== null">{{ formatPrice(c.a_receber) }}</template>
                                    <span v-else class="text-sm font-semibold text-[var(--texto-suave)]">{{ semDado(c, 'a_receber') }}</span>
                                </dd>
                            </div>
                        </dl>

                        <SparkSaldo class="mt-3" :serie="c.serie ?? []" :cor="channelColor(c.canal)" :dias="saldos.diasGrafico" :agora="saldos.agora" :nome="c.nome" />

                        <div class="mt-3">
                            <h4 class="text-xs font-semibold text-[var(--texto-suave)]">Próximos repasses</h4>
                            <ul v-if="c.repasses?.length" class="mt-1">
                                <li v-for="(r, i) in repassesVisiveis(c)" :key="i" class="flex items-baseline justify-between gap-3 border-t border-[var(--surface-muted)] py-1.5 text-sm first:border-0">
                                    <span class="min-w-0">
                                        <b class="tabular-nums">{{ r.data ? (r.data === hoje ? 'hoje' : diaMes(r.data)) : 'sem data' }}</b>
                                        <span class="block truncate text-xs text-[var(--texto-suave)]" :title="r.rotulo">{{ r.rotulo }}</span>
                                    </span>
                                    <span class="shrink-0 font-semibold tabular-nums">{{ formatPrice(r.valor) }}</span>
                                </li>
                            </ul>
                            <details v-if="repassesExtras(c).length" class="mt-1 text-sm">
                                <summary class="cursor-pointer rounded text-xs font-semibold text-[var(--texto-suave)] hover:text-[var(--surface-foreground)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-primary)]">mais {{ repassesExtras(c).length }} {{ repassesExtras(c).length === 1 ? 'data' : 'datas' }}</summary>
                                <ul>
                                    <li v-for="(r, i) in repassesExtras(c)" :key="i" class="flex items-baseline justify-between gap-3 border-t border-[var(--surface-muted)] py-1.5">
                                        <b class="tabular-nums">{{ r.data ? diaMes(r.data) : 'sem data' }}</b>
                                        <span class="font-semibold tabular-nums">{{ formatPrice(r.valor) }}</span>
                                    </li>
                                </ul>
                            </details>
                            <p v-if="!c.repasses?.length" class="mt-1 text-sm text-[var(--texto-suave)]">Nenhum repasse pendente.</p>
                        </div>

                        <div class="mt-auto pt-3 text-xs text-pretty text-[var(--texto-suave)]">
                            <p v-for="(l, i) in apoio(c)" :key="i" class="tabular-nums">{{ l }}</p>
                            <p v-if="c.detalhe?.aviso" class="mt-1">{{ c.detalhe.aviso }}</p>
                            <p v-if="c.erro" class="mt-1 font-semibold text-error" role="status">Última tentativa ({{ quando(c.ultima_tentativa) }}) falhou: {{ c.erro }}</p>
                            <p class="mt-1">Coletado {{ quando(c.coletado_em) }}</p>
                        </div>
                    </article>
                </template>
            </div>
        </section>

        <p v-if="!integradas.length" class="text-sm text-[var(--texto-suave)]">Nenhum canal integrado.</p>
    </div>
</template>

<style scoped>
.totais { grid-template-columns: 1.4fr repeat(2, minmax(0, 1fr)); }
@media (max-width: 1023px) {
    .totais { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .totais :deep(.total-hero) { grid-column: 1 / -1; }
}
.selo { display: inline-flex; align-items: center; gap: 0.3rem; border-radius: 9999px; padding: 0.1rem 0.5rem; font-size: 11px; font-weight: 600; white-space: nowrap; border: 1px solid transparent; }
.selo-ok { color: #047857; background: color-mix(in oklab, #047857 10%, transparent); }
.selo-alerta { color: #92400e; background: color-mix(in oklab, #d97706 14%, transparent); }
.selo-erro { color: #b91c1c; background: color-mix(in oklab, #b91c1c 10%, transparent); }
.selo-neutro { color: var(--texto-suave); border-color: var(--surface-border); }
:global(.dark) .selo-ok { color: #34d399; }
:global(.dark) .selo-alerta { color: #fbbf24; }
:global(.dark) .selo-erro { color: #f87171; }
</style>
