<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Oportunidades (correção do Lira 2026-10-12): produtos de TERCEIROS que
// estão vendendo muito ou em alta no marketplace, para achar produto novo.
// Não são as vendas da KazaKora. Ver OportunidadesController.
const props = defineProps({
    canal: { type: Object, required: true },
    filtros: { type: Object, required: true },
    categorias: { type: Array, default: () => [] },
    mercado: { type: Object, required: true },
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
const formatNumber = (value) => (value ?? 0).toLocaleString('pt-BR');
const formatDateTime = (iso) => new Intl.DateTimeFormat('pt-BR', { timeZone: 'America/Sao_Paulo', dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso));

const ehMl = computed(() => props.canal.slug === 'mercado-livre');
const categoria = ref(props.filtros.categoria ?? '');
const termo = ref(props.filtros.termo ?? '');
const carregando = ref(false);

const buscar = (extra = {}) => {
    const filtros = ehMl.value ? { categoria: categoria.value } : { termo: termo.value };
    router.get(`/admin/oportunidades/${props.canal.slug}`, { ...filtros, ...extra }, {
        preserveScroll: true,
        onStart: () => { carregando.value = true; },
        onFinish: () => { carregando.value = false; },
    });
};

const nomeCategoria = computed(() => props.categorias.find((c) => c.id === props.filtros.categoria)?.nome ?? props.filtros.categoria);
const faixaPreco = (p) => {
    if (p.preco_min === null) return '—';
    if (p.preco_max !== null && p.preco_max > p.preco_min) return `${formatPrice(p.preco_min)} a ${formatPrice(p.preco_max)}`;
    return formatPrice(p.preco_min);
};
</script>

<template>
    <Head :title="`Oportunidades · ${canal.nome}`" />

    <AdminLayout>
        <div class="mb-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-[var(--texto-suave)]">Oportunidades</p>
            <h1 class="text-2xl font-bold">Mais vendidos e em alta · {{ canal.nome }}</h1>
            <p class="mt-1 text-sm text-[var(--texto-suave)]">
                Produtos de outros vendedores com muita venda no {{ canal.nome }}. Servem para achar produto novo: não são as vendas da KazaKora.
            </p>
        </div>

        <!-- Filtro -->
        <form class="mb-6 flex flex-wrap items-end gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" @submit.prevent="buscar()">
            <label v-if="ehMl" class="text-xs text-[var(--texto-suave)]">Categoria
                <select v-model="categoria" name="categoria" class="mt-1 block min-w-64 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm" @change="buscar()">
                    <option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nome }}</option>
                    <option v-if="!categorias.some((c) => c.id === categoria)" :value="categoria">{{ categoria }}</option>
                </select>
            </label>
            <label v-else class="text-xs text-[var(--texto-suave)]">Palavra-chave (vazio = mais vendidos em geral)
                <input v-model="termo" type="search" name="termo" maxlength="80" autocomplete="off" placeholder="ex.: organizador, luminária…"
                    class="mt-1 block w-72 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-1.5 text-sm">
            </label>
            <button v-if="!ehMl" type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50" :disabled="carregando || !mercado.configurado">
                {{ carregando ? 'Buscando…' : 'Buscar' }}
            </button>
            <div class="ml-auto flex items-center gap-3 text-xs text-[var(--texto-suave)]">
                <span v-if="mercado.atualizado_em">Consultado em {{ formatDateTime(mercado.atualizado_em) }}</span>
                <button v-if="mercado.configurado" type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm hover:bg-[var(--surface-muted)] disabled:opacity-50" :disabled="carregando" @click="buscar({ atualizar: 1 })">
                    {{ carregando ? 'Consultando…' : 'Consultar de novo' }}
                </button>
            </div>
        </form>

        <!-- Shopee sem a API de Afiliados -->
        <section v-if="!mercado.configurado" class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-[var(--surface-border)] bg-[var(--surface)] px-6 py-12 text-center">
            <i class="fas fa-plug text-3xl text-[var(--texto-suave)]" aria-hidden="true"></i>
            <p class="text-lg font-bold">Falta conectar a API de Afiliados da Shopee</p>
            <p class="max-w-xl text-sm text-[var(--texto-suave)]">
                A integração de vendedor da Shopee só enxerga a própria loja. As vendas de outras lojas vêm da API de Afiliados
                (affiliate.shopee.com.br › Open API). Com o AppID e a senha de lá no servidor
                (SHOPEE_AFILIADOS_APP_ID e SHOPEE_AFILIADOS_SECRET), esta tela passa a listar os mais vendidos.
            </p>
        </section>

        <template v-else>
            <p v-if="mercado.erro" class="mb-6 rounded-xl border border-[var(--color-error)] bg-[var(--surface)] px-4 py-3 text-sm text-error" role="alert">{{ mercado.erro }}</p>

            <div class="grid grid-cols-1 gap-6" :class="ehMl ? 'xl:grid-cols-[1fr_300px]' : ''">
                <!-- Mais vendidos -->
                <section class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" style="border-top: 3px solid var(--faixa-card)">
                    <div class="border-b border-[var(--surface-border)] px-4 py-3">
                        <h2 class="text-base font-semibold">
                            Mais vendidos{{ ehMl ? ` · ${nomeCategoria}` : (filtros.termo ? ` · “${filtros.termo}”` : '') }} ({{ mercado.produtos.length }})
                        </h2>
                    </div>

                    <p v-if="mercado.produtos.length === 0 && !mercado.erro" class="px-4 py-8 text-center text-sm text-[var(--texto-suave)]">
                        Nenhum produto encontrado.
                    </p>

                    <div v-else-if="mercado.produtos.length" class="overflow-x-auto">
                        <table class="w-full min-w-[720px] text-left text-sm">
                            <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-[var(--texto-suave)]">
                                <tr>
                                    <th scope="col" class="px-4 py-2">#</th>
                                    <th scope="col" class="py-2 pr-3">Produto</th>
                                    <th scope="col" class="py-2 pr-3 text-right">Preço</th>
                                    <th v-if="ehMl" scope="col" class="py-2 pr-4 text-right" title="Quantos vendedores anunciam este produto no catálogo">Vendedores</th>
                                    <template v-else>
                                        <th scope="col" class="py-2 pr-3 text-right" title="Vendas informadas pela Shopee">Vendas</th>
                                        <th scope="col" class="py-2 pr-4 text-right">Nota</th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in mercado.produtos" :key="p.id" class="border-b border-[var(--surface-border)] last:border-0 hover:bg-[var(--surface-muted)]">
                                    <td class="px-4 py-2 tabular-nums text-[var(--texto-suave)]">{{ p.posicao }}º</td>
                                    <td class="py-2 pr-3">
                                        <div class="flex items-center gap-3">
                                            <img v-if="p.imagem" :src="p.imagem" alt="" width="48" height="48" loading="lazy" class="h-12 w-12 shrink-0 rounded-lg border border-[var(--surface-border)] bg-white object-contain">
                                            <div class="min-w-0">
                                                <a :href="p.link" target="_blank" rel="noopener noreferrer" class="line-clamp-2 hover:text-[var(--destaque)] hover:underline">{{ p.nome }}</a>
                                                <span v-if="p.loja" class="block truncate text-xs text-[var(--texto-suave)]">{{ p.loja }}</span>
                                                <span v-if="p.voce_vende" class="mt-0.5 inline-block rounded-full bg-[var(--surface-muted)] px-2 py-0.5 text-xs">A KazaKora já vende</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular-nums">{{ faixaPreco(p) }}</td>
                                    <td v-if="ehMl" class="py-2 pr-4 text-right tabular-nums">{{ p.vendedores === null ? '—' : formatNumber(p.vendedores) }}</td>
                                    <template v-else>
                                        <td class="py-2 pr-3 text-right font-semibold tabular-nums">{{ p.vendas === null ? '—' : formatNumber(p.vendas) }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ p.nota ? p.nota.toLocaleString('pt-BR', { maximumFractionDigits: 1 }) : '—' }}</td>
                                    </template>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="border-t border-[var(--surface-border)] px-4 py-3 text-xs text-[var(--texto-suave)]">
                        <template v-if="ehMl">
                            Ranking "Mais vendidos" do próprio Mercado Livre na categoria. Preço = menor e maior entre os vendedores do catálogo.
                            <template v-if="mercado.ocultos"> {{ mercado.ocultos }} anúncio(s) do ranking não são de catálogo e o ML não libera os dados deles.</template>
                        </template>
                        <template v-else>Ordenado pelas vendas informadas pela API de Afiliados da Shopee.</template>
                    </p>
                </section>

                <!-- Buscas em alta (só ML) -->
                <section v-if="ehMl" class="h-fit rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-label="Buscas em alta">
                    <h2 class="text-base font-semibold">Buscas em alta</h2>
                    <p class="mb-3 text-xs text-[var(--texto-suave)]">O que mais cresce nas buscas desta categoria no Mercado Livre.</p>
                    <p v-if="!mercado.tendencias.length" class="text-sm text-[var(--texto-suave)]">Sem tendências para esta categoria.</p>
                    <ol v-else class="space-y-1 text-sm">
                        <li v-for="(t, i) in mercado.tendencias" :key="t.termo" class="flex gap-2">
                            <span class="w-5 shrink-0 text-right tabular-nums text-[var(--texto-suave)]">{{ i + 1 }}.</span>
                            <a :href="t.link" target="_blank" rel="noopener noreferrer" class="hover:text-[var(--destaque)] hover:underline">{{ t.termo }}</a>
                        </li>
                    </ol>
                </section>
            </div>
        </template>
    </AdminLayout>
</template>
