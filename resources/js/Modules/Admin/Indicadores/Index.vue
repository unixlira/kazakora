<script setup>
/**
 * Indicadores (Lira, 2026-10-14): só cards de número e gráficos, nada de
 * tabela. O servidor manda os 3 períodos (PainelDeAlertas), a composição da
 * receita (ContributionMargin::porCanal) e a série diária de 60 dias
 * (SerieDiaria); período, canal e métrica trocam aqui na tela, na hora.
 * Clicar numa fatia do "Faturamento por canal" filtra a tela pelo canal.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    painel: { type: Object, required: true },
    paineis: { type: Object, required: true },
    composicao: { type: Object, required: true },
    serie: { type: Object, required: true },
    kpis: { type: Object, required: true },
});

// Cor fixa por canal, a mesma paleta validada do Gerenciador.
// FerraKora e Beauty Kora (2026-10-14): canal próprio, só aparecem com dado.
const CANAIS = ['tiktok_shop', 'shopee', 'mercado_livre', 'amazon', 'loja', 'ferrakora', 'beautykora'];
const NOMES = { loja: 'KazaKora', mercado_livre: 'Mercado Livre', shopee: 'Shopee', tiktok_shop: 'TikTok Shop', amazon: 'Amazon', ferrakora: 'FerraKora', beautykora: 'Beauty Kora' };
const CORES = {
    light: { tiktok_shop: '#2a78d6', shopee: '#eb6834', mercado_livre: '#1baf7a', amazon: '#eda100', loja: '#e87ba4', ferrakora: '#F27A2A', beautykora: '#d63384' },
    dark: { tiktok_shop: '#3987e5', shopee: '#d95926', mercado_livre: '#199e70', amazon: '#c98500', loja: '#d55181', ferrakora: '#e8691a', beautykora: '#e05a9d' },
};
const CHROME = {
    light: { grid: '#dddcdb', text: '#5b6066', accent: '#3c4044', context: '#b3aaad', surface: '#ffffff' },
    dark: { grid: '#3c4044', text: '#a9adb2', accent: '#fd7b41', context: '#7a6f73', surface: '#2a2d30' },
};
const STATUS = { critico: '#dc2626', atencao: '#d97706', bom: '#198754' };

const isDark = ref(false);
let observer = null;
onMounted(() => {
    const html = document.documentElement;
    isDark.value = html.classList.contains('dark');
    observer = new MutationObserver(() => { isDark.value = html.classList.contains('dark'); });
    observer.observe(html, { attributes: true, attributeFilter: ['class'] });
});
onBeforeUnmount(() => observer?.disconnect());

const chrome = computed(() => CHROME[isDark.value ? 'dark' : 'light']);
const cor = (canal) => CORES[isDark.value ? 'dark' : 'light'][canal] ?? chrome.value.context;

// ---- Formatos ----------------------------------------------------------
const moeda = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);
const moedaCurta = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', notation: 'compact', maximumFractionDigits: 1 }).format(v ?? 0);
const inteiro = (v) => new Intl.NumberFormat('pt-BR').format(Math.round(v ?? 0));
const um = (v) => (v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const pct = (v) => `${um(v)}%`;
const diaMes = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
const formatar = (formato, v) => {
    if (v === null || v === undefined) return '—';
    if (formato === 'moeda') return moeda(v);
    if (formato === 'pct') return pct(v);
    if (formato === 'vezes') return um(v);
    return inteiro(v);
};
const variacao = (agora, antes) => (agora === null || antes === null || !antes ? null : ((agora - antes) / Math.abs(antes)) * 100);

// ---- Filtros (tudo local, sem recarregar) ------------------------------
const dias = ref([7, 14, 30].includes(props.painel.dias) ? props.painel.dias : 7);
const selecionados = ref([]);

const trocarDias = (d) => {
    dias.value = d;
    const url = new URL(window.location.href);
    url.searchParams.set('dias', d);
    window.history.replaceState(window.history.state, '', url);
};

const P = computed(() => props.paineis[dias.value] ?? props.painel);
const disponiveis = computed(() => CANAIS.filter((c) => props.serie.canais?.[c] || P.value.canais.some((x) => x.canal === c)));
const ativos = computed(() => (selecionados.value.length ? selecionados.value : disponiveis.value));
const todos = computed(() => !selecionados.value.length);
const alternarCanal = (c) => {
    selecionados.value = selecionados.value.includes(c) ? selecionados.value.filter((x) => x !== c) : [...selecionados.value, c];
    if (selecionados.value.length === disponiveis.value.length) selecionados.value = [];
};
const soCanal = (c) => { selecionados.value = selecionados.value.length === 1 && selecionados.value[0] === c ? [] : [c]; };

// ---- Série diária ------------------------------------------------------
const N = computed(() => props.serie.dias.length);
const janela = (anterior = false) => {
    const fim = N.value - (anterior ? dias.value : 0);
    return [Math.max(0, fim - dias.value), fim];
};
const fatia = (arr, anterior = false) => {
    const [a, b] = janela(anterior);
    return (arr ?? []).slice(a, b);
};
const somaArr = (arr) => arr.reduce((s, v) => s + (v ?? 0), 0);
const porDia = (campo, canais, anterior = false) => {
    const total = Array(dias.value).fill(0);
    canais.forEach((c) => fatia(props.serie.canais?.[c]?.[campo], anterior).forEach((v, i) => { total[i] += v ?? 0; }));
    return total;
};
const soma = (campo, anterior = false) => somaArr(porDia(campo, ativos.value, anterior));
const diasJanela = computed(() => fatia(props.serie.dias));

// ---- Indicadores do painel por canal -----------------------------------
const metrica = (canal, chave, painel = P.value) => painel.canais.find((x) => x.canal === canal)?.metricas.find((m) => m.chave === chave) ?? null;
const somaMetrica = (chave, campo) => ativos.value.reduce((s, c) => s + (metrica(c, chave)?.[campo] ?? 0), 0);

const alertas = computed(() => P.value.alertas.filter((a) => todos.value || a.canal === null || ativos.value.includes(a.canal)));

// ---- Cards -------------------------------------------------------------
const cards = computed(() => {
    const receita = soma('receita');
    const receitaAnt = soma('receita', true);
    const pedidos = soma('pedidos');
    const pedidosAnt = soma('pedidos', true);
    const ticket = pedidos ? receita / pedidos : null;
    const ticketAnt = pedidosAnt ? receitaAnt / pedidosAnt : null;
    const margem = somaMetrica('margem', 'valor');
    const margemAnt = somaMetrica('margem', 'anterior');
    const gasto = soma('ads_gasto');
    const gastoAnt = soma('ads_gasto', true);
    const roas = gasto ? soma('ads_venda') / gasto : null;
    const roasAnt = gastoAnt ? soma('ads_venda', true) / gastoAnt : null;
    const todosPed = soma('todos');
    const todosAnt = soma('todos', true);
    const cancel = todosPed ? (soma('cancelados') / todosPed) * 100 : null;
    const cancelAnt = todosAnt ? (soma('cancelados', true) / todosAnt) * 100 : null;
    const visit = metrica('loja', 'visitantes');
    const comSite = ativos.value.includes('loja');
    const criticos = alertas.value.filter((a) => a.nivel === 'critico').length;
    const atencao = alertas.value.length - criticos;

    return [
        { rotulo: 'Faturamento', valor: moeda(receita), var: variacao(receita, receitaAnt), bom: 'alto', icone: 'fas fa-sack-dollar', cor: '#2a78d6' },
        { rotulo: 'Pedidos', valor: inteiro(pedidos), var: variacao(pedidos, pedidosAnt), bom: 'alto', icone: 'fas fa-cart-shopping', cor: '#7c3aed' },
        { rotulo: 'Ticket médio', valor: ticket === null ? '—' : moeda(ticket), var: variacao(ticket, ticketAnt), bom: 'alto', icone: 'fas fa-receipt', cor: '#0891b2' },
        { rotulo: 'Margem de contribuição', valor: moeda(margem), nota: receita ? `${pct((margem / receita) * 100)} da venda` : null, var: variacao(margem, margemAnt), bom: 'alto', icone: 'fas fa-chart-line', cor: margem < 0 ? STATUS.critico : STATUS.bom },
        { rotulo: 'Investimento em ADS', valor: moeda(gasto), var: variacao(gasto, gastoAnt), bom: 'neutro', icone: 'fas fa-bullhorn', cor: '#9a3412' },
        { rotulo: 'ROAS', valor: roas === null ? '—' : um(roas), nota: roas ? `R$ ${um(roas)} vendidos por R$ 1` : null, var: variacao(roas, roasAnt), bom: 'alto', icone: 'fas fa-bullseye', cor: '#eb6834' },
        { rotulo: 'Cancelamento', valor: cancel === null ? '—' : pct(cancel), var: variacao(cancel, cancelAnt), bom: 'baixo', icone: 'fas fa-ban', cor: '#b91c1c' },
        { rotulo: 'Visitantes do site', valor: comSite && visit ? inteiro(visit.valor) : '—', nota: comSite ? null : 'selecione KazaKora', var: comSite && visit ? visit.variacao : null, bom: 'alto', icone: 'fas fa-users', cor: '#e87ba4' },
        { rotulo: 'Alertas críticos', valor: inteiro(criticos), icone: 'fas fa-circle-exclamation', cor: STATUS.critico },
        { rotulo: 'Alertas de atenção', valor: inteiro(atencao), icone: 'fas fa-triangle-exclamation', cor: STATUS.atencao },
    ];
});

const seta = (c) => {
    if (c.var === null || c.var === undefined) return null;
    const subiu = c.var > 0;
    const bom = c.bom === 'neutro' || c.var === 0 ? null : (c.bom === 'alto') === subiu;
    return {
        texto: `${subiu ? '+' : ''}${um(c.var)}%`,
        icone: c.var === 0 ? 'fas fa-arrow-right' : subiu ? 'fas fa-arrow-trend-up' : 'fas fa-arrow-trend-down',
        classe: bom === null ? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' : bom ? 'bg-lightsuccess text-success' : 'bg-lighterror text-error',
    };
};

// ---- Opções comuns -----------------------------------------------------
const tooltip = computed(() => ({
    backgroundColor: isDark.value ? '#f1eced' : '#3c4044',
    titleColor: isDark.value ? '#000000' : '#ffffff',
    bodyColor: isDark.value ? '#000000' : '#ffffff',
    padding: 10,
    cornerRadius: 8,
    boxPadding: 4,
}));
const eixo = computed(() => ({
    grid: { color: chrome.value.grid, drawTicks: false },
    border: { display: false },
    ticks: { color: chrome.value.text, padding: 6 },
}));
const base = (extra = {}) => ({
    animation: { duration: 450 },
    interaction: { mode: 'index', intersect: false },
    plugins: { legend: { display: false }, tooltip: tooltip.value, ...(extra.plugins ?? {}) },
    ...extra,
});

// ---- Crescimento dia a dia ---------------------------------------------
const METRICAS_DIA = {
    receita: { rotulo: 'Faturamento', fmt: moeda, curto: moedaCurta },
    pedidos: { rotulo: 'Pedidos', fmt: inteiro, curto: inteiro },
    ads_gasto: { rotulo: 'ADS', fmt: moeda, curto: moedaCurta },
    visitantes: { rotulo: 'Visitantes do site', fmt: inteiro, curto: inteiro },
};
const metricaDia = ref('receita');
const modoDia = ref('total');
const porCanalPossivel = computed(() => metricaDia.value !== 'visitantes');

const crescimento = computed(() => {
    const m = metricaDia.value;
    const labels = diasJanela.value.map(diaMes);
    if (modoDia.value === 'canal' && porCanalPossivel.value) {
        return {
            labels,
            datasets: ativos.value.map((c) => ({
                label: NOMES[c],
                data: porDia(m, [c]),
                backgroundColor: cor(c),
                borderColor: chrome.value.surface,
                borderWidth: { top: 2 },
                borderRadius: 4,
                stack: 'dia',
                maxBarThickness: 28,
            })),
        };
    }
    const atual = m === 'visitantes' ? fatia(props.serie.visitantes) : porDia(m, ativos.value);
    const antes = m === 'visitantes' ? fatia(props.serie.visitantes, true) : porDia(m, ativos.value, true);
    return {
        labels,
        datasets: [
            { label: 'Período', data: atual, borderColor: chrome.value.accent, backgroundColor: `${chrome.value.accent}22`, fill: true, tension: 0.35, borderWidth: 2, pointRadius: 0, pointHoverRadius: 5 },
            { label: 'Período anterior', data: antes, borderColor: chrome.value.context, borderDash: [5, 4], fill: false, tension: 0.35, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4 },
        ],
    };
});
const crescimentoOpcoes = computed(() => {
    const m = METRICAS_DIA[metricaDia.value];
    const empilhado = modoDia.value === 'canal' && porCanalPossivel.value;
    return base({
        scales: {
            x: { ...eixo.value, stacked: empilhado, grid: { display: false }, ticks: { ...eixo.value.ticks, maxTicksLimit: 10 } },
            y: { ...eixo.value, stacked: empilhado, beginAtZero: true, ticks: { ...eixo.value.ticks, callback: (v) => m.curto(v) } },
        },
        plugins: { tooltip: { ...tooltip.value, callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${m.fmt(ctx.parsed.y)}` } } },
    });
});
const totalCrescimento = computed(() => {
    const m = metricaDia.value;
    const atual = somaArr(m === 'visitantes' ? fatia(props.serie.visitantes) : porDia(m, ativos.value));
    const antes = somaArr(m === 'visitantes' ? fatia(props.serie.visitantes, true) : porDia(m, ativos.value, true));
    return { atual: METRICAS_DIA[m].fmt(atual), var: variacao(atual, antes) };
});

// ---- Faturamento por canal (rosca; clique filtra) ----------------------
const fatiasCanal = computed(() => disponiveis.value
    .map((c) => ({ canal: c, valor: somaArr(porDia('receita', [c])) }))
    .filter((x) => x.valor > 0));
const totalFatias = computed(() => fatiasCanal.value.reduce((s, x) => s + x.valor, 0));
const roscaCanal = computed(() => ({
    labels: fatiasCanal.value.map((x) => NOMES[x.canal]),
    datasets: [{
        data: fatiasCanal.value.map((x) => x.valor),
        backgroundColor: fatiasCanal.value.map((x) => (todos.value || ativos.value.includes(x.canal) ? cor(x.canal) : `${cor(x.canal)}40`)),
        borderColor: chrome.value.surface,
        borderWidth: 2,
        hoverOffset: 6,
    }],
}));
const roscaCanalOpcoes = computed(() => ({
    animation: { duration: 450 },
    cutout: '68%',
    onClick: (_e, el) => { if (el.length) soCanal(fatiasCanal.value[el[0].index].canal); },
    onHover: (e, el) => { e.native.target.style.cursor = el.length ? 'pointer' : 'default'; },
    plugins: {
        legend: { display: false },
        tooltip: { ...tooltip.value, callbacks: { label: (ctx) => ` ${moeda(ctx.parsed)} (${pct((ctx.parsed / totalFatias.value) * 100)})` } },
    },
}));

// ---- Para onde vai o dinheiro (composição, conta oficial) --------------
const comp = computed(() => {
    const linhas = props.composicao[dias.value] ?? {};
    const s = { receita: 0, custo_produtos: 0, taxas: 0, frete: 0, ads: 0, bonificacoes: 0, margem: 0 };
    ativos.value.forEach((c) => Object.keys(s).forEach((k) => { s[k] += linhas[c]?.[k] ?? 0; }));
    return s;
});
const partesComp = computed(() => [
    { rotulo: 'Custo dos produtos', valor: comp.value.custo_produtos, cor: isDark.value ? '#8a7f83' : '#3c4044' },
    { rotulo: 'Taxas dos canais', valor: comp.value.taxas, cor: isDark.value ? '#a99fa2' : '#6b5f63' },
    { rotulo: 'Frete pago pela loja', valor: comp.value.frete, cor: isDark.value ? '#c9c1c3' : '#9a8f92' },
    { rotulo: 'ADS', valor: comp.value.ads, cor: isDark.value ? '#e3dcde' : '#c9c1c3' },
    { rotulo: 'Margem', valor: Math.max(0, comp.value.margem), cor: STATUS.bom },
].filter((p) => p.valor > 0));
const roscaComp = computed(() => ({
    labels: partesComp.value.map((p) => p.rotulo),
    datasets: [{ data: partesComp.value.map((p) => p.valor), backgroundColor: partesComp.value.map((p) => p.cor), borderColor: chrome.value.surface, borderWidth: 2, hoverOffset: 6 }],
}));
const roscaCompOpcoes = computed(() => ({
    animation: { duration: 450 },
    cutout: '68%',
    plugins: {
        legend: { display: false },
        tooltip: { ...tooltip.value, callbacks: { label: (ctx) => ` ${moeda(ctx.parsed)} (${comp.value.receita ? pct((ctx.parsed / comp.value.receita) * 100) : '—'} da receita)` } },
    },
}));

// ---- Comparar canais (substitui a tabela por canal) --------------------
const METRICAS_CANAL = [
    ['faturamento', 'Faturamento'], ['pedidos', 'Pedidos'], ['ticket', 'Ticket médio'], ['margem', 'Margem'], ['margem_pct', 'Margem %'],
    ['cancelamento', 'Cancelamento'], ['devolucao', 'Devolução'], ['roas', 'ROAS'], ['acos', 'ACOS'], ['tacos', 'TACOS'],
    ['ctr', 'CTR'], ['cpc', 'CPC'], ['cpa', 'CPA'], ['conversao_ads', 'Conversão ADS'], ['conversao_site', 'Conversão do site'],
];
const metricaCanal = ref('faturamento');
const opcoesCanal = computed(() => METRICAS_CANAL.filter(([k]) => P.value.canais.some((c) => CANAIS.includes(c.canal) && c.metricas.some((m) => m.chave === k))));
const linhasCanal = computed(() => ativos.value
    .map((c) => ({ canal: c, m: metrica(c, metricaCanal.value) }))
    .filter((x) => x.m && (x.m.valor !== null || x.m.anterior !== null)));
const barrasCanal = computed(() => ({
    labels: linhasCanal.value.map((x) => NOMES[x.canal]),
    datasets: [
        {
            label: 'Período',
            data: linhasCanal.value.map((x) => x.m.valor),
            backgroundColor: linhasCanal.value.map((x) => cor(x.canal)),
            borderColor: linhasCanal.value.map((x) => (x.m.nivel ? STATUS[x.m.nivel] : chrome.value.surface)),
            borderWidth: linhasCanal.value.map((x) => (x.m.nivel ? 3 : 0)),
            borderRadius: 4,
            maxBarThickness: 44,
        },
        { label: 'Período anterior', data: linhasCanal.value.map((x) => x.m.anterior), backgroundColor: chrome.value.context, borderRadius: 4, maxBarThickness: 44 },
    ],
}));
const barrasCanalOpcoes = computed(() => {
    const fmt = linhasCanal.value[0]?.m.formato ?? 'numero';
    return base({
        scales: {
            x: { ...eixo.value, grid: { display: false } },
            y: { ...eixo.value, beginAtZero: true, ticks: { ...eixo.value.ticks, callback: (v) => (fmt === 'moeda' ? moedaCurta(v) : formatar(fmt, v)) } },
        },
        plugins: {
            tooltip: {
                ...tooltip.value,
                callbacks: {
                    label: (ctx) => ` ${ctx.dataset.label}: ${formatar(fmt, ctx.parsed.y)}`,
                    afterBody: (itens) => {
                        const x = linhasCanal.value[itens[0].dataIndex];
                        const extra = [];
                        if (x.m.variacao !== null) extra.push(`Variação: ${x.m.variacao > 0 ? '+' : ''}${um(x.m.variacao)}%`);
                        if (x.m.referencia) extra.push(`Referência: ${x.m.referencia}`);
                        if (x.m.nivel) extra.push(x.m.nivel === 'critico' ? '⚠ Crítico' : '⚠ Atenção');
                        return extra;
                    },
                },
            },
        },
    });
});

// ---- Margem por canal --------------------------------------------------
const margemCanal = computed(() => ativos.value
    .map((c) => ({ canal: c, valor: metrica(c, 'margem')?.valor ?? null, pct: metrica(c, 'margem_pct')?.valor ?? null }))
    .filter((x) => x.valor !== null));
const barrasMargem = computed(() => ({
    labels: margemCanal.value.map((x) => NOMES[x.canal]),
    datasets: [{
        label: 'Margem',
        data: margemCanal.value.map((x) => x.valor),
        backgroundColor: margemCanal.value.map((x) => (x.valor < 0 ? STATUS.critico : cor(x.canal))),
        borderRadius: 4,
        maxBarThickness: 26,
    }],
}));
const barrasMargemOpcoes = computed(() => base({
    indexAxis: 'y',
    scales: {
        x: { ...eixo.value, ticks: { ...eixo.value.ticks, callback: (v) => moedaCurta(v) } },
        y: { ...eixo.value, grid: { display: false } },
    },
    plugins: { tooltip: { ...tooltip.value, callbacks: { label: (ctx) => ` ${moeda(ctx.parsed.x)} (${pct(margemCanal.value[ctx.dataIndex].pct)} da venda)` } } },
}));

// ---- ADS: investimento x venda atribuída -------------------------------
const adsCanal = computed(() => ativos.value
    .map((c) => ({ canal: c, gasto: somaArr(porDia('ads_gasto', [c])), venda: somaArr(porDia('ads_venda', [c])) }))
    .filter((x) => x.gasto > 0 || x.venda > 0));
const barrasAds = computed(() => ({
    labels: adsCanal.value.map((x) => NOMES[x.canal]),
    datasets: [
        { label: 'Venda pelo ADS', data: adsCanal.value.map((x) => x.venda), backgroundColor: adsCanal.value.map((x) => cor(x.canal)), borderRadius: 4, maxBarThickness: 40 },
        { label: 'Investimento', data: adsCanal.value.map((x) => x.gasto), backgroundColor: chrome.value.context, borderRadius: 4, maxBarThickness: 40 },
    ],
}));
const barrasAdsOpcoes = computed(() => base({
    scales: {
        x: { ...eixo.value, grid: { display: false } },
        y: { ...eixo.value, beginAtZero: true, ticks: { ...eixo.value.ticks, callback: (v) => moedaCurta(v) } },
    },
    plugins: {
        tooltip: {
            ...tooltip.value,
            callbacks: {
                label: (ctx) => ` ${ctx.dataset.label}: ${moeda(ctx.parsed.y)}`,
                afterBody: (itens) => {
                    const x = adsCanal.value[itens[0].dataIndex];
                    return x.gasto ? [`ROAS: ${um(x.venda / x.gasto)}`] : [];
                },
            },
        },
    },
}));

// ---- Alertas por assunto -----------------------------------------------
const GRUPOS = { vendas: 'Vendas', lucro: 'Lucro', ads: 'ADS', site: 'Site', operacao: 'Operação', estoque: 'Estoque' };
const gruposAlerta = computed(() => Object.keys(GRUPOS)
    .map((g) => ({ g, lista: alertas.value.filter((a) => a.grupo === g) }))
    .filter((x) => x.lista.length));
const barrasAlertas = computed(() => ({
    labels: gruposAlerta.value.map((x) => GRUPOS[x.g]),
    datasets: [
        { label: 'Crítico', data: gruposAlerta.value.map((x) => x.lista.filter((a) => a.nivel === 'critico').length), backgroundColor: STATUS.critico, borderColor: chrome.value.surface, borderWidth: { right: 2 }, borderRadius: 4, stack: 'a', maxBarThickness: 26 },
        { label: 'Atenção', data: gruposAlerta.value.map((x) => x.lista.filter((a) => a.nivel === 'atencao').length), backgroundColor: STATUS.atencao, borderRadius: 4, stack: 'a', maxBarThickness: 26 },
    ],
}));
const barrasAlertasOpcoes = computed(() => base({
    indexAxis: 'y',
    scales: {
        x: { ...eixo.value, stacked: true, beginAtZero: true, ticks: { ...eixo.value.ticks, precision: 0 } },
        y: { ...eixo.value, stacked: true, grid: { display: false } },
    },
    plugins: {
        tooltip: {
            ...tooltip.value,
            callbacks: {
                // Mostra o que o alerta diz (a lista de antes cabe aqui).
                afterBody: (itens) => gruposAlerta.value[itens[0].dataIndex].lista.slice(0, 4).map((a) => `• ${a.indicador}: ${a.texto.length > 90 ? `${a.texto.slice(0, 88)}…` : a.texto}`),
            },
        },
    },
}));

// ---- Visitantes por site -----------------------------------------------
const sites = computed(() => P.value.canais
    .map((c) => ({ nome: c.canal === 'loja' ? 'KazaKora' : c.nome, m: c.metricas.find((m) => m.chave === 'visitantes') }))
    .filter((x) => x.m));
const barrasSites = computed(() => ({
    labels: sites.value.map((x) => x.nome),
    datasets: [
        { label: 'Período', data: sites.value.map((x) => x.m.valor), backgroundColor: '#198754', borderRadius: 4, maxBarThickness: 22 },
        { label: 'Período anterior', data: sites.value.map((x) => x.m.anterior), backgroundColor: chrome.value.context, borderRadius: 4, maxBarThickness: 22 },
    ],
}));
const barrasSitesOpcoes = computed(() => base({
    indexAxis: 'y',
    scales: {
        x: { ...eixo.value, beginAtZero: true, ticks: { ...eixo.value.ticks, precision: 0 } },
        y: { ...eixo.value, grid: { display: false } },
    },
}));

const periodo = computed(() => `${diaMes(P.value.desde)} a ${diaMes(P.value.ate)} contra ${diaMes(P.value.anterior_desde)} a ${diaMes(P.value.anterior_ate)}`);
const chip = (ativo) => (ativo ? 'bg-primary text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800');
</script>

<template>
    <Head title="Indicadores" />

    <AdminLayout>
        <!-- Cabeçalho e filtros -->
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">Indicadores</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Últimos {{ dias }} dias completos ({{ periodo }}).</p>
            </div>
            <div class="flex gap-1 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-1 shadow-sm" role="group" aria-label="Período">
                <button v-for="d in [7, 14, 30]" :key="d" type="button" class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                    :class="chip(dias === d)" :aria-pressed="dias === d" @click="trocarDias(d)">{{ d }} dias</button>
            </div>
        </div>

        <div class="mb-5 flex flex-wrap items-center gap-2" role="group" aria-label="Canais">
            <button type="button" class="rounded-full border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-1.5 text-sm font-medium shadow-sm transition"
                :class="todos ? 'ring-2 ring-primary' : 'opacity-70 hover:opacity-100'" :aria-pressed="todos" @click="selecionados = []">Todos os canais</button>
            <button v-for="c in disponiveis" :key="c" type="button"
                class="inline-flex items-center gap-2 rounded-full border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-1.5 text-sm font-medium shadow-sm transition"
                :class="!todos && selecionados.includes(c) ? 'ring-2' : todos ? '' : 'opacity-50 hover:opacity-100'"
                :style="!todos && selecionados.includes(c) ? { '--tw-ring-color': cor(c) } : {}"
                :aria-pressed="!todos && selecionados.includes(c)" @click="alternarCanal(c)">
                <span class="h-2.5 w-2.5 rounded-full" :style="{ background: cor(c) }" aria-hidden="true"></span>{{ NOMES[c] }}
            </button>
        </div>

        <!-- Cards -->
        <section class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" aria-label="Números do período">
            <div v-for="c in cards" :key="c.rotulo"
                class="relative min-w-0 overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md"
                :style="{ borderTop: `3px solid ${c.cor}` }">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <p class="truncate text-xs font-semibold text-slate-500 dark:text-slate-400">{{ c.rotulo }}</p>
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm text-white" :style="{ background: c.cor }"><i :class="c.icone" aria-hidden="true"></i></span>
                </div>
                <p class="truncate text-xl font-bold tabular-nums sm:text-2xl">{{ c.valor }}</p>
                <div class="mt-1 flex min-h-[1.25rem] flex-wrap items-center gap-1.5 text-xs">
                    <span v-if="seta(c)" class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 font-semibold tabular-nums" :class="seta(c).classe">
                        <i :class="seta(c).icone" aria-hidden="true"></i>{{ seta(c).texto }}
                    </span>
                    <span v-if="c.nota" class="truncate text-slate-500 dark:text-slate-400">{{ c.nota }}</span>
                </div>
            </div>
        </section>

        <!-- Crescimento + faturamento por canal -->
        <div class="mb-4 grid grid-cols-1 gap-4 xl:grid-cols-3">
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm xl:col-span-2" aria-labelledby="g-crescimento">
                <div class="mb-2 flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h2 id="g-crescimento" class="font-semibold">Crescimento dia a dia</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            <span class="font-semibold text-slate-800 dark:text-slate-100">{{ totalCrescimento.atual }}</span> no período
                            <span v-if="totalCrescimento.var !== null">({{ totalCrescimento.var > 0 ? '+' : '' }}{{ um(totalCrescimento.var) }}% sobre o anterior)</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <div class="flex gap-1 rounded-lg bg-slate-100 p-1 dark:bg-slate-800" role="group" aria-label="Métrica">
                            <button v-for="(m, k) in METRICAS_DIA" :key="k" type="button" class="rounded-md px-2.5 py-1 text-xs font-medium transition" :class="chip(metricaDia === k)" @click="metricaDia = k">{{ m.rotulo }}</button>
                        </div>
                        <div v-if="porCanalPossivel" class="flex gap-1 rounded-lg bg-slate-100 p-1 dark:bg-slate-800" role="group" aria-label="Visão">
                            <button type="button" class="rounded-md px-2.5 py-1 text-xs font-medium transition" :class="chip(modoDia === 'total')" @click="modoDia = 'total'">Total × anterior</button>
                            <button type="button" class="rounded-md px-2.5 py-1 text-xs font-medium transition" :class="chip(modoDia === 'canal')" @click="modoDia = 'canal'">Por canal</button>
                        </div>
                    </div>
                </div>
                <div class="mb-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                    <template v-if="modoDia === 'canal' && porCanalPossivel">
                        <span v-for="c in ativos" :key="c" class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" :style="{ background: cor(c) }"></span>{{ NOMES[c] }}</span>
                    </template>
                    <template v-else>
                        <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4" :style="{ background: chrome.accent }"></span>Período</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4 border-t-2 border-dashed" :style="{ borderColor: chrome.context }"></span>Período anterior</span>
                    </template>
                </div>
                <ChartCanvas :type="modoDia === 'canal' && porCanalPossivel ? 'bar' : 'line'" :data="crescimento" :options="crescimentoOpcoes" />
            </section>

            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="g-canal">
                <h2 id="g-canal" class="font-semibold">Faturamento por canal</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Clique numa fatia para ver só o canal.</p>
                <div v-if="fatiasCanal.length" class="relative">
                    <ChartCanvas type="doughnut" :data="roscaCanal" :options="roscaCanalOpcoes" />
                    <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                        <span class="text-xs text-slate-500 dark:text-slate-400">Total</span>
                        <span class="text-lg font-bold tabular-nums">{{ moedaCurta(totalFatias) }}</span>
                    </div>
                </div>
                <p v-else class="py-20 text-center text-sm text-slate-500">Sem vendas no período.</p>
                <ul class="mt-2 space-y-1 text-sm">
                    <li v-for="x in fatiasCanal" :key="x.canal" class="flex items-center justify-between gap-2">
                        <button type="button" class="inline-flex items-center gap-2 hover:underline" @click="soCanal(x.canal)">
                            <span class="h-2.5 w-2.5 rounded-full" :style="{ background: cor(x.canal) }"></span>{{ NOMES[x.canal] }}
                        </button>
                        <span class="tabular-nums text-slate-600 dark:text-slate-300">{{ moeda(x.valor) }} · {{ pct((x.valor / totalFatias) * 100) }}</span>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Comparar canais + composição -->
        <div class="mb-4 grid grid-cols-1 gap-4 xl:grid-cols-3">
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm xl:col-span-2" aria-labelledby="g-comparar">
                <h2 id="g-comparar" class="font-semibold">Comparar canais</h2>
                <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">Barra colorida é o período; cinza, o anterior. Contorno vermelho ou laranja = indicador em alerta. Passe o mouse para ver variação e referência.</p>
                <div class="mb-2 flex flex-wrap gap-1" role="group" aria-label="Indicador">
                    <button v-for="[k, r] in opcoesCanal" :key="k" type="button" class="rounded-full border border-[var(--surface-border)] px-2.5 py-1 text-xs font-medium transition" :class="chip(metricaCanal === k)" @click="metricaCanal = k">{{ r }}</button>
                </div>
                <ChartCanvas v-if="linhasCanal.length" type="bar" :data="barrasCanal" :options="barrasCanalOpcoes" />
                <p v-else class="py-20 text-center text-sm text-slate-500">Esse indicador não existe nos canais escolhidos.</p>
            </section>

            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="g-comp">
                <h2 id="g-comp" class="font-semibold">Para onde vai o que entra</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Conta oficial da margem de contribuição.</p>
                <div v-if="partesComp.length" class="relative">
                    <ChartCanvas type="doughnut" :data="roscaComp" :options="roscaCompOpcoes" />
                    <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                        <span class="text-xs text-slate-500 dark:text-slate-400">Margem</span>
                        <span class="text-lg font-bold tabular-nums" :class="comp.margem < 0 ? 'text-error' : 'text-success'">{{ comp.receita ? pct((comp.margem / comp.receita) * 100) : '—' }}</span>
                    </div>
                </div>
                <p v-else class="py-20 text-center text-sm text-slate-500">Sem vendas no período.</p>
                <ul class="mt-2 space-y-1 text-sm">
                    <li v-for="p in partesComp" :key="p.rotulo" class="flex items-center justify-between gap-2">
                        <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" :style="{ background: p.cor }"></span>{{ p.rotulo }}</span>
                        <span class="tabular-nums text-slate-600 dark:text-slate-300">{{ moeda(p.valor) }}</span>
                    </li>
                    <li v-if="comp.margem < 0" class="font-semibold text-error">Prejuízo de {{ moeda(-comp.margem) }}</li>
                </ul>
            </section>
        </div>

        <!-- Margem, ADS e alertas -->
        <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="g-margem">
                <h2 id="g-margem" class="font-semibold">Margem por canal</h2>
                <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">Vermelho = canal no prejuízo.</p>
                <ChartCanvas v-if="margemCanal.length" type="bar" :data="barrasMargem" :options="barrasMargemOpcoes" />
                <p v-else class="py-20 text-center text-sm text-slate-500">Sem dados.</p>
            </section>

            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="g-ads">
                <h2 id="g-ads" class="font-semibold">ADS: investimento × venda</h2>
                <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">Cinza é o que foi investido; colorido, o que o ADS vendeu.</p>
                <ChartCanvas v-if="adsCanal.length" type="bar" :data="barrasAds" :options="barrasAdsOpcoes" />
                <p v-else class="py-20 text-center text-sm text-slate-500">Sem ADS nos canais escolhidos.</p>
            </section>

            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="g-alertas">
                <h2 id="g-alertas" class="font-semibold">Alertas por assunto</h2>
                <p class="mb-2 flex flex-wrap gap-3 text-xs text-slate-500 dark:text-slate-400">
                    <span class="inline-flex items-center gap-1.5"><i class="fas fa-circle-exclamation" :style="{ color: STATUS.critico }"></i>Crítico</span>
                    <span class="inline-flex items-center gap-1.5"><i class="fas fa-triangle-exclamation" :style="{ color: STATUS.atencao }"></i>Atenção</span>
                    <span>Passe o mouse para ler.</span>
                </p>
                <ChartCanvas v-if="gruposAlerta.length" type="bar" :data="barrasAlertas" :options="barrasAlertasOpcoes" />
                <p v-else class="py-20 text-center text-sm"><i class="fas fa-circle-check mr-1 text-success"></i>Nenhum indicador fora do limite.</p>
            </section>
        </div>

        <section v-if="sites.length" class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="g-sites">
            <h2 id="g-sites" class="font-semibold">Visitantes únicos por site</h2>
            <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">Verde é o período; cinza, o anterior. Sem equipe e sem robô.</p>
            <ChartCanvas type="bar" :data="barrasSites" :options="barrasSitesOpcoes" />
        </section>

        <p class="text-xs text-slate-500 dark:text-slate-400">
            Margem = receita − custo − taxas − frete − ADS + bônus Flex. ROAS = venda do ADS ÷ investimento; ACOS = investimento ÷ venda do ADS; TACOS = investimento ÷ faturamento do canal; CTR = cliques ÷ impressões; CPC = investimento ÷ cliques; CPA = investimento ÷ pedidos do ADS.
        </p>
    </AdminLayout>
</template>
