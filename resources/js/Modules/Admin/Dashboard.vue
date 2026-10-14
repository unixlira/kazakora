<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { usePermissions } from '@/Shared/usePermissions';
import Swal from 'sweetalert2';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
    topProducts: {
        type: Array,
        default: () => [],
    },
    abcCurve: {
        type: Object,
        default: () => ({ total: 0, a: [], b: [] }),
    },
    revenueByChannel: {
        type: Array,
        default: () => [],
    },
    analytics: {
        type: Object,
        default: () => ({ kpis: {}, ritmo: { dia: 0, atual: [], anterior: [] }, mensal: [], categorias: [] }),
    },
    diagnosticos: {
        type: Object,
        default: () => ({ shopee: null, mercado_livre: null }),
    },
    visitantes: {
        type: Object,
        default: () => ({ mes: '', meses: [], mesAtual: true, dias: [], visitantesMes: 0, paginasMes: 0, visitantesHoje: 0, paginasHoje: 0, mediaDia: 0, melhorDia: null }),
    },
});

// Curva ABC (pedido do usuário 2026-10-06): A = produtos que somam até 80%
// do faturamento, B = os seguintes até 95%. Ver DashboardController::curvaAbc().
const abcCards = computed(() => [
    { key: 'a', description: 'Somam 80% do faturamento', icon: 'fas fa-star', color: 'var(--color-success)', items: props.abcCurve?.a ?? [] },
    { key: 'b', description: 'Os próximos 15% do faturamento', icon: 'fas fa-layer-group', color: 'var(--color-info)', items: props.abcCurve?.b ?? [] },
]);

const CHANNEL_LABELS = {
    loja: 'KazaKora',
    mercado_livre: 'Mercado Livre',
    shopee: 'Shopee',
    tiktok_shop: 'TikTok Shop',
    amazon: 'Amazon',
};

// Cor fixa por canal (nunca pela posição no ranking), na ordem da paleta
// categórica validada pra daltonismo nos dois modos (validate_palette.js,
// 2026-10-06, contra --surface claro e escuro). No claro, verde-água,
// amarelo e magenta ficam abaixo de 3:1 no fundo — por isso todo gráfico
// de canal leva legenda com o valor escrito ao lado da cor.
const CHANNEL_ORDER = ['tiktok_shop', 'shopee', 'mercado_livre', 'amazon', 'loja'];
const CHANNEL_COLORS = {
    light: { tiktok_shop: '#2a78d6', shopee: '#eb6834', mercado_livre: '#1baf7a', amazon: '#eda100', loja: '#e87ba4' },
    dark: { tiktok_shop: '#3987e5', shopee: '#d95926', mercado_livre: '#199e70', amazon: '#c98500', loja: '#d55181' },
};
const CHROME = {
    // Tema grafite (2026-10-11): neutros quentes e o laranja como destaque.
    // Escuro em preto chapado (#000), igual ao --surface do admin.
    // Destaque grafite no claro (2026-10-11): o laranja se confundia com a
    // Shopee nos gráficos "Este mês" e "Faturamento por categoria".
    light: { grid: '#e6e2e3', text: '#6b5f63', surface: '#ffffff', accent: '#2b2326', context: '#b3aaad' },
    dark: { grid: '#2a2a2a', text: '#b3a9ac', surface: '#000000', accent: '#ff8c4d', context: '#7a6f73' },
};

// O tema muda na hora (botão do layout põe/tira .dark no <html>) — os
// gráficos repintam junto.
const isDark = ref(false);
let themeObserver = null;
onMounted(() => {
    const html = document.documentElement;
    isDark.value = html.classList.contains('dark');
    themeObserver = new MutationObserver(() => { isDark.value = html.classList.contains('dark'); });
    themeObserver.observe(html, { attributes: true, attributeFilter: ['class'] });
});
onBeforeUnmount(() => themeObserver?.disconnect());

const chrome = computed(() => CHROME[isDark.value ? 'dark' : 'light']);
const channelColor = (channel) => CHANNEL_COLORS[isDark.value ? 'dark' : 'light'][channel] ?? chrome.value.context;
const channelName = (channel) => CHANNEL_LABELS[channel] ?? channel;
const sortChannels = (channels) => [...channels].sort((a, b) => (CHANNEL_ORDER.indexOf(a) + 99) % 99 - (CHANNEL_ORDER.indexOf(b) + 99) % 99);

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
const formatCompact = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', notation: 'compact', maximumFractionDigits: 1 }).format(value ?? 0);
const formatPercent = (value) => `${(value ?? 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`;
const formatMonth = (yearMonth) =>
    new Intl.DateTimeFormat('pt-BR', { month: 'short', year: '2-digit' }).format(new Date(`${yearMonth}-01T00:00:00`)).replace('.', '');

// ---- KPIs --------------------------------------------------------------
const kpis = computed(() => props.analytics?.kpis ?? {});
const delta = (agora, antes) => (antes > 0 ? Math.round(((agora - antes) / antes) * 1000) / 10 : null);

const kpiTiles = computed(() => [
    {
        label: 'Ticket médio',
        value: formatPrice(kpis.value.ticketMedio),
        delta: delta(kpis.value.ticketMedio, kpis.value.ticketMedioAnterior),
        hint: 'vs mesmos dias do mês passado',
        icon: 'fas fa-receipt',
        color: 'var(--primary-solido)',
    },
    {
        label: 'Projeção do mês',
        value: formatPrice(kpis.value.projecaoMes),
        delta: delta(kpis.value.projecaoMes, kpis.value.receitaMesAnterior),
        hint: 'no ritmo atual, vs mês passado fechado',
        icon: 'fas fa-bullseye',
        color: 'var(--color-info)',
    },
    {
        label: 'Itens por pedido',
        value: (kpis.value.itensPorPedido ?? 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 }),
        delta: delta(kpis.value.itensPorPedido, kpis.value.itensPorPedidoAnterior),
        hint: 'vs mesmos dias do mês passado',
        icon: 'fas fa-boxes-stacked',
        color: 'var(--color-secondary)',
    },
]);

// Nota do card "Faturamento do mês": mesma conta do selo do Ritmo do mês.
const crescimentoNota = computed(() => {
    const c = kpis.value.crescimento;
    if (c === null || c === undefined) return 'sem base no mês passado';
    return `${c > 0 ? '+' : ''}${formatPercent(c)} vs mesmos dias do mês passado`;
});

const tileStyle = (color) => ({
    borderTop: '3px solid var(--faixa-card)',
    background: `linear-gradient(135deg, color-mix(in oklab, ${color} 10%, var(--surface)) 0%, var(--surface) 70%)`,
});

// ---- Opções comuns dos gráficos ---------------------------------------
const baseOptions = computed(() => ({
    animation: { duration: 600 },
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: isDark.value ? '#f1eced' : '#2b2326',
            titleColor: isDark.value ? '#000000' : '#ffffff',
            bodyColor: isDark.value ? '#000000' : '#ffffff',
            padding: 10,
            cornerRadius: 8,
            boxPadding: 4,
        },
    },
}));

const axisStyle = computed(() => ({
    grid: { color: chrome.value.grid, drawTicks: false },
    border: { display: false },
    ticks: { color: chrome.value.text, padding: 6 },
}));

// ---- Faturamento por canal ------------------------------------------
// Faixa do topo (pedido 2026-10-11): todos os canais aparecem, inclusive a
// KazaKora sem venda — canal zerado também é informação.
const channelStrip = computed(() => {
    const total = props.revenueByChannel.reduce((sum, row) => sum + row.total, 0);
    const extras = props.revenueByChannel.map((row) => row.origin).filter((o) => !CHANNEL_ORDER.includes(o));

    return [...CHANNEL_ORDER, ...extras].map((origin) => {
        const valor = props.revenueByChannel.find((row) => row.origin === origin)?.total ?? 0;

        return { origin, total: valor, share: total > 0 ? (valor / total) * 100 : 0 };
    });
});

// ---- 2. Faturamento mensal por canal (colunas empilhadas) -------------
const currentMonth = new Date().toISOString().slice(0, 7);
const monthlyChannels = computed(() => sortChannels([...new Set((props.analytics?.mensal ?? []).flatMap((m) => Object.keys(m.canais)))])
    .filter((channel) => (props.analytics?.mensal ?? []).some((m) => (m.canais[channel] ?? 0) > 0)));

const monthlyChartData = computed(() => ({
    // O mês corrente está pela metade — sem o aviso, a última coluna parece queda.
    labels: (props.analytics?.mensal ?? []).map((m, i, todos) => (i === todos.length - 1 && m.mes === currentMonth ? `${formatMonth(m.mes)} (parcial)` : formatMonth(m.mes))),
    datasets: monthlyChannels.value.map((channel, index) => ({
        label: channelName(channel),
        data: (props.analytics?.mensal ?? []).map((m) => m.canais[channel] ?? 0),
        backgroundColor: channelColor(channel),
        // 2px na cor do fundo separa os segmentos empilhados.
        borderColor: chrome.value.surface,
        borderWidth: { top: 2 },
        borderSkipped: 'start',
        borderRadius: index === monthlyChannels.value.length - 1 ? { topLeft: 4, topRight: 4 } : 0,
        maxBarThickness: 24,
    })),
}));

const monthlyChartOptions = computed(() => ({
    ...baseOptions.value,
    interaction: { mode: 'index', intersect: false },
    scales: {
        x: { ...axisStyle.value, stacked: true, grid: { display: false } },
        y: { ...axisStyle.value, stacked: true, ticks: { ...axisStyle.value.ticks, callback: (v) => formatCompact(v) } },
    },
    plugins: {
        ...baseOptions.value.plugins,
        tooltip: {
            ...baseOptions.value.plugins.tooltip,
            callbacks: {
                label: (ctx) => ` ${ctx.dataset.label}: ${formatPrice(ctx.parsed.y)}`,
                footer: (items) => `Total: ${formatPrice(items.reduce((sum, item) => sum + item.parsed.y, 0))}`,
            },
        },
    },
}));

const monthlyTotals = computed(() => (props.analytics?.mensal ?? []).map((m) => ({
    mes: m.mes,
    total: Object.values(m.canais).reduce((sum, v) => sum + v, 0),
})));

// ---- 3. Ritmo do mês (acumulado diário, atual x anterior) -------------
const ritmo = computed(() => props.analytics?.ritmo ?? { dia: 0, atual: [], anterior: [] });

const paceChartData = computed(() => {
    const dias = Math.max(ritmo.value.anterior.length, ritmo.value.atual.length);

    return {
        labels: Array.from({ length: dias }, (_, i) => `${i + 1}`),
        datasets: [
            {
                label: 'Este mês',
                data: ritmo.value.atual,
                borderColor: chrome.value.accent,
                backgroundColor: `${chrome.value.accent}1a`,
                fill: true,
                borderWidth: 2,
                tension: 0.3,
                pointRadius: (ctx) => (ctx.dataIndex === ritmo.value.atual.length - 1 ? 5 : 0),
                pointBackgroundColor: chrome.value.accent,
                pointBorderColor: chrome.value.surface,
                pointBorderWidth: 2,
                pointHoverRadius: 6,
            },
            {
                label: 'Mês passado',
                data: ritmo.value.anterior,
                borderColor: chrome.value.context,
                borderWidth: 2,
                tension: 0.3,
                pointRadius: 0,
                pointHoverRadius: 5,
                fill: false,
            },
        ],
    };
});

const paceChartOptions = computed(() => ({
    ...baseOptions.value,
    interaction: { mode: 'index', intersect: false },
    scales: {
        x: { ...axisStyle.value, grid: { display: false }, ticks: { ...axisStyle.value.ticks, maxTicksLimit: 8 } },
        y: { ...axisStyle.value, ticks: { ...axisStyle.value.ticks, callback: (v) => formatCompact(v) } },
    },
    plugins: {
        ...baseOptions.value.plugins,
        tooltip: {
            ...baseOptions.value.plugins.tooltip,
            callbacks: {
                title: (items) => `Dia ${items[0].label}`,
                label: (ctx) => ` ${ctx.dataset.label}: ${formatPrice(ctx.parsed.y)}`,
            },
        },
    },
}));

const paceComparison = computed(() => {
    const hoje = ritmo.value.atual.at(-1) ?? 0;
    const antes = ritmo.value.anterior[ritmo.value.atual.length - 1] ?? 0;

    return { hoje, antes, delta: delta(hoje, antes) };
});

// ---- 4. Faturamento por categoria (barras horizontais) ----------------
const categories = computed(() => props.analytics?.categorias ?? []);
const categoriesTotal = computed(() => categories.value.reduce((sum, c) => sum + c.receita, 0));

const categoryChartData = computed(() => ({
    labels: categories.value.map((c) => c.nome),
    datasets: [{
        label: 'Faturamento',
        data: categories.value.map((c) => c.receita),
        backgroundColor: chrome.value.accent,
        borderRadius: 4,
        borderSkipped: 'start',
        maxBarThickness: 18,
    }],
}));

const categoryChartOptions = computed(() => ({
    ...baseOptions.value,
    indexAxis: 'y',
    scales: {
        x: { ...axisStyle.value, ticks: { ...axisStyle.value.ticks, callback: (v) => formatCompact(v), maxTicksLimit: 4, autoSkipPadding: 24, maxRotation: 0 } },
        y: { ...axisStyle.value, grid: { display: false }, ticks: { ...axisStyle.value.ticks, color: isDark.value ? '#f1eced' : '#1f1a1c' } },
    },
    plugins: {
        ...baseOptions.value.plugins,
        tooltip: {
            ...baseOptions.value.plugins.tooltip,
            callbacks: {
                label: (ctx) => ` ${formatPrice(ctx.parsed.x)} (${formatPercent((ctx.parsed.x / categoriesTotal.value) * 100)})`,
            },
        },
    },
}));

// ---- 5. Acessos ao site (pedido 2026-10-11) ---------------------------
// Ver DashboardController::visitantes(): sem equipe e sem robô.
// Card de acessos com fundo branco (pedido 2026-10-14): barras em verde-claro,
// hoje em verde forte (era amarelo), páginas vistas em laranja.
const visitColors = computed(() => ({ bar: '#a3d9b8', today: '#198754', line: '#f97316' }));
const mesVisitas = ref(props.visitantes?.mes ?? '');
const carregandoVisitas = ref(false);
const trocarMesVisitas = () => {
    router.get('/admin', { mes: mesVisitas.value }, {
        only: ['visitantes'],
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onStart: () => { carregandoVisitas.value = true; },
        onFinish: () => { carregandoVisitas.value = false; },
    });
};
const formatMonthLong = (yearMonth) => {
    const texto = new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric' }).format(new Date(`${yearMonth}-01T00:00:00`));
    return texto.charAt(0).toUpperCase() + texto.slice(1);
};
const formatDay = (date) => new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit' }).format(new Date(`${date}T00:00:00`));
const formatInt = (value) => (value ?? 0).toLocaleString('pt-BR');
const hojeIso = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Sao_Paulo' });

const visitTiles = computed(() => {
    const v = props.visitantes ?? {};
    const tiles = [];
    if (v.mesAtual) {
        tiles.push({ label: 'Visitantes hoje', value: formatInt(v.visitantesHoje), hint: `${formatInt(v.paginasHoje)} páginas vistas hoje`, icon: 'fas fa-user-clock', color: '#b45309' });
    }
    tiles.push(
        { label: v.mesAtual ? 'Visitantes no mês' : `Visitantes em ${formatMonthLong(v.mes)}`, value: formatInt(v.visitantesMes), hint: 'pessoas diferentes no mês', icon: 'fas fa-users', color: '#198754' },
        { label: 'Páginas vistas', value: formatInt(v.paginasMes), hint: v.visitantesMes ? `${(v.paginasMes / v.visitantesMes).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} páginas por visitante` : 'no mês', icon: 'fas fa-eye', color: '#2b2326' },
        { label: 'Média por dia', value: formatInt(Math.round(v.mediaDia ?? 0)), hint: v.melhorDia?.visitantes ? `melhor dia: ${formatDay(v.melhorDia.dia)} (${formatInt(v.melhorDia.visitantes)})` : 'visitantes por dia', icon: 'fas fa-chart-line', color: '#c2410c' },
    );
    return tiles;
});

const visitChartData = computed(() => {
    const dias = props.visitantes?.dias ?? [];
    const cores = visitColors.value;

    return {
        labels: dias.map((d) => d.dia.slice(8, 10)),
        datasets: [
            {
                type: 'bar',
                label: 'Visitantes',
                data: dias.map((d) => d.visitantes),
                backgroundColor: dias.map((d) => (d.dia === hojeIso ? cores.today : cores.bar)),
                borderRadius: 4,
                borderSkipped: 'start',
                maxBarThickness: 22,
                yAxisID: 'y',
                order: 2,
            },
            {
                type: 'line',
                label: 'Páginas vistas',
                data: dias.map((d) => d.paginas),
                borderColor: cores.line,
                backgroundColor: cores.line,
                borderWidth: 2,
                tension: 0.35,
                pointRadius: 0,
                pointHoverRadius: 5,
                yAxisID: 'y1',
                order: 1,
            },
        ],
    };
});

const visitChartOptions = computed(() => ({
    ...baseOptions.value,
    interaction: { mode: 'index', intersect: false },
    scales: {
        x: { grid: { display: false }, border: { display: false }, ticks: { color: '#6b7280', maxTicksLimit: 16 } },
        y: { beginAtZero: true, grid: { color: '#e5e7eb', drawTicks: false }, border: { display: false }, ticks: { color: '#6b7280', padding: 6, precision: 0 }, title: { display: true, text: 'Visitantes', color: '#6b7280' } },
        y1: { beginAtZero: true, position: 'right', grid: { display: false }, border: { display: false }, ticks: { color: '#6b7280', padding: 6, precision: 0 }, title: { display: true, text: 'Páginas', color: '#6b7280' } },
    },
    plugins: {
        ...baseOptions.value.plugins,
        tooltip: {
            ...baseOptions.value.plugins.tooltip,
            callbacks: {
                title: (items) => formatDay((props.visitantes?.dias ?? [])[items[0].dataIndex]?.dia ?? hojeIso),
                label: (ctx) => ` ${ctx.dataset.label}: ${formatInt(ctx.parsed.y)}`,
            },
        },
    },
}));

// ---- Cards de produto --------------------------------------------------
const topMax = computed(() => Math.max(1, ...props.topProducts.map((p) => p.quantity)));

// ---- Diagnóstico Cauê (Shopee) e Diego (Mercado Livre), pedido 2026-10-11 --
// Vem pronto do marketplace:diagnostico (a cada 3 h). Aqui só agrupa os
// alertas por produto e filtra pela prioridade.
const PRIORIDADES = {
    P0: { rotulo: 'Urgente', cor: '#dc2626', icone: 'fas fa-triangle-exclamation' },
    P1: { rotulo: 'Atenção', cor: '#d97706', icone: 'fas fa-circle-exclamation' },
    P2: { rotulo: 'Melhorar', cor: '#0d6efd', icone: 'fas fa-wand-magic-sparkles' },
    OK: { rotulo: 'Oportunidade', cor: '#16a34a', icone: 'fas fa-rocket' },
};
const ESPECIALISTAS = [
    { canal: 'shopee', nome: 'Cauê', canalNome: 'Shopee', fundo: 'linear-gradient(120deg, #c2410c 0%, #c2410c 50%, #ee4d2d 100%)', texto: '#ffffff', icone: 'fas fa-bag-shopping' },
    { canal: 'mercado_livre', nome: 'Diego', canalNome: 'Mercado Livre', fundo: 'linear-gradient(120deg, #ffe600 0%, #ffd000 100%)', texto: '#2d3277', icone: 'fas fa-handshake' },
];
// Filtro abre na primeira prioridade que tem alerta (Gerenciador,
// 2026-10-14): abrir em "Urgente" vazio parecia que não havia nada a fazer.
const filtroDiag = ref({ shopee: null, mercado_livre: null });
const mostrarTodosDiag = ref({ shopee: false, mercado_livre: false });

const gruposDiag = computed(() => Object.fromEntries(ESPECIALISTAS.map(({ canal }) => {
    const d = props.diagnosticos?.[canal];
    if (!d) return [canal, null];
    const contagem = { P0: 0, P1: 0, P2: 0, OK: 0 };
    const porProduto = new Map();
    for (const a of d.alertas ?? []) {
        contagem[a.prioridade] = (contagem[a.prioridade] ?? 0) + 1;
        const id = a.produto?.item_id;
        if (!porProduto.has(id)) porProduto.set(id, { produto: a.produto, alertas: [] });
        porProduto.get(id).alertas.push(a);
    }
    return [canal, { ...d, contagem, grupos: [...porProduto.values()] }];
})));

const diagPorCanal = computed(() => Object.fromEntries(ESPECIALISTAS.map(({ canal }) => {
    const d = gruposDiag.value[canal];
    if (!d) return [canal, null];
    const filtro = filtroDiag.value[canal] ?? Object.keys(PRIORIDADES).find((p) => d.contagem[p] > 0) ?? 'P0';
    const produtos = d.grupos.filter((g) => g.alertas.some((a) => a.prioridade === filtro));
    const outra = Object.keys(PRIORIDADES).find((p) => p !== filtro && d.contagem[p] > 0) ?? null;
    return [canal, { ...d, produtos, filtro, outra }];
})));
const diagnostico = (canal) => diagPorCanal.value[canal];

// Diagnóstico roda a cada 3 h; passou de 6 h sem atualizar, algo parou.
const HORAS_DIAG_PARADO = 6;
const horasDesde = (iso) => (iso ? (Date.now() - new Date(iso).getTime()) / 3600000 : 0);

// Botão de ação do Cauê (pedido 2026-10-14): manda a correção para a Shopee
// pela API e confere lendo de volta. Só admin; sempre confirma antes.
const { can } = usePermissions();
const ehAdmin = computed(() => usePage().props.auth?.user?.role === 'admin');
const ICONE_ACAO = { pausar_ads: 'fas fa-pause', meta_roas: 'fas fa-bullseye', preco: 'fas fa-tag' };
const aplicando = ref(null);
const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const acaoFeita = (canal, itemId, acao) => props.diagnosticos?.[canal]?.feitas?.[itemId]?.[acao] ?? null;

async function aplicarAcao(produto, a) {
    let valor = a.valor;
    let modelId = null;
    if (a.acao === 'preco') {
        let modelos = [];
        try {
            const r = await fetch(`/admin/diagnostico/shopee/${produto.item_id}/modelos`, { headers: { Accept: 'application/json' } });
            modelos = (await r.json()).modelos ?? [];
        } catch { /* sem variações conhecidas: segue pelo item */ }
        const opcoes = Object.fromEntries(modelos.map((m) => [m.model_id, `${m.nome} (hoje ${formatPrice(m.original)})`]));
        const passo1 = await Swal.fire({
            title: 'Novo preço na Shopee',
            html: `<p class="text-sm">${esc(produto.nome)}</p><p class="mt-2 text-sm">Sugestão do Cauê: <b>${formatPrice(a.valor)}</b> (margem de 15% antes do ADS).</p>`,
            input: 'number',
            inputValue: a.valor,
            inputAttributes: { step: '0.01', min: '1' },
            showCancelButton: true,
            confirmButtonText: modelos.length ? 'Escolher variação' : 'Aplicar preço',
            cancelButtonText: 'Cancelar',
            reverseButtons: true,
            inputValidator: (v) => (!v || Number(v) <= 0 ? 'Informe o preço.' : null),
        });
        if (!passo1.isConfirmed) return;
        valor = Number(passo1.value);
        if (modelos.length) {
            const passo2 = await Swal.fire({
                title: 'Qual variação?',
                text: `Preço ${formatPrice(valor)} só na variação escolhida.`,
                input: 'select',
                inputOptions: opcoes,
                showCancelButton: true,
                confirmButtonText: 'Aplicar preço',
                cancelButtonText: 'Cancelar',
                reverseButtons: true,
            });
            if (!passo2.isConfirmed) return;
            modelId = passo2.value;
        }
    } else {
        const texto = a.acao === 'pausar_ads'
            ? 'Pausa todas as campanhas de ADS em andamento deste produto na Shopee. Dá para religar depois no Seller Center.'
            : `Muda a meta de ROAS das campanhas em andamento deste produto para ${formatNum(a.valor)} (empate + 2).`;
        const ok = await Swal.fire({
            icon: 'question',
            title: a.rotulo,
            html: `<p class="text-sm">${esc(produto.nome)}</p><p class="mt-2 text-sm">${texto}</p>`,
            showCancelButton: true,
            confirmButtonText: 'Aplicar na Shopee',
            cancelButtonText: 'Cancelar',
            reverseButtons: true,
        });
        if (!ok.isConfirmed) return;
    }
    aplicando.value = `${produto.item_id}:${a.acao}`;
    router.post('/admin/diagnostico/shopee/acao', { item_id: produto.item_id, acao: a.acao, valor, model_id: modelId }, {
        preserveScroll: true,
        // Só o diagnóstico (e o aviso) voltam; o resto da tela não muda.
        only: ['diagnosticos', 'flash'],
        onFinish: () => { aplicando.value = null; },
    });
}

const formatNum = (v, casas = 1) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas }));
const quandoGerado = (iso) => {
    if (!iso) return '';
    const min = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    return min < 60 ? `há ${Math.max(1, min)} min` : `há ${Math.round(min / 60)} h`;
};
</script>

<template>
    <Head title="Gerenciador" />

    <AdminLayout>
        <!-- Gerenciador (era "Início", Lira 2026-10-14). Ordem pela
             revisão do Jonathan: números do dia e do mês, o que pede ação
             (Cauê e Diego), desempenho, gráficos, produtos e, por último,
             os acessos ao site. -->
        <header class="mb-4">
            <h1 class="text-xl font-bold">Gerenciador</h1>
            <p class="text-sm text-[var(--texto-suave)]">Como a loja está hoje e o que precisa de você. Valores do mês até agora, comparados com os mesmos dias do mês passado.</p>
        </header>

        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <component :is="can('pedidos.view') ? Link : 'div'" v-bind="can('pedidos.view') ? { href: '/admin/pedidos' } : {}" class="block">
                <CardStats stat-subtitle="Faturamento hoje" :stat-title="formatPrice(stats.revenueToday)"
                    :stat-note="`${stats.ordersToday} ${stats.ordersToday === 1 ? 'pedido' : 'pedidos'}`"
                    stat-icon-name="fas fa-money-bill-wave" variant="info" vivid compact />
            </component>
            <component :is="can('pedidos.view') ? Link : 'div'" v-bind="can('pedidos.view') ? { href: '/admin/pedidos' } : {}" class="block">
                <CardStats stat-subtitle="Faturamento do mês" :stat-title="formatPrice(stats.revenue)"
                    :stat-note="crescimentoNota"
                    stat-icon-name="fas fa-calendar-days" variant="primary" vivid compact />
            </component>
            <component :is="can('financeiro.view') ? Link : 'div'" v-bind="can('financeiro.view') ? { href: '/admin/dashboard-financeiro' } : {}" class="block">
                <CardStats stat-subtitle="Margem de contribuição do mês" :stat-title="formatPrice(stats.contributionMarginMonth)"
                    :stat-note="kpis.margemPercentual === null || kpis.margemPercentual === undefined ? '' : `${formatPercent(kpis.margemPercentual)} do faturamento`"
                    stat-icon-name="fas fa-wallet" :variant="stats.contributionMarginMonth >= 0 ? 'success' : 'error'" vivid compact />
            </component>
            <CardStats stat-subtitle="Faturamento desde a abertura" :stat-title="formatPrice(stats.revenueTotal)"
                stat-icon-name="fas fa-sack-dollar" variant="neutral" vivid compact />
        </div>

        <div class="mt-3 grid grid-cols-2 gap-3 sm:mt-4 sm:gap-4 xl:grid-cols-4">
            <CardStats stat-subtitle="Pedidos hoje" :stat-title="String(stats.ordersToday)"
                stat-icon-name="fas fa-cart-shopping" variant="primary" vivid compact />
            <CardStats stat-subtitle="Pedidos no mês" :stat-title="String(stats.ordersMonth)"
                stat-icon-name="fas fa-calendar-check" variant="secondary" vivid compact />
            <CardStats stat-subtitle="Produtos devolvidos no mês" :stat-title="String(stats.returnsMonth)"
                stat-icon-name="fas fa-rotate-left" :variant="stats.returnsMonth > 0 ? 'warning' : 'neutral'" vivid compact />
            <CardStats stat-subtitle="Pedidos desde a abertura" :stat-title="formatInt(stats.ordersCount)"
                stat-icon-name="fas fa-receipt" variant="neutral" vivid compact />
        </div>

        <h2 class="mt-8 mb-3 flex items-center gap-2 text-lg font-bold">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-sm text-white"><i class="fas fa-list-check"></i></span>
            O que ajustar agora
        </h2>
        <!-- Cauê (Shopee) e Diego (Mercado Livre): o que ajustar para vender
             mais, anúncio por anúncio (pedido 2026-10-11). -->
        <section v-for="esp in ESPECIALISTAS" :key="esp.canal" class="mt-4 overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" :style="{ background: esp.fundo, color: esp.texto }">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white/25 text-lg ring-1 ring-white/40"><i :class="esp.icone"></i></span>
                    <div>
                        <h2 class="text-lg font-bold leading-tight">{{ esp.nome }} · {{ esp.canalNome }}</h2>
                        <p class="text-xs font-semibold">Anúncios que pedem ação · últimos 30 dias<template v-if="diagnosticos?.[esp.canal]"> · atualizado {{ quandoGerado(diagnosticos[esp.canal].gerado_em) }}</template></p>
                    </div>
                </div>
            </div>

            <div v-if="!diagnostico(esp.canal)" class="p-6 text-sm text-[var(--texto-suave)]">
                O primeiro diagnóstico sai em até 3 horas.
            </div>
            <template v-else>
                <p v-if="horasDesde(diagnostico(esp.canal).gerado_em) > HORAS_DIAG_PARADO"
                    class="mx-4 mt-4 flex items-center gap-2 rounded-lg bg-lightwarning px-3 py-2 text-sm font-semibold text-warning">
                    <i class="fas fa-clock"></i>
                    Diagnóstico sem atualizar há {{ Math.round(horasDesde(diagnostico(esp.canal).gerado_em)) }} h. Os números abaixo podem estar velhos.
                </p>
                <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 xl:grid-cols-6">
                    <div class="rounded-lg border border-[var(--surface-border)] p-3">
                        <p class="text-xs text-[var(--texto-suave)]">Pedidos</p>
                        <p class="text-xl font-bold">{{ diagnostico(esp.canal).kpis.pedidos }}</p>
                    </div>
                    <div class="rounded-lg border border-[var(--surface-border)] p-3">
                        <p class="text-xs text-[var(--texto-suave)]">Faturamento</p>
                        <p class="text-xl font-bold">{{ formatPrice(diagnostico(esp.canal).kpis.faturamento) }}</p>
                    </div>
                    <div class="rounded-lg border border-[var(--surface-border)] p-3">
                        <p class="text-xs text-[var(--texto-suave)]">Gasto com ADS</p>
                        <p class="text-xl font-bold">{{ formatPrice(diagnostico(esp.canal).kpis.ads_gasto) }}</p>
                    </div>
                    <div class="rounded-lg border border-[var(--surface-border)] p-3">
                        <p class="text-xs text-[var(--texto-suave)]">ROAS do ADS</p>
                        <p class="text-xl font-bold">{{ formatNum(diagnostico(esp.canal).kpis.ads_roas) }}</p>
                    </div>
                    <div class="rounded-lg border border-[var(--surface-border)] p-3" title="ROAS mínimo para sobrar 15% de margem, na média do que a loja vende">
                        <p class="text-xs text-[var(--texto-suave)]">ROAS mínimo para 15% de margem</p>
                        <p class="text-xl font-bold" :class="(diagnostico(esp.canal).kpis.ads_roas ?? 0) >= (diagnostico(esp.canal).kpis.roas_meta_loja ?? 0) ? 'text-success' : 'text-error'">{{ formatNum(diagnostico(esp.canal).kpis.roas_meta_loja) }}</p>
                    </div>
                    <div class="rounded-lg border border-[var(--surface-border)] p-3">
                        <p class="text-xs text-[var(--texto-suave)]">{{ esp.canal === 'mercado_livre' ? 'Qualidade média (ML)' : 'Anúncios em ADS' }}</p>
                        <p class="text-xl font-bold">
                            <template v-if="esp.canal === 'mercado_livre'">{{ diagnostico(esp.canal).kpis.qualidade_media ?? '—' }}<span class="text-sm font-normal text-[var(--texto-suave)]">/100</span></template>
                            <template v-else>{{ diagnostico(esp.canal).kpis.em_ads }}<span class="text-sm font-normal text-[var(--texto-suave)]"> de {{ diagnostico(esp.canal).kpis.anuncios }}</span></template>
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 px-4">
                    <button v-for="(cfg, chave) in PRIORIDADES" :key="chave" type="button"
                        class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-semibold transition"
                        :style="diagnostico(esp.canal).filtro === chave ? { background: cfg.cor, borderColor: cfg.cor, color: '#fff' } : { borderColor: cfg.cor, color: cfg.cor }"
                        @click="filtroDiag[esp.canal] = chave; mostrarTodosDiag[esp.canal] = false">
                        <i :class="cfg.icone" class="text-xs"></i> {{ cfg.rotulo }}
                        <span class="rounded-full bg-black/10 px-2 text-xs">{{ diagnostico(esp.canal).contagem[chave] }}</span>
                    </button>
                </div>

                <div class="p-4">
                    <p v-if="!diagnostico(esp.canal).produtos.length" class="text-sm text-[var(--texto-suave)]">
                        Nenhum anúncio em {{ PRIORIDADES[diagnostico(esp.canal).filtro]?.rotulo }} agora.
                        <button v-if="diagnostico(esp.canal).outra" type="button" class="font-semibold text-primary hover:underline"
                            @click="filtroDiag[esp.canal] = diagnostico(esp.canal).outra">
                            Ver {{ PRIORIDADES[diagnostico(esp.canal).outra].rotulo }} ({{ diagnostico(esp.canal).contagem[diagnostico(esp.canal).outra] }})
                        </button>
                    </p>
                    <ul v-else class="divide-y divide-[var(--surface-border)]">
                        <li v-for="g in (mostrarTodosDiag[esp.canal] ? diagnostico(esp.canal).produtos : diagnostico(esp.canal).produtos.slice(0, 6))" :key="g.produto.item_id" class="flex gap-3 py-3">
                            <img v-if="g.produto.imagem" :src="g.produto.imagem" alt="" class="h-14 w-14 shrink-0 rounded-lg border border-[var(--surface-border)] object-cover" loading="lazy">
                            <span v-else class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg bg-[var(--surface-muted)] text-[var(--texto-suave)]"><i class="fas fa-image"></i></span>
                            <div class="min-w-0 flex-1">
                                <a :href="g.produto.link" target="_blank" rel="noopener" class="block truncate font-semibold hover:text-primary hover:underline">{{ g.produto.nome }}</a>
                                <p class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-[var(--texto-suave)]">
                                    <span>{{ formatPrice(g.produto.preco) }}</span>
                                    <span>{{ g.produto.vendas_30d }} {{ g.produto.vendas_30d === 1 ? 'vendido' : 'vendidos' }}</span>
                                    <span>margem {{ formatNum(g.produto.margem) }}%</span>
                                    <span v-if="g.produto.roas_empate">ROAS para empatar {{ formatNum(g.produto.roas_empate) }}</span>
                                    <span v-if="g.produto.roas_meta && g.produto.roas_meta <= 25">meta {{ formatNum(g.produto.roas_meta) }}</span>
                                    <span v-if="g.produto.qualidade">qualidade: {{ g.produto.qualidade }}</span>
                                </p>
                                <ul class="mt-1.5 space-y-1">
                                    <li v-for="(a, i) in g.alertas" :key="i" class="flex items-start gap-2 text-sm">
                                        <span class="mt-0.5 shrink-0 rounded px-1.5 text-[10px] font-bold text-white" :style="{ background: PRIORIDADES[a.prioridade]?.cor }">{{ PRIORIDADES[a.prioridade]?.rotulo }}</span>
                                        <span>{{ a.texto }}</span>
                                    </li>
                                </ul>
                                <div v-if="esp.canal === 'shopee' && ehAdmin && g.produto.acoes?.length" class="mt-2 flex flex-wrap gap-2">
                                    <template v-for="a in g.produto.acoes" :key="a.acao">
                                        <span v-if="acaoFeita(esp.canal, g.produto.item_id, a.acao)" class="inline-flex items-center gap-1.5 rounded-lg bg-success/10 px-2.5 py-1 text-xs font-semibold text-success">
                                            <i class="fas fa-check"></i> {{ a.rotulo }} aplicado
                                        </span>
                                        <button v-else type="button" :disabled="aplicando !== null"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-[#ee4d2d] px-2.5 py-1 text-xs font-semibold text-[#ee4d2d] transition hover:bg-[#ee4d2d] hover:text-white disabled:opacity-50"
                                            @click="aplicarAcao(g.produto, a)">
                                            <i :class="aplicando === `${g.produto.item_id}:${a.acao}` ? 'fas fa-spinner fa-spin' : ICONE_ACAO[a.acao]"></i> {{ a.rotulo }}
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </li>
                    </ul>
                    <button v-if="diagnostico(esp.canal).produtos.length > 6" type="button" class="mt-2 text-sm font-semibold text-primary hover:underline"
                        @click="mostrarTodosDiag[esp.canal] = !mostrarTodosDiag[esp.canal]">
                        {{ mostrarTodosDiag[esp.canal] ? 'Mostrar menos' : `Ver todos (${diagnostico(esp.canal).produtos.length})` }}
                    </button>
                </div>
            </template>
        </section>


        <!-- Indicadores do mês (pedido do usuário 2026-10-06): o mês até
             hoje sempre contra os MESMOS dias do mês passado. -->
        <h2 class="mt-8 mb-3 flex items-center gap-2 text-lg font-bold">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-sm text-white"><i class="fas fa-gauge-high"></i></span>
            Desempenho do mês
        </h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div v-for="tile in kpiTiles" :key="tile.label"
                class="flex min-w-0 flex-col rounded-xl border border-[var(--surface-border)] p-4 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md"
                :style="tileStyle(tile.color)">
                <div class="flex items-center justify-between gap-2">
                    <p class="truncate text-sm text-[var(--texto-suave)]">{{ tile.label }}</p>
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm text-white" :style="{ background: tile.color }">
                        <i :class="tile.icon"></i>
                    </span>
                </div>
                <p class="mt-1 truncate text-2xl font-bold">{{ tile.value }}</p>
                <p class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-[var(--texto-suave)]">
                    <span v-if="tile.delta !== null" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold"
                        :class="tile.delta >= 0 ? 'bg-lightsuccess text-success' : 'bg-lighterror text-error'">
                        <i :class="tile.delta >= 0 ? 'fas fa-arrow-up' : 'fas fa-arrow-down'" class="text-[10px]"></i>
                        {{ tile.delta > 0 ? '+' : '' }}{{ formatPercent(tile.delta) }}
                    </span>
                    <span>{{ tile.hint }}</span>
                </p>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-5">
            <!-- Ritmo do mês -->
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm xl:col-span-3">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h3 class="text-base font-semibold">Ritmo do mês</h3>
                        <p class="text-xs text-[var(--texto-suave)]">Faturamento acumulado dia a dia</p>
                    </div>
                    <span v-if="paceComparison.delta !== null" class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold"
                        :class="paceComparison.delta >= 0 ? 'bg-lightsuccess text-success' : 'bg-lighterror text-error'">
                        <i :class="paceComparison.delta >= 0 ? 'fas fa-arrow-up' : 'fas fa-arrow-down'" class="text-[10px]"></i>
                        {{ paceComparison.delta > 0 ? '+' : '' }}{{ formatPercent(paceComparison.delta) }} vs mês passado no dia {{ ritmo.dia }}
                    </span>
                </div>
                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4 rounded" :style="{ background: chrome.accent }"></span> Este mês · {{ formatPrice(paceComparison.hoje) }}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4 rounded" :style="{ background: chrome.context }"></span> Mês passado · {{ formatPrice(paceComparison.antes) }} no mesmo dia</span>
                </div>
                <ChartCanvas v-if="paceComparison.hoje > 0 || ritmo.anterior.some((v) => v > 0)" class="mt-2" type="line" :data="paceChartData" :options="paceChartOptions" />
                <p v-else class="mt-6 text-sm text-[var(--texto-suave)]">Ainda sem vendas este mês.</p>
            </section>
            <!-- Faturamento por categoria -->
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm xl:col-span-2">
                <h3 class="text-base font-semibold">Faturamento por categoria</h3>
                <p class="text-xs text-[var(--texto-suave)]">Últimos 90 dias</p>
                <ChartCanvas v-if="categories.length" class="mt-3" type="bar" :data="categoryChartData" :options="categoryChartOptions" />
                <p v-else class="mt-6 text-sm text-[var(--texto-suave)]">Sem vendas nos últimos 90 dias.</p>
            </section>
            <!-- Faturamento mês a mês por canal -->
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm xl:col-span-5">
                <h3 class="text-base font-semibold">Faturamento mês a mês por canal</h3>
                <p class="text-xs text-[var(--texto-suave)]">Últimos 12 meses · passe o mouse para ver cada canal</p>
                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    <span v-for="channel in monthlyChannels" :key="channel" class="inline-flex items-center gap-1.5">
                        <span class="h-2.5 w-2.5 rounded-sm" :style="{ background: channelColor(channel) }"></span>
                        {{ channelName(channel) }}
                    </span>
                </div>
                <ChartCanvas v-if="monthlyTotals.length" class="mt-2" type="bar" :data="monthlyChartData" :options="monthlyChartOptions" />
                <p v-else class="mt-6 text-sm text-[var(--texto-suave)]">Sem vendas nos últimos 12 meses.</p>
            </section>
        </div>

        <!-- Fundos em degradê (pedido 2026-10-11, mesmas cores dos cards
             "Adapta"): vinho/rosa aqui. Texto branco.
             Cards de canal sem faixa lateral (pedido 2026-10-11). -->
        <section class="card-degrade card-degrade--vinho mt-8 p-4 text-white">
            <div class="flex items-center gap-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white/20 text-sm ring-1 ring-white/30"><i class="fas fa-store"></i></span>
                <h3 class="text-base font-semibold">Faturamento por canal</h3>
                <span class="text-xs text-white/80">· mês até hoje</span>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                <div v-for="row in channelStrip" :key="row.origin"
                    class="min-w-0 rounded-lg bg-white/12 px-3 py-2.5 ring-1 ring-white/20">
                    <p class="flex items-center gap-1.5 truncate text-xs font-semibold text-white/90">
                        <span class="h-2 w-2 shrink-0 rounded-full" :style="{ background: channelColor(row.origin) }"></span>
                        {{ channelName(row.origin) }}
                    </p>
                    <p class="truncate text-lg font-bold" :class="row.total > 0 ? 'text-white' : 'text-white/70'">{{ formatPrice(row.total) }}</p>
                    <div class="mt-1 h-1 rounded-full bg-white/20">
                        <div class="h-1 rounded-full bg-white" :style="{ width: `${row.share}%` }"></div>
                    </div>
                    <p class="mt-1 text-[11px] text-white/80">{{ row.total > 0 ? `${formatPercent(row.share)} do mês` : 'sem vendas no mês' }}</p>
                </div>
            </div>
        </section>

        <!-- Produtos: cada card com a sua cor, ranking e a barra de peso. -->
        <div class="mt-8 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <section class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm transition duration-200 hover:shadow-md"
                style="border-top: 3px solid var(--faixa-card)">
                <div class="flex items-center gap-3 border-b border-[var(--surface-border)] px-4 py-4"
                    style="background: linear-gradient(135deg, color-mix(in oklab, var(--primary-solido) 10%, var(--surface)) 0%, var(--surface) 80%)">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-white" style="background: var(--primary-solido)"><i class="fas fa-fire"></i></span>
                    <div>
                        <h3 class="text-base font-semibold">Produtos mais vendidos</h3>
                        <p class="text-xs text-[var(--texto-suave)]">Unidades vendidas nos últimos 30 dias</p>
                    </div>
                </div>
                <div class="p-4">
                    <p v-if="topProducts.length === 0" class="text-sm text-[var(--texto-suave)]">Nenhuma venda nos últimos 30 dias.</p>
                    <ol v-else class="space-y-3 text-sm">
                        <li v-for="(product, index) in topProducts" :key="`${product.id}-${product.name}`">
                            <div class="flex items-center justify-between gap-3">
                                <span class="flex min-w-0 items-center gap-2">
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                                        :class="index < 3 ? 'text-white' : 'bg-lightprimary text-primary'"
                                        :style="index < 3 ? { background: 'var(--primary-solido)' } : {}">{{ index + 1 }}</span>
                                    <span class="min-w-0 truncate">
                                        <Link v-if="product.id" :href="`/admin/produtos/${product.id}/editar`" class="hover:text-primary hover:underline">{{ product.name }}</Link>
                                        <span v-else>{{ product.name }}</span>
                                        <span v-if="product.color" class="block text-xs font-semibold text-[var(--texto-suave)]">{{ product.color }}</span>
                                    </span>
                                </span>
                                <span class="shrink-0 text-right">
                                    <span class="block font-semibold">{{ product.quantity }} un.</span>
                                    <span class="block text-xs text-[var(--texto-suave)]">{{ formatPrice(product.revenue) }}</span>
                                </span>
                            </div>
                            <div class="mt-1.5 ml-8 h-1.5 rounded-full bg-lightprimary">
                                <div class="h-1.5 rounded-full" style="background: var(--primary-solido)" :style="{ width: `${(product.quantity / topMax) * 100}%` }"></div>
                            </div>
                        </li>
                    </ol>
                </div>
            </section>

            <section v-for="curva in abcCards" :key="curva.key"
                class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm transition duration-200 hover:shadow-md"
                style="border-top: 3px solid var(--faixa-card)">
                <div class="flex items-center gap-3 border-b border-[var(--surface-border)] px-4 py-4"
                    :style="{ background: `linear-gradient(135deg, color-mix(in oklab, ${curva.color} 10%, var(--surface)) 0%, var(--surface) 80%)` }">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-white" :style="{ background: curva.color }"><i :class="curva.icon"></i></span>
                    <div>
                        <h3 class="text-base font-semibold">Produtos curva {{ curva.key.toUpperCase() }}</h3>
                        <p class="text-xs text-[var(--texto-suave)]">{{ curva.description }} · últimos 90 dias</p>
                    </div>
                </div>
                <div class="p-4">
                    <p v-if="curva.items.length === 0" class="text-sm text-[var(--texto-suave)]">Nenhum produto nesta curva.</p>
                    <ul v-else class="space-y-3 text-sm">
                        <li v-for="product in curva.items.slice(0, 10)" :key="`${product.id}-${product.name}`">
                            <div class="flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate">
                                    <Link v-if="product.id" :href="`/admin/produtos/${product.id}/editar`" class="hover:text-primary hover:underline">{{ product.name }}</Link>
                                    <span v-else>{{ product.name }}</span>
                                    <span v-if="product.color" class="block text-xs font-semibold text-[var(--texto-suave)]">{{ product.color }}</span>
                                </span>
                                <span class="shrink-0 text-right">
                                    <span class="block font-semibold">{{ formatPrice(product.revenue) }}</span>
                                    <span class="block text-xs text-[var(--texto-suave)]">{{ formatPercent(product.share) }} do faturamento</span>
                                </span>
                            </div>
                            <div class="mt-1.5 h-1.5 rounded-full" :style="{ background: `color-mix(in oklab, ${curva.color} 15%, transparent)` }">
                                <div class="h-1.5 rounded-full" :style="{ width: `${(product.share / (curva.items[0]?.share || 1)) * 100}%`, background: curva.color }"></div>
                            </div>
                        </li>
                    </ul>
                    <p v-if="curva.items.length > 10" class="mt-3 text-xs text-[var(--texto-suave)]">+ {{ curva.items.length - 10 }} produto(s) nesta curva</p>
                </div>
            </section>
        </div>
        <!-- Acessos ao site (pedido 2026-10-11), no fim da tela desde
             2026-10-14: o filtro de mês vale só para este bloco. -->
        <section class="card-degrade card-acessos mt-8 overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 pt-4">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#198754] text-white"><i class="fas fa-globe"></i></span>
                    <h3 class="text-lg font-semibold">Acessos ao site kazakora.com</h3>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <i v-if="carregandoVisitas" class="fas fa-circle-notch fa-spin"></i>
                    <span class="text-[#6b7280]">Mês <span class="text-[#9ca3af]">(só dos acessos)</span></span>
                    <select v-model="mesVisitas" @change="trocarMesVisitas"
                        class="visitas-mes rounded-lg border-0 py-1.5 pl-3 pr-8 text-sm font-medium shadow-sm ring-1 ring-[#d1d5db] focus:ring-2 focus:ring-[#198754]/50">
                        <option v-for="m in visitantes.meses" :key="m" :value="m">{{ formatMonthLong(m) }}</option>
                    </select>
                </label>
            </div>
            <div class="grid grid-cols-2 gap-3 p-4 xl:grid-cols-4" :class="{ 'opacity-60': carregandoVisitas }">
                <div v-for="tile in visitTiles" :key="tile.label" class="min-w-0 rounded-lg bg-[#f8faf9] p-3 ring-1 ring-[#e5e7eb]">
                    <div class="flex items-center justify-between gap-2">
                        <p class="truncate text-xs font-semibold text-[#6b7280]">{{ tile.label }}</p>
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-sm shadow-sm" :style="{ color: tile.color }"><i :class="tile.icon"></i></span>
                    </div>
                    <p class="mt-1 truncate text-3xl font-bold">{{ tile.value }}</p>
                    <p class="truncate text-xs text-[#6b7280]">{{ tile.hint }}</p>
                </div>
            </div>
            <div class="px-4 pb-4" :class="{ 'opacity-60': carregandoVisitas }">
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-[#6b7280]">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" :style="{ background: visitColors.bar }"></span> Visitantes</span>
                    <span v-if="visitantes.mesAtual" class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" :style="{ background: visitColors.today }"></span> Hoje</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4 rounded" :style="{ background: visitColors.line }"></span> Páginas vistas</span>
                </div>
                <ChartCanvas v-if="visitantes.visitantesMes > 0" class="mt-2" type="bar" :data="visitChartData" :options="visitChartOptions" />
                <p v-else class="mt-4 text-sm text-[#6b7280]">Nenhum acesso de cliente neste mês.</p>
            </div>
        </section>

    </AdminLayout>
</template>

<style scoped>
/* Cards em degradê (cores enviadas pelo Lira, 2026-10-11). */
.card-degrade {
    border-radius: 16px;
    box-shadow: inset 0 8px 12px 0 rgba(255, 255, 255, 0.1), 0 4px 14px rgba(15, 23, 42, 0.12);
    }
.card-acessos {
    /* Fundo branco nos dois temas (pedido 2026-10-14). */
    background: #ffffff;
    color: #1f1a1c;
    border: 1px solid #e5e7eb;
}
.card-degrade--vinho {
    background: linear-gradient(87deg, #290c15 0%, #78233d 40%, #9b2743 74.52%, #db586e 100%);
}

/* Select do mês no card de acessos: fundo branco e texto escuro nos dois temas. */
.visitas-mes,
.visitas-mes option {
    background-color: #ffffff;
    color: #1f1a1c;
}
</style>
