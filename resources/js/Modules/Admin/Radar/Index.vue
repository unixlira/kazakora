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
// Notação curta pedida pelo Lira (2026-10-12): 2,9k, 412k, 1,9M.
const formatK = (value) => {
    const n = value ?? 0;
    const curto = (v, sufixo) => `${v.toLocaleString('pt-BR', { maximumFractionDigits: v < 10 ? 1 : 0 })}${sufixo}`;
    if (Math.abs(n) >= 1_000_000) return curto(n / 1_000_000, 'M');
    if (Math.abs(n) >= 1_000) return curto(n / 1_000, 'k');
    return formatNumber(n);
};
const formatPriceK = (value) => (Math.abs(value ?? 0) >= 1_000 ? `R$ ${formatK(value)}` : formatPrice(value));
// O Google manda o volume como "20000+"; vira "20k+". Outro formato passa igual.
const trafegoK = (texto) => {
    const m = String(texto).replace(/\./g, '').match(/^(\d+)\+$/);
    return m ? `${formatK(Number(m[1]))}+` : texto;
};
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
// Faixa de números do topo; cada um espera a própria fonte chegar.
const faturamentoTikTok = computed(() => (props.tiktok?.produtos ?? []).reduce((soma, p) => soma + (p.receita ?? 0), 0));
const voceVendeMl = computed(() => (props.mercadoLivre?.produtos ?? []).filter((p) => p.voce_vende).length);

// Etiqueta de fonte do cruzamento: a cor diz de onde veio.
const corDaFonte = (texto) => {
    if (texto.includes('TikTok')) return 'fonte-tiktok';
    if (texto.includes('Shopee')) return 'fonte-shopee';
    if (texto.includes('Mercado Livre')) return 'fonte-ml';
    return 'fonte-google';
};

const tudoCarregado = computed(() => props.tiktok && props.shopee && props.mercadoLivre && props.google);
</script>

<template>
    <Head :title="RADAR_NOME" />
    <AdminLayout>
        <div class="radar-grafite">
            <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-[var(--destaque)]">Oportunidades</p>
                    <h1 class="text-2xl font-bold">{{ RADAR_NOME }}</h1>
                    <p class="mt-1 max-w-3xl text-sm text-[var(--texto-suave)]">
                        O que está vendendo e em alta fora da loja: TikTok Shop, Shopee, Mercado Livre e Google. Serve para achar produto novo; não são as vendas da KazaKora.
                    </p>
                </div>
                <button type="button" class="rounded-lg bg-[var(--primary-solido)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50"
                    :disabled="carregando || !tudoCarregado" title="Refaz Shopee, Mercado Livre e Google. O TikTok atualiza uma vez por dia (o Kalodata cobra por consulta)." @click="consultarDeNovo">
                    <i class="fas fa-rotate-right mr-1" :class="{ 'animate-spin': carregando || !tudoCarregado }" aria-hidden="true"></i>
                    {{ carregando || !tudoCarregado ? 'Consultando…' : 'Consultar de novo' }}
                </button>
            </div>

            <!-- Faixa de números (horizontal; rola de lado no celular) -->
            <div class="mb-5 flex gap-3 overflow-x-auto pb-1">
                <div class="kpi">
                    <span class="kpi-icone fonte-destaque"><i class="fas fa-bullseye" aria-hidden="true"></i></span>
                    <div><b>{{ tudoCarregado ? sinais.length : '…' }}</b><span>em alta em mais de um lugar</span></div>
                </div>
                <div class="kpi">
                    <span class="kpi-icone fonte-tiktok"><i class="fab fa-tiktok" aria-hidden="true"></i></span>
                    <div><b>{{ tiktok ? formatPriceK(faturamentoTikTok) : '…' }}</b><span>top {{ tiktok?.produtos?.length ?? '' }} do TikTok em 7 dias</span></div>
                </div>
                <div class="kpi">
                    <span class="kpi-icone fonte-shopee"><i class="fas fa-bag-shopping" aria-hidden="true"></i></span>
                    <div><b>{{ shopee ? Math.min(shopee.produtos?.length ?? 0, 20) : '…' }}</b><span>mais vendidos da Shopee</span></div>
                </div>
                <div class="kpi">
                    <span class="kpi-icone fonte-ml"><i class="fas fa-handshake" aria-hidden="true"></i></span>
                    <div><b>{{ mercadoLivre ? voceVendeMl : '…' }}</b><span>que você já vende no ML</span></div>
                </div>
            </div>

            <!-- Em alta em mais de um lugar -->
            <section class="destaque mb-6 rounded-2xl border border-[var(--surface-border)] p-4 md:p-5">
                <h2 class="text-base font-bold"><i class="fas fa-bullseye mr-1 text-[var(--destaque)]" aria-hidden="true"></i> Em alta em mais de um lugar</h2>
                <p class="text-xs text-[var(--texto-suave)]">Busca em alta que também aparece entre os mais vendidos de outra fonte. É o sinal mais forte da tela.</p>
                <p v-if="!tudoCarregado" class="pt-4 text-sm text-[var(--texto-suave)]" role="status">
                    <i class="fas fa-circle-notch mr-1 animate-spin" aria-hidden="true"></i> Cruzando as fontes…
                </p>
                <p v-else-if="sinais.length === 0" class="pt-4 text-sm text-[var(--texto-suave)]">
                    Nenhuma busca em alta bate com os mais vendidos hoje. Veja as fontes abaixo uma a uma.
                </p>
                <ul v-else class="mt-3 flex snap-x gap-3 overflow-x-auto pb-2">
                    <li v-for="s in sinais" :key="s.termo" class="w-72 shrink-0 snap-start rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate font-bold" :title="s.termo">{{ s.termo }}</span>
                            <span class="etiqueta fonte-destaque whitespace-nowrap">{{ s.fontes.length }} fontes</span>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <span v-for="f in s.fontes" :key="f" class="etiqueta" :class="corDaFonte(f)">{{ f }}</span>
                        </div>
                        <p v-for="e in s.exemplos.slice(0, 2)" :key="e" class="mt-1.5 truncate text-xs text-[var(--texto-suave)]" :title="e">{{ e }}</p>
                    </li>
                </ul>
            </section>

            <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                <!-- TikTok Shop (Kalodata) -->
                <section class="card-fonte fonte-tiktok">
                    <div class="card-cabeca">
                        <span class="card-icone"><i class="fab fa-tiktok" aria-hidden="true"></i></span>
                        <div class="min-w-0">
                            <h2 class="text-base font-bold">TikTok Shop · mais faturamento em 7 dias</h2>
                            <p class="text-xs text-[var(--texto-suave)]">Fonte: Kalodata. Atualiza uma vez por dia.</p>
                        </div>
                    </div>
                    <Deferred data="tiktok">
                        <template #fallback>
                            <p class="px-4 py-6 text-sm text-[var(--texto-suave)]" role="status"><i class="fas fa-circle-notch mr-1 animate-spin" aria-hidden="true"></i> Consultando o Kalodata…</p>
                        </template>
                        <p v-if="!tiktok.configurado" class="px-4 py-6 text-sm text-[var(--texto-suave)]">Falta a chave do Kalodata (KALODATA_API_KEY) no servidor.</p>
                        <p v-else-if="tiktok.erro" class="px-4 py-6 text-sm text-error" role="alert">{{ tiktok.erro }}</p>
                        <p v-else-if="tiktok.produtos.length === 0" class="px-4 py-6 text-sm text-[var(--texto-suave)]">Nenhum produto no período.</p>
                        <div v-else class="overflow-x-auto">
                            <table class="tabela min-w-[520px]">
                                <thead>
                                    <tr>
                                        <th scope="col" class="pl-4">#</th>
                                        <th scope="col">Produto</th>
                                        <th scope="col" class="text-right">Faturamento</th>
                                        <th scope="col" class="text-right">Vendas</th>
                                        <th scope="col" class="text-right" title="Faturamento dividido pelas vendas">Preço médio</th>
                                        <th scope="col" class="pr-4 text-right" title="Faturamento contra os 7 dias anteriores">Cresc.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in tiktok.produtos" :key="p.id">
                                        <td class="pl-4"><span class="posicao" :class="{ 'posicao-top': p.posicao <= 3 }">{{ p.posicao }}</span></td>
                                        <td class="max-w-0 w-full truncate font-medium" :title="p.nome">{{ p.nome }}</td>
                                        <td class="text-right" :title="p.receita === null ? null : formatPrice(p.receita)">{{ p.receita === null ? '—' : formatPriceK(p.receita) }}</td>
                                        <td class="text-right" :title="p.vendas === null ? null : formatNumber(p.vendas)">{{ p.vendas === null ? '—' : formatK(p.vendas) }}</td>
                                        <td class="whitespace-nowrap text-right">{{ p.preco === null ? '—' : formatPrice(p.preco) }}</td>
                                        <td class="pr-4 text-right">
                                            <span v-if="p.crescimento === null">—</span>
                                            <span v-else class="etiqueta" :class="p.crescimento > 0 ? 'bg-lightsuccess text-success' : (p.crescimento < 0 ? 'bg-lighterror text-error' : 'bg-[var(--surface-muted)]')">{{ formatPercent(p.crescimento) }}</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </Deferred>
                </section>

                <!-- Shopee (API de Afiliados) -->
                <section class="card-fonte fonte-shopee">
                    <div class="card-cabeca">
                        <span class="card-icone"><i class="fas fa-bag-shopping" aria-hidden="true"></i></span>
                        <div class="min-w-0">
                            <h2 class="text-base font-bold">Shopee · mais vendidos</h2>
                            <p class="text-xs text-[var(--texto-suave)]">Fonte: API de Afiliados da Shopee. Busca por palavra em <a href="/admin/oportunidades/shopee" class="underline">Oportunidades › Shopee</a>.</p>
                        </div>
                    </div>
                    <Deferred data="shopee">
                        <template #fallback>
                            <p class="px-4 py-6 text-sm text-[var(--texto-suave)]" role="status"><i class="fas fa-circle-notch mr-1 animate-spin" aria-hidden="true"></i> Consultando a Shopee…</p>
                        </template>
                        <div v-if="!shopee.configurado" class="px-4 py-6 text-sm text-[var(--texto-suave)]">
                            <p class="font-semibold text-[var(--surface-foreground)]">Falta conectar a API de Afiliados da Shopee.</p>
                            <p class="mt-1">A integração de vendedor só enxerga a própria loja. As vendas de outras lojas vêm da API de Afiliados (affiliate.shopee.com.br › Open API); com o AppID e a senha de lá no servidor (SHOPEE_AFILIADOS_APP_ID e SHOPEE_AFILIADOS_SECRET) este bloco passa a listar os mais vendidos.</p>
                        </div>
                        <p v-else-if="shopee.erro" class="px-4 py-6 text-sm text-error" role="alert">{{ shopee.erro }}</p>
                        <p v-else-if="shopee.produtos.length === 0" class="px-4 py-6 text-sm text-[var(--texto-suave)]">Nenhum produto encontrado.</p>
                        <table v-else class="tabela">
                            <thead>
                                <tr>
                                    <th scope="col" class="pl-4">#</th>
                                    <th scope="col">Produto</th>
                                    <th scope="col" class="text-right">Vendas</th>
                                    <th scope="col" class="pr-4 text-right">Preço</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in shopee.produtos.slice(0, 20)" :key="p.id">
                                    <td class="pl-4"><span class="posicao" :class="{ 'posicao-top': p.posicao <= 3 }">{{ p.posicao }}</span></td>
                                    <td class="max-w-0 w-full truncate font-medium"><a :href="p.link" target="_blank" rel="noopener" class="hover:underline" :title="p.nome">{{ p.nome }}</a></td>
                                    <td class="whitespace-nowrap text-right" :title="p.vendas === null ? null : formatNumber(p.vendas)">{{ p.vendas === null ? '' : formatK(p.vendas) }}</td>
                                    <td class="whitespace-nowrap pr-4 text-right font-bold">{{ faixaPreco(p) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </Deferred>
                </section>

                <!-- Mercado Livre -->
                <section class="card-fonte fonte-ml">
                    <div class="card-cabeca">
                        <span class="card-icone"><i class="fas fa-handshake" aria-hidden="true"></i></span>
                        <div class="min-w-0">
                            <h2 class="text-base font-bold">Mercado Livre · buscas em alta e mais vendidos de Casa</h2>
                            <p class="text-xs text-[var(--texto-suave)]">Fonte: API do Mercado Livre. Outras categorias em <a href="/admin/oportunidades/mercado-livre" class="underline">Oportunidades › Mercado Livre</a>.</p>
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
                        <table v-if="mercadoLivre.produtos.length" class="tabela border-t border-[var(--surface-border)]">
                            <thead>
                                <tr>
                                    <th scope="col" class="pl-4">#</th>
                                    <th scope="col">Produto</th>
                                    <th scope="col" class="text-right">Vendedores</th>
                                    <th scope="col" class="pr-4 text-right">Preço</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in mercadoLivre.produtos" :key="p.id">
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
                    </Deferred>
                </section>

                <!-- Google Trends -->
                <section class="card-fonte fonte-google">
                    <div class="card-cabeca">
                        <span class="card-icone"><i class="fab fa-google" aria-hidden="true"></i></span>
                        <div class="min-w-0">
                            <h2 class="text-base font-bold">Google · buscas em alta hoje no Brasil</h2>
                            <p class="text-xs text-[var(--texto-suave)]">Fonte: Google Trends. Mistura notícia e esporte; vale quando cruza com produto (bloco do topo).</p>
                        </div>
                    </div>
                    <Deferred data="google">
                        <template #fallback>
                            <p class="px-4 py-6 text-sm text-[var(--texto-suave)]" role="status"><i class="fas fa-circle-notch mr-1 animate-spin" aria-hidden="true"></i> Consultando o Google Trends…</p>
                        </template>
                        <p v-if="google.erro" class="px-4 py-6 text-sm text-error" role="alert">{{ google.erro }}</p>
                        <p v-else-if="google.termos.length === 0" class="px-4 py-6 text-sm text-[var(--texto-suave)]">Sem dados agora.</p>
                        <table v-else class="tabela">
                            <tbody>
                                <tr v-for="t in google.termos" :key="t.termo">
                                    <td class="max-w-0 w-full pl-4">
                                        <a :href="t.link" target="_blank" rel="noopener" class="block truncate font-semibold hover:underline">{{ t.termo }}</a>
                                        <p v-if="t.noticia" class="truncate text-xs text-[var(--texto-suave)]" :title="t.noticia">{{ t.noticia }}</p>
                                    </td>
                                    <td class="pr-4 text-right">
                                        <span v-if="t.trafego" class="etiqueta etiqueta-fonte whitespace-nowrap" :title="`${t.trafego} buscas`">{{ trafegoK(t.trafego) }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </Deferred>
                </section>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
/* Visual do Radar (pedido do Lira 2026-10-12): cada fonte com a cor dela no
   cabeçalho do card e nas etiquetas; no escuro o fundo vira grafite (ver
   .radar-grafite no app.css). --cor é o sólido do ícone e --cor-texto o tom
   legível em cima do fundo tingido. */
.fonte-tiktok { --cor: #111111; --cor-texto: #111111; --tinta: 9%; }
.fonte-shopee { --cor: #ee4d2d; --cor-texto: #b5341b; --tinta: 11%; }
.fonte-ml { --cor: #2d3277; --cor-texto: #2d3277; --cor-fundo: #ffe600; --tinta: 18%; }
.fonte-google { --cor: #1a73e8; --cor-texto: #1557b0; --tinta: 10%; }
.fonte-destaque { --cor: #c2410c; --cor-texto: #c2410c; --tinta: 12%; }
.dark .fonte-tiktok { --cor: #000000; --cor-texto: #e8e3e5; --tinta: 14%; }
.dark .fonte-shopee { --cor-texto: #ff9a80; --tinta: 16%; }
.dark .fonte-ml { --cor-texto: #ffe866; --tinta: 12%; }
.dark .fonte-google { --cor-texto: #8ab4f8; --tinta: 16%; }
.dark .fonte-destaque { --cor: #ff8c4d; --cor-texto: #ff8c4d; --tinta: 16%; }

.kpi {
    display: flex;
    flex: 1 0 13rem;
    align-items: center;
    gap: 0.75rem;
    border: 1px solid var(--surface-border);
    border-radius: 14px;
    background: var(--surface);
    padding: 0.85rem 1rem;
}
.kpi b { display: block; font-size: 1.375rem; line-height: 1.1; font-variant-numeric: tabular-nums; }
.kpi span:not(.kpi-icone) { font-size: 0.75rem; color: var(--texto-suave); }
.kpi-icone {
    display: grid;
    flex: none;
    place-items: center;
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 12px;
    background: color-mix(in oklab, var(--cor-fundo, var(--cor)) var(--tinta), var(--surface));
    color: var(--cor-texto);
}

.destaque {
    border-left: 4px solid #c2410c;
    background: linear-gradient(135deg, color-mix(in oklab, #c2410c 7%, var(--surface)) 0%, var(--surface) 60%);
}

.card-fonte {
    overflow: hidden;
    border: 1px solid var(--surface-border);
    border-radius: 16px;
    background: var(--surface);
    box-shadow: 0 1px 2px rgb(0 0 0 / 0.05);
}
.card-cabeca {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    border-bottom: 1px solid var(--surface-border);
    background: color-mix(in oklab, var(--cor-fundo, var(--cor)) var(--tinta), var(--surface));
    padding: 0.85rem 1rem;
}
.card-icone {
    display: grid;
    flex: none;
    place-items: center;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 10px;
    background: var(--cor-fundo, var(--cor));
    color: #ffffff;
}
.fonte-ml .card-icone { color: #2d3277; }

.etiqueta {
    display: inline-block;
    border-radius: 999px;
    padding: 0.125rem 0.5rem;
    font-size: 0.6875rem;
    font-weight: 700;
}
.etiqueta.fonte-tiktok, .etiqueta.fonte-shopee, .etiqueta.fonte-ml, .etiqueta.fonte-google, .etiqueta.fonte-destaque, .etiqueta-fonte {
    background: color-mix(in oklab, var(--cor-fundo, var(--cor)) var(--tinta), var(--surface));
    color: var(--cor-texto);
}
.etiqueta-busca {
    border: 1px solid color-mix(in oklab, var(--cor-fundo) 45%, var(--surface));
    background: color-mix(in oklab, var(--cor-fundo) var(--tinta), var(--surface));
    font-weight: 500;
    color: var(--surface-foreground);
}
.etiqueta-busca:hover { background: color-mix(in oklab, var(--cor-fundo) 35%, var(--surface)); }

.tabela { width: 100%; text-align: left; font-size: 0.875rem; font-variant-numeric: tabular-nums; }
.tabela th {
    border-bottom: 1px solid var(--surface-border);
    padding: 0.5rem 0.75rem 0.5rem 0;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: nowrap;
    color: var(--texto-suave);
}
.tabela td { padding: 0.55rem 0.75rem 0.55rem 0; border-bottom: 1px solid var(--surface-border); }
.tabela th.pl-4, .tabela td.pl-4 { padding-left: 1rem; }
.tabela th.pr-4, .tabela td.pr-4 { padding-right: 1rem; }
.tabela tbody tr:last-child td { border-bottom: 0; }
.tabela tbody tr:nth-child(even) td { background: color-mix(in oklab, var(--surface-muted) 60%, var(--surface)); }

.posicao {
    display: inline-grid;
    place-items: center;
    width: 1.4rem;
    height: 1.4rem;
    border-radius: 7px;
    background: var(--surface-muted);
    font-size: 0.6875rem;
    font-weight: 700;
    color: var(--texto-suave);
}
.posicao-top { background: var(--primary-solido); color: #ffffff; }
</style>
