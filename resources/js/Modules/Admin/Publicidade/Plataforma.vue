<script setup>
/**
 * Seções Meta ADS e Google ADS do menu Publicidade (2026-10-12). A mesma
 * tela serve as duas plataformas (ver PublicidadePlataformaController).
 * Sem integração conectada, mostra o estado vazio com o que falta para
 * ligar; com gasto sincronizado em channel_ad_spends, mostra os números.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    plataforma: { type: Object, required: true },
    conectado: { type: Boolean, default: false },
    ultimaSincronizacao: { type: [String, null], default: null },
    periodos: { type: Array, default: () => [] },
});

const dinheiro = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);
const numero = (v) => new Intl.NumberFormat('pt-BR').format(v ?? 0);
const data = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('pt-BR');
const roas = (v) => (v === null || v === undefined ? '—' : `${v.toFixed(2).replace('.', ',')}x`);

const atual = computed(() => props.periodos[0]?.totais ?? { gasto: 0, vendas: 0, roas: null });
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

        <template v-else>
            <p class="mb-4 text-xs text-slate-500 dark:text-slate-400">
                Último dia sincronizado: {{ data(ultimaSincronizacao) }}.
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
                            <tr v-for="p in periodos" :key="p.titulo" class="border-t border-[var(--surface-border)]">
                                <td class="px-4 py-2">
                                    <span class="font-semibold">{{ p.titulo }}</span>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ data(p.desde) }} a {{ data(p.ate) }}</span>
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
    </AdminLayout>
</template>
