<script setup>
/**
 * Seções Meta ADS e Google ADS do menu Publicidade (2026-10-12). A mesma
 * tela serve as duas plataformas (ver PublicidadePlataformaController).
 * Sem integração conectada, mostra o estado vazio com o que falta para
 * ligar; com gasto sincronizado em channel_ad_spends, mostra os números.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import DiagnosticoMeta from '@/Modules/Admin/MetaAds/DiagnosticoMeta.vue';
import { Deferred, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    plataforma: { type: Object, required: true },
    conectado: { type: Boolean, default: false },
    ultimaSincronizacao: { type: [String, null], default: null },
    periodos: { type: Array, default: () => [] },
    // Meta (2026-10-21): "O que fazer agora" curto, últimos 7 dias (prop adiada).
    diagnostico: { type: Object, default: null },
    temDiagnostico: { type: Boolean, default: false },
    // Lira 2026-10-27: sem gasto gravado no painel, o resumo vem da Meta (prop adiada).
    daMeta: { type: Boolean, default: false },
    resumoMeta: { type: Object, default: null },
    // Lira 2026-10-27: campanhas da Meta já excluídas, com os números de toda a vida delas.
    historico: { type: Array, default: () => [] },
});

const dinheiro = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);
const numero = (v) => new Intl.NumberFormat('pt-BR').format(v ?? 0);
const data = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('pt-BR');
const roas = (v) => (v === null || v === undefined ? '—' : `${v.toFixed(2).replace('.', ',')}x`);

const linhas = computed(() => (props.daMeta ? props.resumoMeta?.periodos ?? [] : props.periodos));
const STATUS_FINAL = { PAUSED: 'Pausada', ARCHIVED: 'Arquivada', ACTIVE: 'Ativa' };
const porcento = (v) => `${(v ?? 0).toFixed(2).replace('.', ',')}%`;
const somaHistorico = (campo) => props.historico.reduce((t, c) => t + (c[campo] ?? 0), 0);
const totalHistorico = computed(() => {
    const gasto = somaHistorico('gasto');
    const vendas = somaHistorico('vendas');
    return {
        gasto,
        impressoes: somaHistorico('impressoes'),
        cliques_link: somaHistorico('cliques_link'),
        carrinho: somaHistorico('carrinho'),
        checkout: somaHistorico('checkout'),
        compras: somaHistorico('compras'),
        vendas,
        roas: gasto > 0 ? vendas / gasto : null,
    };
});

const atual = computed(() => linhas.value[0]?.totais ?? { gasto: 0, vendas: 0, roas: null });
</script>

<template>
    <Head :title="plataforma.nome" />

    <AdminLayout>
        <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-3 text-2xl font-bold">
                <i :class="plataforma.icone" :style="{ color: plataforma.cor }" aria-hidden="true"></i>
                {{ plataforma.nome }}
            </h1>
            <span
                class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-bold"
                :class="conectado
                    ? 'bg-lightsuccess text-success'
                    : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'"
            >
                <span class="h-2 w-2 rounded-full" :class="conectado ? 'bg-success' : 'bg-slate-400'" aria-hidden="true"></span>
                {{ conectado ? 'Conectada' : 'Não conectada' }}
            </span>
            <!-- Campanhas de vídeo (2026-10-13): sobe vídeos e divide o orçamento. -->
            <Link
                v-if="plataforma.canal === 'meta_ads'"
                href="/admin/publicidade/meta-ads/campanhas"
                class="ml-auto inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis"
            >
                <i class="fas fa-film text-xs" aria-hidden="true"></i> Campanhas com vídeo
            </Link>
        </div>
        <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
            {{ plataforma.subtitulo }}. Gasto, cliques e venda que a própria plataforma atribui ao anúncio.
            ROAS é a venda atribuída dividida pelo gasto. O lucro de verdade fica em
            <Link href="/admin/dashboard-financeiro" class="font-medium text-primary hover:underline">Resultado e margem</Link>.
        </p>

        <Deferred v-if="temDiagnostico" data="diagnostico">
            <template #fallback>
                <div class="mb-5 flex items-center gap-2 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 text-sm text-slate-500" role="status" aria-live="polite">
                    <i class="fas fa-circle-notch animate-spin text-primary" aria-hidden="true"></i> Consultando a Meta…
                </div>
            </template>
            <DiagnosticoMeta :diagnostico="diagnostico" :periodos="{ last_7d: 'Últimos 7 dias' }" :limite="3" compacto />
        </Deferred>

        <section
            v-if="!conectado"
            class="mb-6 rounded-xl border border-dashed border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-sm"
            aria-labelledby="estado-vazio-titulo"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                <div
                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xl text-slate-500 dark:bg-slate-800 dark:text-slate-300"
                    aria-hidden="true"
                >
                    <i class="fas fa-plug-circle-xmark"></i>
                </div>
                <div class="min-w-0">
                    <h2 id="estado-vazio-titulo" class="text-base font-semibold">Integração ainda não conectada</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        O painel ainda não recebe dados do {{ plataforma.nome }}. Nenhum número desta tela é estimado:
                        assim que a integração for ligada, o gasto diário aparece aqui.
                    </p>

                    <h3 class="mt-4 text-sm font-semibold">O que falta para conectar</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
                        <li v-for="item in plataforma.precisa" :key="item">{{ item }}</li>
                    </ul>

                    <a
                        :href="plataforma.painel"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-4 inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                    >
                        Abrir o {{ plataforma.painel_nome }}
                        <i class="fas fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                        <span class="sr-only">(abre em outra aba)</span>
                    </a>
                </div>
            </div>
        </section>

        <!-- Lira 2026-10-27: conectada pela API, sem gasto diário gravado no
             painel: o gasto e os resultados vêm direto da Meta. -->
        <Deferred v-else-if="daMeta" data="resumoMeta">
            <template #fallback>
                <div class="mb-6 flex items-center gap-2 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 text-sm text-slate-500" role="status" aria-live="polite">
                    <i class="fas fa-circle-notch animate-spin text-primary" aria-hidden="true"></i> Lendo gasto e resultados na Meta…
                </div>
            </template>
            <p
                v-if="resumoMeta?.erro"
                class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 text-sm text-slate-600 shadow-sm dark:text-slate-300"
                role="alert"
            >
                Não deu para ler o gasto na Meta agora: {{ resumoMeta.erro }}
            </p>
        </Deferred>

        <template v-if="conectado && linhas.length">
            <p class="mb-4 text-xs text-slate-500 dark:text-slate-400">
                <template v-if="daMeta">Números lidos agora na conta da Meta, incluindo campanhas pausadas e excluídas.</template>
                <template v-else>Último dia sincronizado: {{ data(ultimaSincronizacao) }}.</template>
            </p>

            <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <CardStats stat-subtitle="GASTO NO MÊS" :stat-title="dinheiro(atual.gasto)" stat-icon-name="fas fa-bullhorn" variant="warning" />
                <CardStats stat-subtitle="VENDA ATRIBUÍDA AO ADS" :stat-title="dinheiro(atual.vendas)" stat-icon-name="fas fa-cart-shopping" variant="success" />
                <CardStats stat-subtitle="ROAS DO MÊS" :stat-title="roas(atual.roas)" stat-icon-name="fas fa-arrow-trend-up" variant="primary" />
            </div>

            <section class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                <th scope="col" class="px-4 py-2 font-semibold">Período</th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold">Gasto</th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold">Impressões</th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold">Cliques</th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold">Pedidos</th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold">Venda atribuída</th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold">ROAS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in linhas" :key="p.titulo" class="border-t border-[var(--surface-border)]">
                                <td class="px-4 py-2">
                                    <span class="font-semibold">{{ p.titulo }}</span>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ p.desde ? `${data(p.desde)} a ${data(p.ate)}` : `até ${data(p.ate)}` }}</span>
                                </td>
                                <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ dinheiro(p.totais.gasto) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ numero(p.totais.impressoes) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ numero(p.totais.cliques) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ numero(p.totais.pedidos) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ dinheiro(p.totais.vendas) }}</td>
                                <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ roas(p.totais.roas) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>

        <!-- Lira 2026-10-27: histórico das campanhas excluídas, da nossa base. -->
        <section
            v-if="plataforma.canal === 'meta_ads' && historico.length"
            class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm"
            aria-labelledby="historico-titulo"
        >
            <div class="border-b border-[var(--surface-border)] px-4 py-3">
                <h2 id="historico-titulo" class="text-base font-semibold">Histórico de campanhas excluídas</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    {{ historico.length }} campanhas. Números de toda a vida de cada uma, guardados antes da exclusão.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <th scope="col" class="whitespace-nowrap px-3 py-2 font-semibold">Início</th>
                            <th scope="col" class="px-3 py-2 font-semibold">Campanha</th>
                            <th scope="col" class="whitespace-nowrap px-3 py-2 text-right font-semibold">Orçamento/dia</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">Gasto</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">Impressões</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">Alcance</th>
                            <th scope="col" class="whitespace-nowrap px-3 py-2 text-right font-semibold">Cliques no link</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">CTR</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">Carrinho</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">Checkout</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">Compras</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">Vendas</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">ROAS</th>
                            <th scope="col" class="whitespace-nowrap px-3 py-2 text-right font-semibold">Conjuntos / anúncios</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in historico" :key="c.id" class="border-t border-[var(--surface-border)] align-top">
                            <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ c.inicio ? data(c.inicio) : '—' }}</td>
                            <td class="min-w-[16rem] px-3 py-2">
                                <span class="font-semibold">{{ c.nome }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ STATUS_FINAL[c.status_final] ?? c.status_final }} antes de excluir<template v-if="c.fim"> · terminou em {{ data(c.fim) }}</template>
                                    · excluída em {{ data(c.excluida_em) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                                <template v-if="c.orcamento_nivel === 'conjunto'">nos conjuntos</template>
                                <template v-else>{{ dinheiro(c.orcamento_diario) }}</template>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums">{{ dinheiro(c.gasto) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(c.impressoes) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(c.alcance) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(c.cliques_link) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ porcento(c.ctr_link) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(c.carrinho) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(c.checkout) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(c.compras) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ dinheiro(c.vendas) }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ c.gasto > 0 ? roas(c.roas) : '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ c.conjuntos }} / {{ c.anuncios }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-[var(--surface-border)] font-semibold">
                            <td class="px-3 py-2" colspan="3">Total</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ dinheiro(totalHistorico.gasto) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(totalHistorico.impressoes) }}</td>
                            <td class="px-3 py-2"></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(totalHistorico.cliques_link) }}</td>
                            <td class="px-3 py-2"></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(totalHistorico.carrinho) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(totalHistorico.checkout) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(totalHistorico.compras) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ dinheiro(totalHistorico.vendas) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ roas(totalHistorico.roas) }}</td>
                            <td class="px-3 py-2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
