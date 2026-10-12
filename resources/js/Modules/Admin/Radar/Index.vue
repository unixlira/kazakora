<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { RADAR_NOME } from '@/Shared/radarNome';
import { Deferred, Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Radar de Oportunidades (pedido do Lira 2026-10-12): o que está vendendo e
// em alta fora da loja, numa tela só. Fontes: TikTok Shop (Kalodata),
// Shopee (API de Afiliados), Mercado Livre (mais vendidos de Casa e buscas
// em alta) e Google Trends. Cada fonte é prop adiada num grupo próprio
// (null até chegar). Ver RadarController.
const props = defineProps({
    tiktok: { type: Object, default: null },
    shopee: { type: Object, default: null },
    mercadoLivre: { type: Object, default: null },
    google: { type: Object, default: null },
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
const formatNumber = (value) => (value ?? 0).toLocaleString('pt-BR');
const formatPercent = (value) => `${value > 0 ? '+' : ''}${value.toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`;

const carregando = ref(false);
const consultarDeNovo = () => {
    router.get('/admin/radar', { atualizar: 1 }, {
        preserveScroll: true,
        onStart: () => { carregando.value = true; },
        onFinish: () => { carregando.value = false; },
    });
};

const faixaPreco = (p) => {
    if (p.preco_min === null || p.preco_min === undefined) return '—';
    if (p.preco_max !== null && p.preco_max > p.preco_min) return `${formatPrice(p.preco_min)} a ${formatPrice(p.preco_max)}`;
    return formatPrice(p.preco_min);
};

// Cruzamento: termo em alta (buscas do ML e do Google) que também aparece em
// nome de produto vendendo muito em outra fonte, ou nas duas listas de busca.
// Comparação por palavra, sem acento; palavras curtas e genéricas não contam.
const IGNORAR = new Set(['para', 'com', 'sem', 'kit', 'jogo', 'the', 'dos', 'das', 'por', 'uma', 'novo', 'nova', 'original', 'pro', 'max', 'plus']);
const normalizar = (texto) => (texto ?? '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim();
const palavrasDe = (texto) => normalizar(texto).split(' ').filter((p) => p.length >= 3 && !IGNORAR.has(p));
const contem = (nome, palavras) => {
    const doNome = new Set(palavrasDe(nome));
    return palavras.every((p) => doNome.has(p) || doNome.has(`${p}s`) || doNome.has(p.replace(/s$/, '')));
};

const sinais = computed(() => {
    const termos = [
        ...(props.mercadoLivre?.buscas ?? []).map((t) => ({ termo: t.termo, origem: 'Busca no Mercado Livre' })),
        ...(props.mercadoLivre?.tendencias ?? []).map((t) => ({ termo: t.termo, origem: 'Busca no Mercado Livre' })),
        ...(props.google?.termos ?? []).map((t) => ({ termo: t.termo, origem: 'Busca no Google' })),
    ];
    const listas = [
        ['TikTok Shop', props.tiktok?.produtos ?? []],
        ['Shopee', props.shopee?.produtos ?? []],
        ['Mercado Livre', props.mercadoLivre?.produtos ?? []],
    ];

    const porTermo = new Map();
    for (const { termo, origem } of termos) {
        const palavras = palavrasDe(termo);
        if (palavras.length === 0) continue;
        const chave = palavras.join(' ');
        const sinal = porTermo.get(chave) ?? { termo, palavras, fontes: new Set(), exemplos: [] };
        sinal.fontes.add(origem);
        porTermo.set(chave, sinal);
    }

    const resultado = [];
    for (const sinal of porTermo.values()) {
        for (const [fonte, produtos] of listas) {
            const achado = produtos.find((p) => contem(p.nome, sinal.palavras));
            if (achado) {
                sinal.fontes.add(`Vendendo no ${fonte}`);
                sinal.exemplos.push(`${fonte}: ${achado.nome}`);
            }
        }
        if (sinal.fontes.size >= 2) resultado.push({ ...sinal, fontes: [...sinal.fontes] });
    }

    return resultado.sort((a, b) => b.fontes.length - a.fontes.length).slice(0, 12);
});
const tudoCarregado = computed(() => props.tiktok && props.shopee && props.mercadoLivre && props.google);
</script>

<template>
    <Head :title="RADAR_NOME" />
    <AdminLayout>
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-[var(--texto-suave)]">Oportunidades</p>
                <h1 class="text-2xl font-bold">{{ RADAR_NOME }}</h1>
                <p class="mt-1 max-w-3xl text-sm text-[var(--texto-suave)]">
                    O que está vendendo e em alta fora da loja: TikTok Shop, Shopee, Mercado Livre e Google. Serve para achar produto novo; não são as vendas da KazaKora.
                </p>
            </div>
            <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm hover:bg-[var(--surface-muted)] disabled:opacity-50"
                :disabled="carregando || !tudoCarregado" title="Refaz Shopee, Mercado Livre e Google. O TikTok atualiza uma vez por dia (o Kalodata cobra por consulta)." @click="consultarDeNovo">
                {{ carregando || !tudoCarregado ? 'Consultando…' : 'Consultar de novo' }}
            </button>
        </div>

        <!-- Em alta em mais de um lugar -->
        <section class="mb-6 overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" style="border-top: 3px solid var(--faixa-card)">
            <div class="border-b border-[var(--surface-border)] px-4 py-3">
                <h2 class="text-base font-semibold"><i class="fas fa-bullseye mr-1 text-primary" aria-hidden="true"></i> Em alta em mais de um lugar</h2>
                <p class="text-xs text-[var(--texto-suave)]">Busca em alta que também aparece entre os mais vendidos de outra fonte. É o sinal mais forte da tela.</p>
            </div>
            <p v-if="!tudoCarregado" class="px-4 py-6 text-sm text-[var(--texto-suave)]" role="status">
                <i class="fas fa-circle-notch mr-1 animate-spin" aria-hidden="true"></i> Cruzando as fontes…
            </p>
            <p v-else-if="sinais.length === 0" class="px-4 py-6 text-sm text-[var(--texto-suave)]">
                Nenhuma busca em alta bate com os mais vendidos hoje. Veja as fontes abaixo uma a uma.
            </p>
            <ul v-else class="divide-y divide-[var(--surface-border)]">
                <li v-for="s in sinais" :key="s.termo" class="px-4 py-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold">{{ s.termo }}</span>
                        <span v-for="f in s.fontes" :key="f" class="rounded-full bg-[var(--surface-muted)] px-2 py-0.5 text-xs">{{ f }}</span>
                    </div>
                    <p v-for="e in s.exemplos.slice(0, 2)" :key="e" class="mt-1 truncate text-xs text-[var(--texto-suave)]" :title="e">{{ e }}</p>
                </li>
            </ul>
        </section>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <!-- TikTok Shop (Kalodata) -->
            <section class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" style="border-top: 3px solid var(--faixa-card)">
                <div class="border-b border-[var(--surface-border)] px-4 py-3">
                    <h2 class="text-base font-semibold"><i class="fab fa-tiktok mr-1" aria-hidden="true"></i> TikTok Shop · mais faturamento em 7 dias</h2>
                    <p class="text-xs text-[var(--texto-suave)]">Fonte: Kalodata. Atualiza uma vez por dia.</p>
                </div>
                <Deferred data="tiktok">
                    <template #fallback>
                        <p class="px-4 py-6 text-sm text-[var(--texto-suave)]" role="status"><i class="fas fa-circle-notch mr-1 animate-spin" aria-hidden="true"></i> Consultando o Kalodata…</p>
                    </template>
                    <p v-if="!tiktok.configurado" class="px-4 py-6 text-sm text-[var(--texto-suave)]">Falta a chave do Kalodata (KALODATA_API_KEY) no servidor.</p>
                    <p v-else-if="tiktok.erro" class="px-4 py-6 text-sm text-error" role="alert">{{ tiktok.erro }}</p>
                    <p v-else-if="tiktok.produtos.length === 0" class="px-4 py-6 text-sm text-[var(--texto-suave)]">Nenhum produto no período.</p>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-left text-sm">
                            <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-[var(--texto-suave)]">
                                <tr>
                                    <th scope="col" class="px-4 py-2">#</th>
                                    <th scope="col" class="py-2 pr-3">Produto</th>
                                    <th scope="col" class="py-2 pr-3 text-right">Faturamento</th>
                                    <th scope="col" class="py-2 pr-3 text-right">Vendas</th>
                                    <th scope="col" class="py-2 pr-3 text-right" title="Faturamento dividido pelas vendas">Preço médio</th>
                                    <th scope="col" class="py-2 pr-4 text-right" title="Faturamento contra os 7 dias anteriores">Cresc.</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--surface-border)]">
                                <tr v-for="p in tiktok.produtos" :key="p.id">
                                    <td class="px-4 py-2 text-[var(--texto-suave)]">{{ p.posicao }}</td>
                                    <td class="max-w-xs truncate py-2 pr-3" :title="p.nome">{{ p.nome }}</td>
                                    <td class="py-2 pr-3 text-right">{{ p.receita === null ? '—' : formatPrice(p.receita) }}</td>
                                    <td class="py-2 pr-3 text-right">{{ p.vendas === null ? '—' : formatNumber(p.vendas) }}</td>
                                    <td class="py-2 pr-3 text-right">{{ p.preco === null ? '—' : formatPrice(p.preco) }}</td>
                                    <td class="py-2 pr-4 text-right" :class="p.crescimento > 0 ? 'text-success' : (p.crescimento < 0 ? 'text-error' : '')">{{ p.crescimento === null ? '—' : formatPercent(p.crescimento) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Deferred>
            </section>

            <!-- Shopee (API de Afiliados) -->
            <section class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" style="border-top: 3px solid var(--faixa-card)">
                <div class="border-b border-[var(--surface-border)] px-4 py-3">
                    <h2 class="text-base font-semibold"><i class="fas fa-bag-shopping mr-1" aria-hidden="true"></i> Shopee · mais vendidos</h2>
                    <p class="text-xs text-[var(--texto-suave)]">Fonte: API de Afiliados da Shopee. Busca por palavra em <a href="/admin/oportunidades/shopee" class="underline">Oportunidades › Shopee</a>.</p>
                </div>
                <Deferred data="shopee">
                    <template #fallback>
                        <p class="px-4 py-6 text-sm text-[var(--texto-suave)]" role="status"><i class="fas fa-circle-notch mr-1 animate-spin" aria-hidden="true"></i> Consultando a Shopee…</p>
                    </template>
                    <div v-if="!shopee.configurado" class="px-4 py-6 text-sm text-[var(--texto-suave)]">
                        <p class="font-semibold text-[var(--texto)]">Falta conectar a API de Afiliados da Shopee.</p>
                        <p class="mt-1">A integração de vendedor só enxerga a própria loja. As vendas de outras lojas vêm da API de Afiliados (affiliate.shopee.com.br › Open API); com o AppID e a senha de lá no servidor (SHOPEE_AFILIADOS_APP_ID e SHOPEE_AFILIADOS_SECRET) este bloco passa a listar os mais vendidos.</p>
                    </div>
                    <p v-else-if="shopee.erro" class="px-4 py-6 text-sm text-error" role="alert">{{ shopee.erro }}</p>
                    <p v-else-if="shopee.produtos.length === 0" class="px-4 py-6 text-sm text-[var(--texto-suave)]">Nenhum produto encontrado.</p>
                    <ul v-else class="divide-y divide-[var(--surface-border)] text-sm">
                        <li v-for="p in shopee.produtos.slice(0, 20)" :key="p.id" class="flex items-center gap-3 px-4 py-2">
                            <span class="w-5 text-[var(--texto-suave)]">{{ p.posicao }}</span>
                            <a :href="p.link" target="_blank" rel="noopener" class="min-w-0 flex-1 truncate hover:underline" :title="p.nome">{{ p.nome }}</a>
                            <span class="whitespace-nowrap text-xs text-[var(--texto-suave)]">{{ p.vendas === null ? '' : `${formatNumber(p.vendas)} vendas` }}</span>
                            <span class="whitespace-nowrap">{{ faixaPreco(p) }}</span>
                        </li>
                    </ul>
                </Deferred>
            </section>

            <!-- Mercado Livre -->
            <section class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" style="border-top: 3px solid var(--faixa-card)">
                <div class="border-b border-[var(--surface-border)] px-4 py-3">
                    <h2 class="text-base font-semibold"><i class="fas fa-handshake mr-1" aria-hidden="true"></i> Mercado Livre · buscas em alta e mais vendidos de Casa</h2>
                    <p class="text-xs text-[var(--texto-suave)]">Fonte: API do Mercado Livre. Outras categorias em <a href="/admin/oportunidades/mercado-livre" class="underline">Oportunidades › Mercado Livre</a>.</p>
                </div>
                <Deferred data="mercadoLivre">
                    <template #fallback>
                        <p class="px-4 py-6 text-sm text-[var(--texto-suave)]" role="status"><i class="fas fa-circle-notch mr-1 animate-spin" aria-hidden="true"></i> Consultando o Mercado Livre (a primeira consulta do dia leva alguns segundos)…</p>
                    </template>
                    <p v-if="mercadoLivre.erro" class="px-4 pt-4 text-sm text-error" role="alert">{{ mercadoLivre.erro }}</p>
                    <div class="px-4 py-3">
                        <h3 class="text-xs font-semibold uppercase text-[var(--texto-suave)]">Buscas que mais crescem no site</h3>
                        <p v-if="mercadoLivre.buscas.length === 0" class="mt-1 text-sm text-[var(--texto-suave)]">Sem dados agora.</p>
                        <div v-else class="mt-2 flex flex-wrap gap-1.5">
                            <a v-for="t in mercadoLivre.buscas" :key="t.termo" :href="t.link || null" target="_blank" rel="noopener"
                                class="rounded-full border border-[var(--surface-border)] px-2 py-0.5 text-xs hover:bg-[var(--surface-muted)]">{{ t.termo }}</a>
                        </div>
                    </div>
                    <ul v-if="mercadoLivre.produtos.length" class="divide-y divide-[var(--surface-border)] border-t border-[var(--surface-border)] text-sm">
                        <li v-for="p in mercadoLivre.produtos" :key="p.id" class="flex items-center gap-3 px-4 py-2">
                            <span class="w-5 text-[var(--texto-suave)]">{{ p.posicao }}</span>
                            <a :href="p.link" target="_blank" rel="noopener" class="min-w-0 flex-1 truncate hover:underline" :title="p.nome">{{ p.nome }}</a>
                            <span v-if="p.voce_vende" class="rounded-full bg-[var(--surface-muted)] px-2 py-0.5 text-xs">você vende</span>
                            <span class="whitespace-nowrap text-xs text-[var(--texto-suave)]">{{ p.vendedores === null ? '' : `${p.vendedores} vendedores` }}</span>
                            <span class="whitespace-nowrap">{{ faixaPreco(p) }}</span>
                        </li>
                    </ul>
                </Deferred>
            </section>

            <!-- Google Trends -->
            <section class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" style="border-top: 3px solid var(--faixa-card)">
                <div class="border-b border-[var(--surface-border)] px-4 py-3">
                    <h2 class="text-base font-semibold"><i class="fab fa-google mr-1" aria-hidden="true"></i> Google · buscas em alta hoje no Brasil</h2>
                    <p class="text-xs text-[var(--texto-suave)]">Fonte: Google Trends. Mistura notícia e esporte; vale quando cruza com produto (bloco do topo).</p>
                </div>
                <Deferred data="google">
                    <template #fallback>
                        <p class="px-4 py-6 text-sm text-[var(--texto-suave)]" role="status"><i class="fas fa-circle-notch mr-1 animate-spin" aria-hidden="true"></i> Consultando o Google Trends…</p>
                    </template>
                    <p v-if="google.erro" class="px-4 py-6 text-sm text-error" role="alert">{{ google.erro }}</p>
                    <p v-else-if="google.termos.length === 0" class="px-4 py-6 text-sm text-[var(--texto-suave)]">Sem dados agora.</p>
                    <ul v-else class="divide-y divide-[var(--surface-border)] text-sm">
                        <li v-for="t in google.termos" :key="t.termo" class="px-4 py-2">
                            <div class="flex items-center gap-3">
                                <a :href="t.link" target="_blank" rel="noopener" class="min-w-0 flex-1 truncate font-medium hover:underline">{{ t.termo }}</a>
                                <span v-if="t.trafego" class="whitespace-nowrap text-xs text-[var(--texto-suave)]">{{ t.trafego }} buscas</span>
                            </div>
                            <p v-if="t.noticia" class="truncate text-xs text-[var(--texto-suave)]" :title="t.noticia">{{ t.noticia }}</p>
                        </li>
                    </ul>
                </Deferred>
            </section>
        </div>
    </AdminLayout>
</template>
