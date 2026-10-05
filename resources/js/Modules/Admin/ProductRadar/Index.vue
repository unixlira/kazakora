<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    products: { type: Array, default: () => [] },
    airImportOpportunities: { type: Array, default: () => [] },
    trendTerms: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    sourceNotice: { type: String, default: '' },
    scoreFormula: { type: String, default: '' },
    dataSources: { type: Array, default: () => [] },
    providerStatus: { type: Array, default: () => [] },
    crawlerLayers: { type: Array, default: () => [] },
});

const selectedRecommendation = ref('Todos');
const selectedRisk = ref('Todos');
const selectedMarketplace = ref('Todos');

const recommendations = ['Todos', 'Prioridade para cotação', 'Pesquisar fornecedor', 'Monitorar mercado'];
const riskLevels = ['Todos', 'Baixo', 'Médio', 'Alto'];
const marketplaces = computed(() => ['Todos', ...new Set(props.products.map((product) => product.marketplace).filter(Boolean))]);

const marketplaceTargets = [
    {
        name: 'Mercado Livre',
        accent: 'emerald',
        status: 'captura direta ativa',
        description: 'Produtos priorizados a partir do ranking público e cruzamento com sinais do Google Trends.',
        method: 'Fonte direta do marketplace',
    },
    {
        name: 'Shopee',
        accent: 'purple',
        status: 'hipótese por aderência',
        description: 'Itens leves, baratos e de giro rápido para validar quando o crawler público da Shopee entrar.',
        method: 'Lista estimada até o parser próprio estar ativo',
    },
    {
        name: 'TikTok Shop',
        accent: 'pink',
        status: 'hipótese viral',
        description: 'Produtos demonstráveis em vídeo curto, com apelo visual e compra por impulso.',
        method: 'Lista estimada até URLs públicas estáveis serem validadas',
    },
    {
        name: 'Amazon',
        accent: 'slate',
        status: 'hipótese por catálogo',
        description: 'Produtos com busca recorrente, ficha técnica clara e potencial de comparação por review.',
        method: 'Lista estimada até o crawler de busca e produto estar ativo',
    },
];

const filteredProducts = computed(() => props.products.filter((product) => {
    const recommendationMatches = selectedRecommendation.value === 'Todos' || product.recommendation === selectedRecommendation.value;
    const riskMatches = selectedRisk.value === 'Todos' || product.riskLevel === selectedRisk.value;
    const marketplaceMatches = selectedMarketplace.value === 'Todos' || product.marketplace === selectedMarketplace.value;

    return recommendationMatches && riskMatches && marketplaceMatches;
}));

const topProduct = computed(() => props.products[0] ?? null);

const normalizeText = (value) => String(value ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase();

const textForProduct = (product) => normalizeText(`${product.productName ?? ''} ${product.category ?? ''}`);

const includesAny = (text, terms) => terms.some((term) => text.includes(term));

const targetFitScore = (product, targetName) => {
    const text = textForProduct(product);
    const base = Number(product.opportunityScore ?? 0);
    let adjustment = 0;

    if (targetName === 'Mercado Livre') {
        adjustment += product.marketplace === 'Mercado Livre' ? 10 : -8;
        adjustment += Number(product.marketplaceRank ?? 90) <= 25 ? 8 : 0;
    }

    if (targetName === 'Shopee') {
        if (includesAny(text, ['cabo', 'fone', 'case', 'capinha', 'organizador', 'cozinha', 'beleza', 'acrilico', 'bambu', 'lapela', 'carregador'])) adjustment += 14;
        if (includesAny(text, ['smartphone', 'iphone', 'tv ', 'notebook', 'geladeira', 'lavadora'])) adjustment -= 18;
    }

    if (targetName === 'TikTok Shop') {
        if (includesAny(text, ['mini', 'portatil', 'turbo', 'beleza', 'cozinha', 'organizador', 'microfone', 'lapela', 'led', 'soprador', 'aspirador'])) adjustment += 18;
        if (includesAny(text, ['gift card', 'digital', 'cartao', 'smartphone', 'tv ', 'notebook'])) adjustment -= 18;
    }

    if (targetName === 'Amazon') {
        if (includesAny(text, ['ferramenta', 'eletronico', 'cozinha', 'casa', 'organizador', 'carregador', 'power bank', 'aspirador', 'microfone'])) adjustment += 12;
        if (includesAny(text, ['moda', 'tenis', 'roupa', 'gift card', 'digital'])) adjustment -= 14;
    }

    if (product.riskLevel === 'Alto') adjustment -= 12;
    if (product.riskLevel === 'Baixo') adjustment += 4;
    if (product.matchedTrend) adjustment += 6;

    return Math.max(0, Math.min(100, Math.round(base + adjustment)));
};

const trendSignalLabel = (product) => {
    if (product.matchedTrend) return `Google Trends: ${product.matchedTrend}`;
    if (Number(product.trend30d ?? 0) >= 60) return 'Google Trends: sinal forte';
    if (Number(product.trend30d ?? 0) > 0) return 'Google Trends: sinal parcial';
    return 'Google Trends: sem match direto hoje';
};

const buyCeilingLabel = (product) => {
    const margin = Number(product.estimatedMargin ?? 0);
    if (margin >= 70) return 'Compra alvo: até 30% do preço público';
    if (margin >= 55) return 'Compra alvo: até 38% do preço público';
    if (margin >= 40) return 'Compra alvo: até 45% do preço público';
    return 'Compra alvo: validar margem antes de cotar';
};

const nextStepFor = (targetName, product) => {
    if (targetName === 'Mercado Livre') return 'Cotar fornecedor e comparar com os 10 primeiros anúncios públicos.';
    if (targetName === 'Shopee') return 'Validar manualmente preço de entrada, frete e volume visível quando a coleta dedicada estiver ativa.';
    if (targetName === 'TikTok Shop') return 'Testar apelo em vídeo curto e fornecedor com embalagem leve.';
    if (targetName === 'Amazon') return 'Checar reviews, ficha técnica, disponibilidade e preço de compra.';
    return product.recommendation;
};

const providerFor = (name) => props.providerStatus.find((provider) => provider.name === name);

const opportunityColumns = computed(() => marketplaceTargets.map((target) => {
    const mercadoLivreProvider = providerFor('Mercado Livre');
    const isMercadoLivreDirect = target.name === 'Mercado Livre'
        && mercadoLivreProvider?.status === 'Conectado'
        && props.products.some((product) => product.marketplace === 'Mercado Livre');

    const status = target.name === 'Mercado Livre'
        ? (isMercadoLivreDirect ? 'captura direta ativa' : 'fallback de segurança')
        : target.status;

    const method = target.name === 'Mercado Livre'
        ? (mercadoLivreProvider?.detail ?? 'Aguardando leitura da fonte pública do Mercado Livre.')
        : target.method;

    const items = [...props.products]
        .map((product) => ({
            ...product,
            targetMarketplace: target.name,
            channelScore: targetFitScore(product, target.name),
            trendSignalLabel: trendSignalLabel(product),
            buyCeilingLabel: buyCeilingLabel(product),
            nextStepLabel: nextStepFor(target.name, product),
            confidenceLabel: target.name === 'Mercado Livre' && isMercadoLivreDirect && product.marketplace === 'Mercado Livre'
                ? 'Confiança: captura direta'
                : 'Confiança: hipótese ou fallback até crawler dedicado',
        }))
        .filter((product) => target.name === 'Mercado Livre' || product.riskLevel !== 'Alto')
        .sort((a, b) => b.channelScore - a.channelScore)
        .slice(0, 4);

    return {
        ...target,
        status,
        method,
        items,
    };
}));

const formatPercent = (value) => {
    if (value === null || value === undefined) return 'A validar';
    return `${Number(value).toLocaleString('pt-BR', { maximumFractionDigits: 0 })}/100`;
};

const recommendationClass = (recommendation) => ({
    'Prioridade para cotação': 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200',
    'Pesquisar fornecedor': 'bg-amber-100 text-amber-700 ring-1 ring-amber-200',
    'Monitorar mercado': 'bg-slate-100 text-slate-600 ring-1 ring-slate-200',
}[recommendation] ?? 'bg-slate-100 text-slate-600 ring-1 ring-slate-200');

const scoreGradient = (score) => {
    if (score >= 68) return 'from-emerald-400 to-teal-500';
    if (score >= 48) return 'from-purple-400 to-fuchsia-500';
    return 'from-slate-400 to-slate-500';
};

const riskClass = (riskLevel) => ({
    Baixo: 'text-emerald-600 bg-emerald-50 ring-1 ring-emerald-100',
    Médio: 'text-amber-600 bg-amber-50 ring-1 ring-amber-100',
    Alto: 'text-rose-600 bg-rose-50 ring-1 ring-rose-100',
}[riskLevel] ?? 'text-slate-600 bg-slate-50 ring-1 ring-slate-100');

const airFitClass = (label) => ({
    'Prioridade para cotação aérea': 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200',
    'Cotar com validação': 'bg-amber-100 text-amber-700 ring-1 ring-amber-200',
    'Monitorar antes de importar': 'bg-slate-100 text-slate-600 ring-1 ring-slate-200',
}[label] ?? 'bg-slate-100 text-slate-600 ring-1 ring-slate-200');

const providerClass = (status) => ({
    Conectado: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100',
    Fallback: 'bg-amber-50 text-amber-700 ring-1 ring-amber-100',
    'Crawler pendente': 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-100',
    'Estrutura pronta': 'bg-cyan-50 text-cyan-700 ring-1 ring-cyan-100',
    'Pesquisa técnica': 'bg-slate-50 text-slate-600 ring-1 ring-slate-100',
}[status] ?? 'bg-slate-50 text-slate-600 ring-1 ring-slate-100');

const columnShellClass = (accent) => ({
    emerald: 'border-emerald-100 bg-emerald-50/60',
    purple: 'border-purple-100 bg-purple-50/60',
    pink: 'border-pink-100 bg-pink-50/60',
    slate: 'border-slate-200 bg-slate-50/80',
}[accent] ?? 'border-slate-200 bg-slate-50/80');

const columnBadgeClass = (accent) => ({
    emerald: 'bg-emerald-600 text-white',
    purple: 'bg-purple-600 text-white',
    pink: 'bg-pink-500 text-white',
    slate: 'bg-slate-900 text-white',
}[accent] ?? 'bg-slate-900 text-white');
</script>

<template>
    <Head title="Radar de Mercado" />

    <AdminLayout>
        <section class="overflow-hidden rounded-3xl bg-slate-950 text-white shadow-2xl shadow-slate-300/40">
            <div class="relative px-6 py-8 sm:px-8 lg:px-10">
                <div class="absolute right-0 top-0 h-64 w-64 rounded-full bg-purple-400/20 blur-3xl"></div>
                <div class="absolute bottom-0 left-1/3 h-48 w-48 rounded-full bg-fuchsia-400/10 blur-3xl"></div>

                <div class="relative flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-3xl">
                        <span class="inline-flex items-center rounded-full bg-white/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.24em] text-purple-100 ring-1 ring-white/15">
                            Mercado externo · inteligência · decisão humana
                        </span>
                        <h1 class="mt-5 text-3xl font-black tracking-tight sm:text-4xl lg:text-5xl">
                            Radar de Mercado
                        </h1>
                        <p class="mt-4 max-w-2xl text-sm leading-6 text-slate-300 sm:text-base">
                            Primeiro a tela mostra a leitura geral do mercado. Depois separa as melhores hipóteses por canal para você decidir o que cotar, comprar e transformar em produto no KazaKora.
                        </p>
                    </div>

                    <div v-if="topProduct" class="rounded-2xl border border-white/10 bg-white/10 p-5 backdrop-blur">
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-300">Maior prioridade geral</p>
                        <p class="mt-2 max-w-md text-xl font-bold">{{ topProduct.productName }}</p>
                        <div class="mt-4 flex items-center gap-3">
                            <span class="text-4xl font-black text-emerald-300">{{ topProduct.opportunityScore }}</span>
                            <span class="rounded-full bg-purple-400/20 px-3 py-1 text-xs font-bold uppercase text-emerald-100">
                                {{ topProduct.recommendation }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-2xl bg-white p-5 shadow-lg shadow-slate-200/80">
                <p class="text-xs font-bold uppercase text-slate-400">Produtos de mercado</p>
                <p class="mt-2 text-3xl font-black text-slate-800">{{ summary.totalTracked ?? products.length }}</p>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-lg shadow-emerald-100/80">
                <p class="text-xs font-bold uppercase text-emerald-500">Prioridade cotação</p>
                <p class="mt-2 text-3xl font-black text-emerald-600">{{ summary.readyToQuote ?? 0 }}</p>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-lg shadow-amber-100/80">
                <p class="text-xs font-bold uppercase text-amber-500">Pesquisar fornecedor</p>
                <p class="mt-2 text-3xl font-black text-amber-600">{{ summary.supplierResearch ?? 0 }}</p>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-lg shadow-slate-200/80">
                <p class="text-xs font-bold uppercase text-slate-400">Monitorar</p>
                <p class="mt-2 text-3xl font-black text-slate-600">{{ summary.monitoring ?? 0 }}</p>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-lg shadow-cyan-100/80">
                <p class="text-xs font-bold uppercase text-cyan-500">Score médio</p>
                <p class="mt-2 text-3xl font-black text-cyan-600">{{ summary.averageScore ?? '—' }}</p>
            </div>
        </section>

        <section class="mt-6 grid gap-6 xl:grid-cols-[1.5fr_1fr]">
            <div class="rounded-2xl border border-emerald-100 bg-emerald-50/80 p-5 text-sm text-emerald-900 shadow-sm">
                <p class="font-black">Visão geral do mercado</p>
                <p class="mt-2 leading-6 text-emerald-800">{{ sourceNotice }}</p>
                <p class="mt-3 text-xs font-semibold leading-5 text-emerald-700">
                    As etiquetas abaixo mostram fontes ativas e camadas planejadas. O status real de cada coleta fica no painel ao lado.
                </p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <span v-for="source in dataSources" :key="source" class="rounded-full bg-white/80 px-3 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">
                        {{ source }}
                    </span>
                </div>
                <p class="mt-4 rounded-xl bg-white/80 px-4 py-3 text-xs font-semibold text-slate-600 ring-1 ring-emerald-100">
                    {{ scoreFormula }}
                </p>
            </div>

            <div class="rounded-2xl bg-white p-5 shadow-lg shadow-slate-200/70">
                <p class="text-sm font-black text-slate-800">Status das fontes</p>
                <div class="mt-4 space-y-3">
                    <div v-for="provider in providerStatus" :key="provider.name" class="rounded-xl border border-slate-100 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-bold text-slate-700">{{ provider.name }}</p>
                            <span class="rounded-full px-3 py-1 text-xs font-bold" :class="providerClass(provider.status)">{{ provider.status }}</span>
                        </div>
                        <p class="mt-2 text-xs leading-5 text-slate-500">{{ provider.detail }}</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="mt-6 rounded-3xl bg-white p-5 shadow-xl shadow-slate-200/70 sm:p-6">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.22em] text-emerald-500">Resposta prática para compra</p>
                    <h2 class="mt-2 text-xl font-black text-slate-800">Oportunidades por Marketplace</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-500">
                        Cada coluna responde o que faz mais sentido cotar para vender naquele canal. O Google Trends aparece dentro dos cards como sinal transversal, não como marketplace separado.
                        Para Shopee, TikTok Shop e Amazon, as listas são estimativas geradas a partir das fontes ativas e sinais de tendência até os crawlers dedicados entrarem.
                    </p>
                </div>
                <span class="inline-flex rounded-full bg-slate-900 px-4 py-2 text-xs font-black uppercase tracking-wide text-white">
                    Geral → canal → fornecedor → KazaKora
                </span>
            </div>

            <div class="mt-5 grid gap-4 xl:grid-cols-4">
                <article
                    v-for="column in opportunityColumns"
                    :key="column.name"
                    class="flex min-h-full flex-col rounded-3xl border p-4"
                    :class="columnShellClass(column.accent)"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-black text-slate-900">{{ column.name }}</h3>
                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ column.description }}</p>
                        </div>
                        <span class="shrink-0 rounded-full px-3 py-1 text-[10px] font-black uppercase" :class="columnBadgeClass(column.accent)">
                            {{ column.status }}
                        </span>
                    </div>
                    <p class="mt-3 rounded-2xl bg-white/80 p-3 text-xs font-semibold leading-5 text-slate-600 ring-1 ring-white/80">
                        {{ column.method }}
                    </p>

                    <div class="mt-4 space-y-3">
                        <div
                            v-for="(product, index) in column.items"
                            :key="`${column.name}-${product.productName}`"
                            class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-[11px] font-black uppercase tracking-[0.18em] text-slate-400">#{{ index + 1 }} para {{ column.name }}</p>
                                    <h4 class="mt-1 text-sm font-black leading-5 text-slate-800">{{ product.productName }}</h4>
                                    <p class="mt-1 text-xs font-semibold text-slate-400">{{ product.category }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-2xl font-black text-slate-900">{{ product.channelScore }}</p>
                                    <p class="text-[10px] font-bold uppercase text-slate-400">score canal</p>
                                </div>
                            </div>

                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-gradient-to-r" :class="scoreGradient(product.channelScore)" :style="{ width: `${product.channelScore}%` }"></div>
                            </div>

                            <div class="mt-3 space-y-2 text-xs leading-5 text-slate-600">
                                <p><strong>Sinal:</strong> {{ product.shortReason }}</p>
                                <p><strong>{{ product.trendSignalLabel }}</strong></p>
                                <p><strong>Concorrência:</strong> {{ product.competitionLevel }} · <strong>Risco:</strong> {{ product.riskLevel }}</p>
                                <p><strong>{{ product.buyCeilingLabel }}</strong></p>
                                <p><strong>Próximo passo:</strong> {{ product.nextStepLabel }}</p>
                            </div>

                            <div class="mt-3 flex flex-wrap gap-2">
                                <span class="rounded-full px-3 py-1 text-[11px] font-bold" :class="recommendationClass(product.recommendation)">
                                    {{ product.recommendation }}
                                </span>
                                <span class="rounded-full bg-slate-50 px-3 py-1 text-[11px] font-bold text-slate-500 ring-1 ring-slate-100">
                                    {{ product.confidenceLabel }}
                                </span>
                            </div>
                        </div>

                        <p v-if="column.items.length === 0" class="rounded-2xl bg-white/80 p-4 text-xs font-bold leading-5 text-slate-500 ring-1 ring-slate-100">
                            Nenhuma hipótese disponível com os dados atuais.
                        </p>
                    </div>
                </article>
            </div>
        </section>


        <section class="mt-6 overflow-hidden rounded-3xl bg-slate-950 text-white shadow-2xl shadow-slate-300/40">
            <div class="relative p-5 sm:p-6">
                <div class="absolute right-0 top-0 h-64 w-64 rounded-full bg-purple-400/20 blur-3xl"></div>
                <div class="absolute bottom-0 left-1/4 h-48 w-48 rounded-full bg-violet-400/10 blur-3xl"></div>

                <div class="relative flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.22em] text-emerald-300">Nova aba operacional</p>
                        <h2 class="mt-2 text-2xl font-black">Importação aérea · pequenos e leves</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-300">
                            Revalidação dos produtos do Radar pensando em lote pequeno por aéreo: produto compacto, giro rápido, menor capital parado e checagem antes de comprar. Não é ordem de compra; é fila para cotação com trava de custo, frete, imposto e risco.
                        </p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/10 p-4 text-sm backdrop-blur">
                        <p class="text-xs font-black uppercase tracking-wide text-slate-300">Regra de decisão</p>
                        <p class="mt-2 leading-6 text-white">Só avança se o custo final importado couber no teto e ainda sobrar margem líquida depois de taxa, ADS, frete e devolução provável.</p>
                    </div>
                </div>

                <div class="relative mt-5 grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                    <article
                        v-for="item in airImportOpportunities"
                        :key="`air-${item.airImportRank}-${item.productName}`"
                        class="rounded-3xl border border-white/10 bg-white p-4 text-slate-800 shadow-lg shadow-black/10"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-[11px] font-black uppercase tracking-[0.18em] text-slate-400">#{{ item.airImportRank }} · importação aérea</p>
                                <h3 class="mt-1 text-base font-black leading-6 text-slate-900">{{ item.productName }}</h3>
                                <p class="mt-1 text-xs font-semibold text-slate-400">{{ item.category }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-3xl font-black text-emerald-600">{{ item.airImportScore }}</p>
                                <p class="text-[10px] font-bold uppercase text-slate-400">score aéreo</p>
                            </div>
                        </div>

                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-gradient-to-r" :class="scoreGradient(item.airImportScore)" :style="{ width: `${item.airImportScore}%` }"></div>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="rounded-full px-3 py-1 text-[11px] font-bold" :class="airFitClass(item.airFitLabel)">
                                {{ item.airFitLabel }}
                            </span>
                            <span class="rounded-full bg-slate-50 px-3 py-1 text-[11px] font-bold text-slate-500 ring-1 ring-slate-100">
                                {{ item.estimatedWeightBand }}
                            </span>
                        </div>

                        <div class="mt-3 space-y-2 text-xs leading-5 text-slate-600">
                            <p><strong>Por que entrou:</strong> {{ item.airImportReason }}</p>
                            <p><strong>Teto:</strong> {{ item.targetBuyRule }}</p>
                            <p><strong>Canais:</strong> {{ item.channelPlan }}</p>
                            <p><strong>Sinal:</strong> {{ item.shortReason }}</p>
                        </div>

                        <div class="mt-3 rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-100">
                            <p class="text-xs font-black uppercase tracking-wide text-slate-400">Checklist antes de comprar</p>
                            <ul class="mt-2 space-y-1 text-xs leading-5 text-slate-600">
                                <li v-for="check in item.validationChecklist" :key="`${item.productName}-${check}`">• {{ check }}</li>
                            </ul>
                        </div>
                    </article>

                    <p v-if="airImportOpportunities.length === 0" class="rounded-2xl border border-white/10 bg-white/10 p-4 text-sm font-semibold leading-6 text-slate-200">
                        Nenhum produto passou no filtro mínimo de importação aérea com os dados atuais.
                    </p>
                </div>
            </div>
        </section>

        <section class="mt-6 rounded-3xl bg-white p-5 shadow-xl shadow-slate-200/70 sm:p-6">
            <div>
                <p class="text-xs font-black uppercase tracking-[0.22em] text-emerald-500">Crawler próprio externo</p>
                <h2 class="mt-2 text-xl font-black text-slate-800">Camadas de coleta do Radar de Mercado</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">
                    O fluxo é mercado externo → crawler → inteligência de oportunidade → decisão humana → KazaKora.
                    A loja não alimenta a análise; ela recebe apenas os produtos aprovados depois.
                </p>
            </div>

            <div class="mt-5 grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                <article v-for="layer in crawlerLayers" :key="layer.name" class="rounded-2xl border border-slate-100 bg-slate-50/80 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="text-base font-black text-slate-800">{{ layer.name }}</h3>
                        <span class="rounded-full bg-white px-3 py-1 text-[11px] font-black uppercase text-emerald-700 ring-1 ring-emerald-100">{{ layer.status }}</span>
                    </div>
                    <p class="mt-3 text-sm leading-6 text-slate-600">{{ layer.role }}</p>
                    <p class="mt-3 rounded-xl bg-white p-3 text-xs leading-5 text-slate-500 ring-1 ring-slate-100"><strong>Campos:</strong> {{ layer.fields }}</p>
                    <p class="mt-3 text-xs font-semibold leading-5 text-slate-500"><strong>Próximo passo:</strong> {{ layer.nextStep }}</p>
                </article>
            </div>
        </section>

        <section class="mt-6 rounded-3xl bg-white p-5 shadow-xl shadow-slate-200/70 sm:p-6">
            <div class="flex flex-col gap-4 border-b border-slate-100 pb-5 xl:flex-row xl:items-center xl:justify-between">
                <div>
                    <h2 class="text-xl font-black text-slate-800">Ranking geral de oportunidades</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Não usa estoque nem venda da KazaKora. É uma fila para cotação, validação de fornecedor e decisão humana.
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-xs font-bold uppercase text-slate-400">
                        Recomendação
                        <select v-model="selectedRecommendation" class="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm focus:border-emerald-400 focus:outline-none">
                            <option v-for="recommendation in recommendations" :key="recommendation" :value="recommendation">{{ recommendation }}</option>
                        </select>
                    </label>
                    <label class="text-xs font-bold uppercase text-slate-400">
                        Risco
                        <select v-model="selectedRisk" class="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm focus:border-emerald-400 focus:outline-none">
                            <option v-for="riskLevel in riskLevels" :key="riskLevel" :value="riskLevel">{{ riskLevel }}</option>
                        </select>
                    </label>
                    <label class="text-xs font-bold uppercase text-slate-400">
                        Marketplace
                        <select v-model="selectedMarketplace" class="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm focus:border-emerald-400 focus:outline-none">
                            <option v-for="marketplace in marketplaces" :key="marketplace" :value="marketplace">{{ marketplace }}</option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="mt-5 grid gap-4 xl:hidden">
                <article v-for="product in filteredProducts" :key="`${product.marketplaceRank}-${product.productName}`" class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-black uppercase tracking-[0.2em] text-slate-400">#{{ product.rank }} · {{ product.marketplace }}</p>
                            <h3 class="mt-1 text-lg font-black text-slate-800">{{ product.productName }}</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ product.category }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-black text-slate-900">{{ product.opportunityScore }}</p>
                            <span class="mt-1 inline-flex rounded-full px-3 py-1 text-xs font-bold" :class="recommendationClass(product.recommendation)">
                                {{ product.recommendation }}
                            </span>
                        </div>
                    </div>
                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-200">
                        <div class="h-full rounded-full bg-gradient-to-r" :class="scoreGradient(product.opportunityScore)" :style="{ width: `${product.opportunityScore}%` }"></div>
                    </div>
                    <p class="mt-4 text-sm leading-6 text-slate-600">{{ product.shortReason }}</p>
                </article>
            </div>

            <div class="mt-5 hidden overflow-x-auto xl:block">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400">
                            <th class="px-4 py-3">Rank</th>
                            <th class="px-4 py-3">Produto de mercado</th>
                            <th class="px-4 py-3">Score</th>
                            <th class="px-4 py-3">Sinais</th>
                            <th class="px-4 py-3">Concorrência</th>
                            <th class="px-4 py-3">Margem</th>
                            <th class="px-4 py-3">Risco</th>
                            <th class="px-4 py-3">Ação</th>
                            <th class="px-4 py-3">Motivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="product in filteredProducts" :key="`${product.marketplaceRank}-${product.productName}`" class="border-b border-slate-100 align-top last:border-0 hover:bg-slate-50/80">
                            <td class="px-4 py-4">
                                <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-slate-900 text-xs font-black text-white">#{{ product.rank }}</span>
                            </td>
                            <td class="px-4 py-4">
                                <p class="font-black text-slate-800">{{ product.productName }}</p>
                                <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ product.category }} · {{ product.marketplace }}</p>
                                <a :href="product.sourceUrl" target="_blank" rel="noopener" class="mt-2 inline-flex text-xs font-bold text-emerald-600 hover:text-emerald-700">
                                    Ver fonte pública
                                </a>
                            </td>
                            <td class="px-4 py-4">
                                <div class="flex items-center gap-3">
                                    <span class="text-2xl font-black text-slate-900">{{ product.opportunityScore }}</span>
                                    <div class="h-2 w-24 overflow-hidden rounded-full bg-slate-200">
                                        <div class="h-full rounded-full bg-gradient-to-r" :class="scoreGradient(product.opportunityScore)" :style="{ width: `${product.opportunityScore}%` }"></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <div class="space-y-1 text-xs text-slate-600">
                                    <p><strong>Rank na fonte:</strong> {{ product.marketplaceRank }}</p>
                                    <p><strong>Estabilidade:</strong> {{ product.stability90d }}/100</p>
                                    <p><strong>Google:</strong> {{ product.matchedTrend || 'sem match direto hoje' }}</p>
                                </div>
                            </td>
                            <td class="px-4 py-4 text-sm font-bold text-slate-700">{{ product.competitionLevel }}</td>
                            <td class="px-4 py-4 text-sm font-bold text-slate-700">{{ formatPercent(product.estimatedMargin) }}</td>
                            <td class="px-4 py-4">
                                <span class="rounded-full px-3 py-1 text-xs font-bold" :class="riskClass(product.riskLevel)">{{ product.riskLevel }}</span>
                            </td>
                            <td class="px-4 py-4">
                                <span class="rounded-full px-3 py-1 text-xs font-bold" :class="recommendationClass(product.recommendation)">{{ product.recommendation }}</span>
                            </td>
                            <td class="max-w-xs px-4 py-4 leading-6 text-slate-600">{{ product.shortReason }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-6 rounded-3xl bg-white p-5 shadow-lg shadow-slate-200/70 sm:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-xl font-black text-slate-800">Google Trends Brasil agora</h2>
                    <p class="mt-1 text-sm text-slate-500">Termos em alta capturados do RSS público. Eles reforçam ou enfraquecem as hipóteses dentro de cada marketplace.</p>
                </div>
                <div class="flex max-w-4xl flex-wrap gap-2">
                    <span v-for="trend in trendTerms" :key="trend.term" class="rounded-full bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700 ring-1 ring-slate-200">
                        {{ trend.term }} · {{ trend.traffic }}
                    </span>
                    <span v-if="trendTerms.length === 0" class="rounded-full bg-amber-50 px-3 py-2 text-xs font-bold text-amber-700 ring-1 ring-amber-100">
                        Google Trends indisponível no momento
                    </span>
                </div>
            </div>
        </section>
    </AdminLayout>
</template>
