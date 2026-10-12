<script setup>
import MarcaLogo from './MarcaLogo.vue';
import BuscaTabela from './BuscaTabela.vue';
import Paginacao from './Paginacao.vue';
import { useBuscaPaginada } from './useBuscaPaginada';
import { Deferred } from '@inertiajs/vue3';
import { computed } from 'vue';

// Bloco do Mercado Livre do Radar (buscas em alta do site e mais vendidos de
// Casa). Aparece no Radar geral e embaixo dos mais vendidos em
// Oportunidades › Mercado Livre e Mercado Livre › Radar (pedido do Lira
// 2026-10-12). `mercadoLivre` é prop adiada da página com esse mesmo nome.
const props = defineProps({
    mercadoLivre: { type: Object, default: null },
    // No Radar geral aponta para as outras categorias; na tela do ML elas já estão em cima.
    linkOutrasCategorias: { type: Boolean, default: true },
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
const formatK = (value) => {
    const n = value ?? 0;
    const curto = (v, sufixo) => `${v.toLocaleString('pt-BR', { maximumFractionDigits: v < 10 ? 1 : 0 })}${sufixo}`;
    if (Math.abs(n) >= 1_000_000) return curto(n / 1_000_000, 'M');
    if (Math.abs(n) >= 1_000) return curto(n / 1_000, 'k');
    return n.toLocaleString('pt-BR');
};
const faixaPreco = (p) => {
    if (p.preco_min === null || p.preco_min === undefined) return '—';
    if (p.preco_max !== null && p.preco_max > p.preco_min) return `${formatPrice(p.preco_min)} a ${formatPrice(p.preco_max)}`;
    return formatPrice(p.preco_min);
};

const ml = useBuscaPaginada(computed(() => props.mercadoLivre?.produtos ?? []), (p) => p.nome, 20);
</script>

<template>
    <section class="card-fonte fonte-ml">
        <div class="card-cabeca">
            <MarcaLogo marca="ml" />
            <div class="min-w-0">
                <h2 class="text-base font-bold">Mercado Livre · buscas em alta e mais vendidos de Casa</h2>
                <p class="text-xs text-[var(--texto-suave)]">
                    Fonte: API do Mercado Livre.
                    <template v-if="linkOutrasCategorias">Outras categorias em <a href="/admin/oportunidades/mercado-livre" class="underline">Oportunidades › Mercado Livre</a>.</template>
                </p>
            </div>
        </div>
        <Deferred data="mercadoLivre">
            <template #fallback>
                <p class="px-4 py-6 text-sm text-[var(--texto-suave)]" role="status"><i class="fas fa-circle-notch mr-1 animate-spin" aria-hidden="true"></i> Consultando o Mercado Livre (a primeira consulta do dia leva alguns segundos)…</p>
            </template>
            <p v-if="mercadoLivre.erro" class="px-4 pt-4 text-sm text-error" role="alert">{{ mercadoLivre.erro }}</p>
            <div class="px-4 py-3">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-[var(--texto-suave)]">Buscas que mais crescem no site</h3>
                <p v-if="mercadoLivre.buscas.length === 0" class="mt-1 text-sm text-[var(--texto-suave)]">Sem dados agora.</p>
                <div v-else class="mt-2 flex flex-wrap gap-1.5">
                    <a v-for="t in mercadoLivre.buscas" :key="t.termo" :href="t.link || null" target="_blank" rel="noopener"
                        class="etiqueta etiqueta-busca">{{ t.termo }}</a>
                </div>
            </div>
            <div v-if="mercadoLivre.produtos.length" class="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--surface-border)] px-4 py-3">
                <BuscaTabela v-model="ml.busca.value" rotulo="Buscar produto entre os mais vendidos do Mercado Livre" placeholder="Buscar produto (ex.: organizador)" />
                <span class="text-xs text-[var(--texto-suave)]" role="status" aria-live="polite">{{ ml.faixa.value }} produtos</span>
            </div>
            <p v-if="mercadoLivre.produtos.length && ml.filtrados.value.length === 0" class="border-t border-[var(--surface-border)] px-4 py-6 text-sm text-[var(--texto-suave)]">Nenhum produto com “{{ ml.busca.value }}” entre os mais vendidos.</p>
            <table v-else-if="mercadoLivre.produtos.length" class="tabela border-t border-[var(--surface-border)]">
                <thead>
                    <tr>
                        <th scope="col" class="pl-4">#</th>
                        <th scope="col">Produto</th>
                        <th scope="col" class="text-right">Vendedores</th>
                        <th scope="col" class="pr-4 text-right">Preço</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in ml.daPagina.value" :key="p.id">
                        <td class="pl-4"><span class="posicao" :class="{ 'posicao-top': p.posicao <= 3 }">{{ p.posicao }}</span></td>
                        <td class="max-w-0 w-full">
                            <div class="flex items-center gap-2">
                                <a :href="p.link" target="_blank" rel="noopener" class="min-w-0 truncate font-medium hover:underline" :title="p.nome">{{ p.nome }}</a>
                                <span v-if="p.voce_vende" class="etiqueta shrink-0 bg-lightsuccess text-success">você vende</span>
                            </div>
                        </td>
                        <td class="whitespace-nowrap text-right">{{ p.vendedores === null ? '' : formatK(p.vendedores) }}</td>
                        <td class="whitespace-nowrap pr-4 text-right font-bold">{{ faixaPreco(p) }}</td>
                    </tr>
                </tbody>
            </table>
            <Paginacao :pagina="ml.pagina.value" :total="ml.totalPaginas.value" rotulo="Páginas dos mais vendidos do Mercado Livre" @ir="ml.irParaPagina" />
        </Deferred>
    </section>
</template>

<style scoped src="./radar.css"></style>
