<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { RADAR_NOME } from '@/Shared/radarNome';
import MarcaLogo from './MarcaLogo.vue';
import BuscaTabela from './BuscaTabela.vue';
import Paginacao from './Paginacao.vue';
import MercadoLivreBloco from './MercadoLivreBloco.vue';
import { normalizar, useBuscaPaginada } from './useBuscaPaginada';
import { Deferred, Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const pagina = usePage();

// Radar de Oportunidades (pedido do Lira 2026-10-12): o que está vendendo e
// em alta fora da loja, numa tela só. Fontes: TikTok Shop (Kalodata),
// Mercado Livre (mais vendidos de Casa e buscas em alta) e Google Trends.
// Layout v4 (Lira 2026-10-12): Shopee fora até a API de Afiliados trazer
// dados. TikTok sozinho em cima, Mercado Livre embaixo e o Google em seis
// cards por assunto (gerais, música, personalidades, vídeos, notícias e
// produtos; pedido do Lira 2026-10-12).
// Cada fonte é prop adiada num grupo próprio (null até chegar). Ver RadarController.
// Submenu do Radar (Lira 2026-10-12): com `fonte` (/admin/radar/tiktok etc.)
// a tela mostra só o bloco daquela fonte, sem números do topo e sem cruzamento.
const props = defineProps({
    fonte: { type: String, default: null },
    fonteNome: { type: String, default: null },
    tiktok: { type: Object, default: null },
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
    // Dentro de Marketplaces › TikTok Shop › Oportunidades fica na aba.
    router.get(pagina.props.marketplaceNav?.url ?? (props.fonte ? `/admin/radar/${props.fonte}` : '/admin/radar'), { atualizar: 1 }, {
        preserveScroll: true,
        onStart: () => { carregando.value = true; },
        onFinish: () => { carregando.value = false; },
    });
};

// Cruzamento: termo em alta (buscas do ML e do Google) que também aparece em
// nome de produto vendendo muito em outra fonte, ou nas duas listas de busca.
// Comparação por palavra, sem acento; palavras curtas e genéricas não contam.
const IGNORAR = new Set(['para', 'com', 'sem', 'kit', 'jogo', 'the', 'dos', 'das', 'por', 'uma', 'novo', 'nova', 'original', 'pro', 'max', 'plus']);
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
        ...(props.google?.grupos?.produtos ?? []).map((t) => ({ termo: t.termo, origem: 'Busca no Google' })),
    ];
    const listas = [
        ['TikTok Shop', props.tiktok?.produtos ?? []],
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
// Busca e paginação na tela das três fontes (pedido do Lira 2026-10-12): cada
// fonte manda a lista inteira de uma vez, então filtrar e paginar aqui não
// gasta consulta nem crédito (o Kalodata cobra por consulta). A posição
// mostrada continua a do ranking, mesmo com a busca ativa.
const tt = useBuscaPaginada(computed(() => props.tiktok?.produtos ?? []), (p) => p.nome, 20);

// Faixa de números do topo; cada um espera a própria fonte chegar.
const faturamentoTikTok = computed(() => (props.tiktok?.produtos ?? []).reduce((soma, p) => soma + (p.receita ?? 0), 0));
const voceVendeMl = computed(() => (props.mercadoLivre?.produtos ?? []).filter((p) => p.voce_vende).length);

// Etiqueta de fonte do cruzamento: a cor diz de onde veio.
const corDaFonte = (texto) => {
    if (texto.includes('TikTok')) return 'fonte-tiktok';
    if (texto.includes('Mercado Livre')) return 'fonte-ml';
    return 'fonte-google';
};
const marcaDaFonte = (texto) => corDaFonte(texto).replace('fonte-', '');

// Cards do Google por assunto (pedido do Lira 2026-10-12). "Gerais" é o RSS
// de sempre; os outros vêm separados pelo TendenciasGoogle.
const CARDS_GOOGLE = [
    { chave: 'geral', titulo: 'Tendências gerais', icone: 'fa-arrow-trend-up', dica: 'Tudo o que mais cresce hoje: notícia, esporte, gente.' },
    { chave: 'musica', titulo: 'Música', icone: 'fa-music', dica: 'Cantores, músicas, shows e clipes.' },
    { chave: 'personalidades', titulo: 'Personalidades', icone: 'fa-star', dica: 'Artistas, influenciadores, atletas e famosos.' },
    { chave: 'videos', titulo: 'Vídeos', icone: 'fa-circle-play', dica: 'Filmes, séries, novelas, TV e streaming.' },
    { chave: 'noticias', titulo: 'Notícias', icone: 'fa-newspaper', dica: 'Acontecimentos, política, economia e clima.' },
    { chave: 'produtos', titulo: 'Produtos', icone: 'fa-bag-shopping', dica: 'Marcas, lançamentos e coisas que se compram. O mais útil para a loja.' },
];
// Cada card tem a própria busca e paginação (10 por página: o card é estreito).
const cardsGoogle = CARDS_GOOGLE.map((c) => {
    const termos = computed(() => (c.chave === 'geral' ? props.google?.termos : props.google?.grupos?.[c.chave]) ?? []);
    return { ...c, termos, ...useBuscaPaginada(termos, (t) => `${t.termo} ${t.noticia ?? ''}`, 10) };
});

const mostra = (fonte) => !props.fonte || props.fonte === fonte;
const titulo = computed(() => (props.fonteNome ? `${RADAR_NOME} · ${props.fonteNome}` : RADAR_NOME));
const tudoCarregado = computed(() => (!mostra('tiktok') || props.tiktok)
    && (!mostra('mercado-livre') || props.mercadoLivre)
    && (!mostra('google') || props.google));
</script>

<template>
    <Head :title="titulo" />
    <AdminLayout>
        <div class="radar-grafite">
            <div class="radar-topo mb-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-[var(--destaque)]">Oportunidades</p>
                    <h1 class="text-2xl font-bold">{{ titulo }}</h1>
                    <p class="mt-1 max-w-3xl text-sm text-[var(--texto-suave)]">
                        O que está vendendo e em alta fora da loja: {{ fonteNome ?? 'TikTok Shop, Mercado Livre e Google' }}. Serve para achar produto novo; não são as vendas da KazaKora.
                    </p>
                </div>
                <button type="button" class="rounded-lg bg-[var(--primary-solido)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50"
                    :disabled="carregando || !tudoCarregado" title="Refaz Mercado Livre e Google. O TikTok atualiza uma vez por dia (o Kalodata cobra por consulta)." @click="consultarDeNovo">
                    <i class="fas fa-rotate-right mr-1" :class="{ 'animate-spin': carregando || !tudoCarregado }" aria-hidden="true"></i>
                    {{ carregando || !tudoCarregado ? 'Consultando…' : 'Consultar de novo' }}
                </button>
            </div>

            <!-- Faixa de números (horizontal; rola de lado no celular) -->
            <div v-if="!fonte" class="mb-5 flex gap-3 overflow-x-auto pb-1 pt-1">
                <div class="kpi fonte-destaque">
                    <span class="kpi-icone"><i class="fas fa-bullseye" aria-hidden="true"></i></span>
                    <div><b>{{ tudoCarregado ? sinais.length : '…' }}</b><span>em alta em mais de um lugar</span></div>
                </div>
                <div class="kpi fonte-tiktok">
                    <MarcaLogo marca="tiktok" />
                    <div><b>{{ tiktok ? formatPriceK(faturamentoTikTok) : '…' }}</b><span>top {{ tiktok?.produtos?.length ?? '' }} do TikTok em 7 dias</span></div>
                </div>
                <div class="kpi fonte-ml">
                    <MarcaLogo marca="ml" />
                    <div><b>{{ mercadoLivre ? voceVendeMl : '…' }}</b><span>que você já vende no ML</span></div>
                </div>
                <div class="kpi fonte-google">
                    <MarcaLogo marca="google" />
                    <div><b>{{ google ? (google.termos?.length ?? 0) : '…' }}</b><span>buscas em alta no Google hoje</span></div>
                </div>
            </div>

            <!-- Em alta em mais de um lugar -->
            <section v-if="!fonte" class="destaque mb-6 rounded-2xl border border-[var(--surface-border)] p-4 md:p-5">
                <h2 class="text-base font-bold"><i class="fas fa-bullseye mr-1 text-[var(--destaque)]" aria-hidden="true"></i> Em alta em mais de um lugar</h2>
                <p class="text-xs text-[var(--texto-suave)]">Busca em alta que também aparece entre os mais vendidos de outra fonte. É o sinal mais forte da tela.</p>
                <p v-if="!tudoCarregado" class="pt-4 text-sm text-[var(--texto-suave)]" role="status">
                    <i class="fas fa-circle-notch mr-1 animate-spin" aria-hidden="true"></i> Cruzando as fontes…
                </p>
                <p v-else-if="sinais.length === 0" class="pt-4 text-sm text-[var(--texto-suave)]">
                    Nenhuma busca em alta bate com os mais vendidos hoje. Veja as fontes abaixo uma a uma.
                </p>
                <ul v-else class="mt-2 flex snap-x gap-3 overflow-x-auto pb-2 pt-1">
                    <li v-for="s in sinais" :key="s.termo" tabindex="0" class="card-sinal w-72 shrink-0 snap-start rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate font-bold" :title="s.termo">{{ s.termo }}</span>
                            <span class="etiqueta fonte-destaque whitespace-nowrap">{{ s.fontes.length }} fontes</span>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <span v-for="f in s.fontes" :key="f" class="etiqueta etiqueta-marca" :class="corDaFonte(f)"><MarcaLogo :marca="marcaDaFonte(f)" class="marca-mini" />{{ f }}</span>
                        </div>
                        <p v-for="e in s.exemplos.slice(0, 2)" :key="e" class="mt-1.5 truncate text-xs text-[var(--texto-suave)]" :title="e">{{ e }}</p>
                    </li>
                </ul>
            </section>

            <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                <!-- TikTok Shop (Kalodata): sozinho em cima, ocupa a largura toda -->
                <section v-if="mostra('tiktok')" class="card-fonte fonte-tiktok xl:col-span-2">
                    <div class="card-cabeca">
                        <MarcaLogo marca="tiktok" />
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
                        <template v-else>
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--surface-border)] px-4 py-3">
                            <BuscaTabela v-model="tt.busca.value" rotulo="Buscar produto no ranking do TikTok" placeholder="Buscar produto (ex.: garrafa, luminária)" />
                            <span class="text-xs text-[var(--texto-suave)]" role="status" aria-live="polite">{{ tt.faixa.value }} produtos</span>
                        </div>
                        <p v-if="tt.filtrados.value.length === 0" class="px-4 py-6 text-sm text-[var(--texto-suave)]">Nenhum produto com “{{ tt.busca.value }}” no ranking.</p>
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
                                    <tr aria-hidden="true"><td colspan="6" class="faixa"></td></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in tt.daPagina.value" :key="p.id">
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
                        <Paginacao :pagina="tt.pagina.value" :total="tt.totalPaginas.value" rotulo="Páginas do ranking do TikTok" @ir="tt.irParaPagina" />
                        </template>
                    </Deferred>
                </section>

                <!-- Mercado Livre: embaixo do TikTok, largura toda (o Google virou 6 cards) -->
                <MercadoLivreBloco v-if="mostra('mercado-livre')" :mercado-livre="mercadoLivre" class="xl:col-span-2" />

            </div>

            <!-- Google Trends em cards por assunto (pedido do Lira 2026-10-12):
                 gerais, música, personalidades, vídeos, notícias e produtos. -->
            <section v-if="mostra('google')" class="mt-6" aria-labelledby="google-titulo">
                <div class="mb-3 flex items-center gap-3">
                    <MarcaLogo marca="google" />
                    <div class="min-w-0">
                        <h2 id="google-titulo" class="text-base font-bold">Google Trends · em alta hoje no Brasil</h2>
                        <p class="text-xs text-[var(--texto-suave)]">Gerais pelo RSS do Google Trends; os outros cards separam as buscas das últimas 24 h por assunto.</p>
                    </div>
                </div>
                <Deferred data="google">
                    <template #fallback>
                        <p class="card-fonte fonte-google px-4 py-6 text-sm text-[var(--texto-suave)]" role="status"><i class="fas fa-circle-notch mr-1 animate-spin" aria-hidden="true"></i> Consultando o Google Trends…</p>
                    </template>
                    <p v-if="google.erro_grupos" class="mb-3 text-sm text-error" role="alert">{{ google.erro_grupos }}</p>
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
                        <section v-for="c in cardsGoogle" :key="c.chave" class="card-fonte fonte-google">
                            <div class="card-cabeca">
                                <span class="kpi-icone"><i class="fas" :class="c.icone" aria-hidden="true"></i></span>
                                <div class="min-w-0">
                                    <h3 class="text-base font-bold">{{ c.titulo }}</h3>
                                    <p class="text-xs text-[var(--texto-suave)]">{{ c.dica }}</p>
                                </div>
                            </div>
                            <p v-if="c.chave === 'geral' && google.erro" class="px-4 py-6 text-sm text-error" role="alert">{{ google.erro }}</p>
                            <p v-else-if="c.termos.value.length === 0" class="px-4 py-6 text-sm text-[var(--texto-suave)]">Nada em alta neste assunto agora.</p>
                            <template v-else>
                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--surface-border)] px-4 py-3">
                                    <BuscaTabela v-model="c.busca.value" :rotulo="`Buscar em ${c.titulo}`" placeholder="Buscar" />
                                    <span class="text-xs text-[var(--texto-suave)]" role="status" aria-live="polite">{{ c.faixa.value }}</span>
                                </div>
                                <p v-if="c.filtrados.value.length === 0" class="px-4 py-6 text-sm text-[var(--texto-suave)]">Nada com “{{ c.busca.value }}” neste assunto.</p>
                                <table v-else class="tabela">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="pl-4">Busca</th>
                                            <th scope="col" class="pr-4 text-right">Volume</th>
                                        </tr>
                                        <tr aria-hidden="true"><td colspan="2" class="faixa"></td></tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="t in c.daPagina.value" :key="t.termo">
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
                                <Paginacao :pagina="c.pagina.value" :total="c.totalPaginas.value" :rotulo="`Páginas de ${c.titulo}`" @ir="c.irParaPagina" />
                            </template>
                        </section>
                    </div>
                </Deferred>
            </section>
        </div>
    </AdminLayout>
</template>

<!-- Estilo das fontes do Radar num arquivo só: o bloco do Mercado Livre
     (MercadoLivreBloco.vue) também aparece em Oportunidades › Mercado Livre. -->
<style scoped src="./radar.css"></style>
