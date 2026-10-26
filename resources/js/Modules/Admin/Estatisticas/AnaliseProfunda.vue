<script setup>
/**
 * Estatísticas › Análise Profunda de Conta · Shopee (pedido do Lira em
 * 2026-10-26; especificação de texto e UX do Jonathan em
 * Documents/2026-10-26-analise-profunda-conta-shopee.md do Homer).
 * Risco primeiro, todo número com a fonte e o (?) que ensina a ler.
 * SÓ LEITURA: nenhum botão muda nada na Shopee; ação vira link para a
 * Estratégia Shopee, o cadastro do produto ou o Seller Center.
 * Dados: AnaliseProfundaController + AnaliseProfundaShopee.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import AjudaMetrica from './AjudaMetrica.vue';
import { GLOSSARIO, GRUPOS } from './glossarioShopee.js';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    canal: { type: Object, required: true },
    filtro: { type: Object, required: true },
    periodos: { type: Array, default: () => [] },
    analise: { type: Object, required: true },
    saldo: { type: [Number, null], default: null },
});

const SD = 's/d';
const vazio = (v) => v === null || v === undefined || Number.isNaN(v);
const dinheiro = (v) => (vazio(v) ? SD : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v));
const numero = (v) => (vazio(v) ? SD : new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 }).format(v));
const um = (v) => (vazio(v) ? SD : Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }));
const dois = (v) => (vazio(v) ? SD : Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const pct = (v) => (vazio(v) ? SD : `${dois(v)}%`);
const data = (d) => (d ? new Date(`${String(d).slice(0, 10)}T00:00:00`).toLocaleDateString('pt-BR') : SD);
const curta = (d) => (d ? new Date(`${String(d).slice(0, 10)}T00:00:00`).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }) : SD);
const dataHora = (d) => (d ? new Date(String(d).replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : null);

const a = computed(() => props.analise);
const conta = computed(() => a.value.conta);
const antes = computed(() => a.value.conta_anterior);
const comparar = ref(true);

// Variação contra o período anterior. Vermelho é sempre "piorou".
const variacao = (agora, anterior, menorMelhor = false) => {
    if (!comparar.value || vazio(agora) || vazio(anterior) || anterior === 0) return null;
    const v = ((agora - anterior) / Math.abs(anterior)) * 100;
    const melhorou = menorMelhor ? v <= 0 : v >= 0;
    return { texto: `${v >= 0 ? '▲' : '▼'} ${um(Math.abs(v))}%`, melhorou };
};
const textoVar = (x) => (x ? ` · ${x.texto} vs anterior` : '');

// Período.
const desde = ref(props.filtro.desde);
const ate = ref(props.filtro.ate);
const url = `/admin/estatisticas/analise-profunda/${props.canal.slug}`;
const irPeriodo = (dias) => router.get(url, { dias }, { preserveScroll: true });
const irIntervalo = () => desde.value && ate.value && router.get(url, { desde: desde.value, ate: ate.value }, { preserveScroll: true });

// Fonte da tela da Shopee.
const fonteTela = computed(() => a.value.funil.fonte);
const avisoFonte = (f) => {
    if (!f) return null;
    if (f.tipo === 'aproximado') return `Funil da Shopee mostrado de ${data(f.inicio)} a ${data(f.fim)} (a coleta mais próxima do período escolhido).`;
    if (f.tipo === 'parcial') return `Funil da Shopee só tem ${f.dias} dias coletados neste período (${data(f.inicio)} a ${data(f.fim)}).`;
    return null;
};
const seloTela = (f) => (f ? `Tela · ${curta(f.inicio)} a ${curta(f.fim)}` : 'Tela · sem coleta');

// Cartões.
const lucro = computed(() => conta.value.lucro ?? 0);
const margemPct = computed(() => (conta.value.receita > 0 ? (lucro.value / conta.value.receita) * 100 : null));
const ticket = computed(() => (conta.value.pedidos > 0 ? conta.value.receita / conta.value.pedidos : null));
const funil = computed(() => a.value.funil.loja);
const funilAntes = computed(() => a.value.funil.anterior);
const adsSobreVendas = computed(() => (conta.value.receita > 0 ? (conta.value.ads / conta.value.receita) * 100 : null));
const k = computed(() => a.value.ads.kpis);
const varianteConversao = computed(() => {
    if (!funil.value || vazio(funil.value.conversao) || !funilAntes.value || vazio(funilAntes.value.conversao)) return 'neutral';
    return funil.value.conversao >= funilAntes.value.conversao ? 'success' : 'error';
});

// Veredito.
const graves = computed(() => a.value.riscos.filter((r) => r.gravidade === 'grave'));
const perdaGraves = computed(() => graves.value.reduce((s, r) => s + (r.valor && r.valor < 0 ? r.valor : 0), 0));
const semPedidos = computed(() => !conta.value.pedidos);
const veredito = computed(() => {
    const dias = a.value.periodo.dias;
    if (semPedidos.value) return { cor: 'warning', texto: 'Nenhum pedido da Shopee neste período no KazaKora. Escolha um período maior. O funil da tela continua abaixo.' };
    if (lucro.value < 0) {
        const explica = graves.value.length && perdaGraves.value < 0 ? ` ${graves.value.length} anúncios explicam ${dinheiro(Math.abs(perdaGraves.value))} disso.` : '';
        return { cor: 'error', texto: `Em ${dias} dias a Shopee deu ${dinheiro(Math.abs(lucro.value))} de prejuízo.${explica} Comece por "Riscos agora".` };
    }
    if (graves.value.length) return { cor: 'warning', texto: `Em ${dias} dias a Shopee deixou ${dinheiro(lucro.value)} de lucro, mas ${graves.value.length} anúncios estão tirando dinheiro agora. Comece por "Riscos agora".` };
    return { cor: 'success', texto: `Em ${dias} dias a Shopee deixou ${dinheiro(lucro.value)} de lucro líquido, ${um(margemPct.value)}% do que vendeu. Nenhum risco grave aberto.` };
});

// Riscos.
const GRAV = {
    grave: { rotulo: 'Grave', cor: 'bg-lighterror text-error', icone: 'fas fa-ban' },
    atencao: { rotulo: 'Atenção', cor: 'bg-lightwarning text-warning', icone: 'fas fa-triangle-exclamation' },
    melhorar: { rotulo: 'Melhorar', cor: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300', icone: 'fas fa-wrench' },
    oportunidade: { rotulo: 'Oportunidade', cor: 'bg-lightinfo text-info', icone: 'fas fa-arrow-trend-up' },
};
const TEMA = { preco: 'Preço e lucro', ads: 'ADS', anuncio: 'Anúncio', funil: 'Funil', promocao: 'Promoção', estoque: 'Estoque', conta: 'Conta', avaliacao: 'Avaliações' };
const filtroRisco = ref('todos');
const verTodosRiscos = ref(false);
const contagemGrav = computed(() => Object.fromEntries(['grave', 'atencao', 'melhorar'].map((g) => [g, a.value.riscos.filter((r) => r.gravidade === g).length])));
const temas = computed(() => Object.keys(TEMA).filter((t) => a.value.riscos.some((r) => r.alertas.some((x) => x.tema === t))));
const riscosFiltrados = computed(() => a.value.riscos.filter((r) => {
    if (filtroRisco.value === 'todos') return true;
    if (GRAV[filtroRisco.value]) return r.gravidade === filtroRisco.value;
    return r.alertas.some((x) => x.tema === filtroRisco.value);
}));
const riscosVisiveis = computed(() => (verTodosRiscos.value ? riscosFiltrados.value : riscosFiltrados.value.slice(0, 15)));
const destino = (r, alerta) => {
    if (alerta.destino === 'ads') return { href: '/admin/publicidade/shopee', rotulo: 'Ver na Estratégia Shopee', externo: false };
    if (alerta.destino === 'produto' && r.product_id) return { href: `/admin/produtos/${r.product_id}/editar`, rotulo: 'Abrir produto', externo: false };
    if (r.link_shopee) return { href: r.link_shopee, rotulo: 'Abrir no Seller Center', externo: true };
    return null;
};
const semTela = computed(() => !fonteTela.value);

// Dinheiro (cascata pela ContributionMargin).
const cascata = computed(() => {
    const c = conta.value;
    const linhas = [
        { rotulo: 'Vendas', valor: c.receita, sinal: '' },
        { rotulo: 'Custo dos produtos', valor: c.custo_produtos, sinal: '−' },
        { rotulo: 'Taxas da Shopee', valor: c.taxas, sinal: '−' },
        { rotulo: 'Frete pago pela loja', valor: c.frete, sinal: '−' },
        { rotulo: 'ADS', valor: c.ads, sinal: '−' },
        { rotulo: 'Imposto (Simples)', valor: c.imposto, sinal: '−' },
    ].filter((l) => l.rotulo !== 'Frete pago pela loja' || l.valor > 0);
    return linhas.map((l) => ({ ...l, pct: c.receita > 0 ? (l.valor / c.receita) * 100 : null }));
});
const maxSerie = computed(() => Math.max(1, ...a.value.serie.map((d) => Math.abs(d.receita))));
const taxaReal = computed(() => (conta.value.receita > 0 ? (conta.value.taxas / conta.value.receita) * 100 : null));

// Funil.
const degraus = computed(() => {
    const f = funil.value;
    if (!f) return [];
    return [
        { rotulo: 'Impressões', valor: f.impressoes, chave: 'impressoes', passa: f.ctr, passaRotulo: 'CTR', ruim: !vazio(f.ctr) && f.ctr < 2 },
        { rotulo: 'Cliques', valor: f.cliques, chave: null, passa: f.cliques && f.visitantes ? (f.visitantes / f.cliques) * 100 : null, passaRotulo: 'viraram visitante', ruim: false },
        { rotulo: 'Visitantes', valor: f.visitantes, chave: 'visitantes', passa: f.taxa_carrinho, passaRotulo: 'visitante → carrinho', ruim: false },
        { rotulo: 'No carrinho', valor: f.add_carrinho_compradores, chave: 'carrinho', passa: vazio(f.abandono) ? null : 100 - f.abandono, passaRotulo: 'carrinho → pedido', ruim: !vazio(f.abandono) && f.abandono >= 90 },
        { rotulo: 'Pedidos feitos', valor: f.pedidos, chave: null, passa: f.taxa_pagamento, passaRotulo: 'feito → pago', ruim: false },
        { rotulo: 'Pedidos pagos', valor: f.pedidos_pagos, chave: 'pagamento', passa: null },
    ];
});
const vazamento = computed(() => {
    const f = funil.value;
    if (!f) return null;
    if (!vazio(f.abandono) && f.abandono >= 90) return `O maior vazamento está entre carrinho e pedido: ${um(f.abandono)}% desistem. Veja frete e preço final dos produtos com mais carrinho na tabela Produtos.`;
    if (!vazio(f.ctr) && f.ctr < 2) return `O maior vazamento está na capa: só ${dois(f.ctr)}% de quem vê clica. Veja os produtos com CTR em vermelho na tabela Produtos.`;
    if (!vazio(f.abandono)) return `De quem pôs no carrinho, ${um(f.abandono)}% não fizeram o pedido.`;
    return null;
});
const maxDia = computed(() => Math.max(1, ...a.value.funil.dias.map((d) => d.visitantes ?? 0)));

// ADS.
const fonteAds = computed(() => a.value.ads.fonte);
const notaSaldo = computed(() => {
    if (props.saldo === null) return 'sem conexão com a Shopee Ads agora';
    if (!k.value.gasto_dia) return 'sem gasto no período';
    return `dura cerca de ${Math.floor(props.saldo / k.value.gasto_dia)} dias no ritmo atual`;
});
const ordemAds = ref({ campo: 'gasto', desc: true });
const anunciosAds = computed(() => {
    const { campo, desc } = ordemAds.value;
    return [...a.value.ads.anuncios].sort((x, y) => ((x[campo] ?? -Infinity) - (y[campo] ?? -Infinity)) * (desc ? -1 : 1));
});
const ordenarAds = (campo) => { ordemAds.value = { campo, desc: ordemAds.value.campo === campo ? !ordemAds.value.desc : true }; };
const ariaAds = (campo) => (ordemAds.value.campo === campo ? (ordemAds.value.desc ? 'descending' : 'ascending') : 'none');
const COLUNAS_ADS = [
    ['gasto', 'Gasto'], ['venda_ads', 'Venda'], ['roas', 'ROAS'], ['roas_empate', 'Empate'], ['roas_meta', 'Meta 15%'],
    ['ctr_ads', 'CTR'], ['cpc', 'CPC'], ['custo_por_pedido', 'Custo por pedido'], ['conversao_ads', 'Conversão'], ['resultado_ads', 'Resultado'],
];
const comAds = (p) => ({
    ...p,
    ctr_ads: p.impressoes_ads > 0 ? (p.cliques_ads / p.impressoes_ads) * 100 : null,
    cpc: p.cliques_ads > 0 ? p.gasto / p.cliques_ads : null,
    conversao_ads: p.cliques_ads > 0 ? (p.pedidos_ads / p.cliques_ads) * 100 : null,
});

// Horários.
const DIAS_SEMANA = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
const h = computed(() => a.value.horarios);
const maxMapa = computed(() => Math.max(1, ...h.value.mapa.flat()));
const corMapa = (n) => (n ? `color-mix(in srgb, #EE4D2D ${Math.round(15 + (n / maxMapa.value) * 85)}%, transparent)` : 'transparent');
const maxVisitaHora = computed(() => Math.max(1, ...(h.value.visitas_por_hora ?? []).map((v) => v ?? 0)));
const minutos = (m) => (vazio(m) ? SD : m >= 120 ? `${um(m / 60)} h` : `${numero(m)} min`);

// Produtos.
const mapaRisco = computed(() => Object.fromEntries(a.value.riscos.filter((r) => r.item_id).map((r) => [r.item_id, r])));
const mapaOport = computed(() => Object.fromEntries(a.value.oportunidades.filter((r) => r.item_id).map((r) => [r.item_id, r])));
const situacaoProduto = (p) => (mapaRisco.value[p.item_id]?.gravidade ?? (mapaOport.value[p.item_id] ? 'oportunidade' : 'sem'));
const busca = ref('');
const situacao = ref('');
const filtroAds = ref('');
const estoqueBaixo = ref(false);
const ordem = ref('vendidos');
const ORDENS = {
    vendidos: ['Mais vendidos', (p) => -p.unidades],
    lucro: ['Maior lucro', (p) => -(p.lucro ?? -Infinity)],
    prejuizo: ['Maior prejuízo', (p) => p.lucro ?? Infinity],
    visitantes: ['Mais visitantes', (p) => -(p.visitantes ?? -1)],
    conversao: ['Pior conversão', (p) => p.conversao ?? Infinity],
    rejeicao: ['Maior rejeição', (p) => -(p.rejeicao ?? -1)],
    abandono: ['Maior abandono', (p) => -(p.abandono ?? -1)],
};
const coluna = ref(null);
const ordenarColuna = (c) => { coluna.value = coluna.value?.campo === c ? { campo: c, desc: !coluna.value.desc } : { campo: c, desc: true }; };
const ariaColuna = (c) => (coluna.value?.campo === c ? (coluna.value.desc ? 'descending' : 'ascending') : 'none');
const produtosFiltrados = computed(() => {
    const termo = busca.value.trim().toLowerCase();
    const lista = a.value.produtos.filter((p) => {
        if (termo && ![p.nome, p.sku, p.item_id].some((v) => String(v ?? '').toLowerCase().includes(termo))) return false;
        if (situacao.value && situacaoProduto(p) !== situacao.value) return false;
        if (filtroAds.value === 'com' && !p.ads_ativo && !p.gasto) return false;
        if (filtroAds.value === 'sem' && (p.ads_ativo || p.gasto)) return false;
        if (estoqueBaixo.value && !(p.dias_estoque !== null && p.dias_estoque < 15)) return false;
        return true;
    });
    if (coluna.value) {
        const { campo, desc } = coluna.value;
        return lista.sort((x, y) => ((x[campo] ?? -Infinity) - (y[campo] ?? -Infinity)) * (desc ? -1 : 1));
    }
    const chave = ORDENS[ordem.value][1];
    return lista.sort((x, y) => chave(x) - chave(y));
});
const pagina = ref(1);
const POR_PAGINA = 50;
const paginas = computed(() => Math.max(1, Math.ceil(produtosFiltrados.value.length / POR_PAGINA)));
const produtosPagina = computed(() => produtosFiltrados.value.slice((pagina.value - 1) * POR_PAGINA, pagina.value * POR_PAGINA));
const aberto = ref(null);
const alternarLinha = (id) => { aberto.value = aberto.value === id ? null : id; };
const COLUNAS = [
    ['impressoes', 'Impressões', 'impressoes'], ['ctr', 'CTR', 'ctr'], ['visitantes', 'Visitantes', 'visitantes'], ['rejeicao', 'Rejeição', 'rejeicao'],
    ['carrinho', 'Carrinho', 'carrinho'], ['abandono', 'Abandono', 'abandono'], ['conversao', 'Conversão', 'conversao'],
    ['unidades', 'Unidades', null], ['receita', 'Vendas', 'vendas'], ['ticket', 'Ticket', 'ticket'], ['ranking', 'Ranking', null],
    ['preco', 'Preço', null], ['lucro_unidade', 'Lucro/un', 'lucro_unidade'], ['margem', 'Margem', null], ['lucro_pct_custo', 'Lucro % custo', 'lucro_pct_custo'],
    ['preco_minimo', 'Piso 15%', 'piso'], ['gasto', 'Gasto ADS', null], ['roas', 'ROAS', 'roas'], ['roas_empate', 'Empate', 'empate'],
    ['intervalo_horas', 'Vende a cada', 'vende_a_cada'], ['estoque', 'Estoque', null], ['dias_estoque', 'Dias de estoque', 'dias_estoque'],
];
const celula = (p, c) => {
    const v = p[c];
    if (['ctr', 'rejeicao', 'abandono', 'conversao'].includes(c)) return pct(v);
    if (['receita', 'ticket', 'preco', 'lucro_unidade', 'preco_minimo', 'gasto'].includes(c)) return c === 'gasto' && !v ? SD : dinheiro(v);
    if (c === 'margem' || c === 'lucro_pct_custo') return vazio(v) ? SD : `${um(v)}%`;
    if (c === 'roas' || c === 'roas_empate') return um(v);
    if (c === 'ranking') return vazio(v) ? SD : `${v}º`;
    if (c === 'intervalo_horas') return vazio(v) ? SD : v >= 48 ? `${um(v / 24)} dias` : `${um(v)} h`;
    return numero(v);
};
const corCelula = (p, c) => {
    if (c === 'ctr' && !vazio(p.ctr) && p.impressoes >= 500 && p.ctr < 2) return 'text-error font-semibold';
    if (c === 'conversao' && !vazio(p.conversao) && p.visitantes >= 100 && p.conversao < 1) return 'text-error font-semibold';
    if (c === 'abandono' && !vazio(p.abandono) && p.carrinho >= 20 && p.abandono >= 90) return 'text-error font-semibold';
    if (c === 'lucro_unidade' && !vazio(p.lucro_unidade)) return p.lucro_unidade <= 0 ? 'text-error font-semibold' : '';
    if (c === 'margem' && !vazio(p.margem) && p.margem < 15) return 'text-error';
    if (c === 'preco' && !vazio(p.preco_minimo) && !vazio(p.preco) && p.preco < p.preco_minimo) return 'text-error';
    if (c === 'roas' && !vazio(p.roas) && p.roas_empate && p.roas < p.roas_empate) return 'text-error font-semibold';
    if (c === 'dias_estoque' && !vazio(p.dias_estoque) && p.dias_estoque < 15) return 'text-warning font-semibold';
    return vazio(p[c]) ? 'text-slate-400' : '';
};
const baixarCsv = () => {
    const cab = ['item_id', 'nome', 'sku', ...COLUNAS.map((c) => c[1])];
    const linhas = produtosFiltrados.value.map((p) => [p.item_id ?? '', p.nome, p.sku ?? '', ...COLUNAS.map((c) => (vazio(p[c[0]]) ? '' : String(p[c[0]]).replace('.', ',')))]);
    const csv = [cab, ...linhas].map((l) => l.map((v) => `"${String(v).replace(/"/g, '""')}"`).join(';')).join('\n');
    const blob = new Blob([`﻿${csv}`], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `shopee-produtos-${a.value.periodo.desde}-a-${a.value.periodo.ate}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
};

// Glossário.
const glossarioPorGrupo = computed(() => GRUPOS.map((g) => ({ grupo: g, itens: Object.entries(GLOSSARIO).filter(([, v]) => v.grupo === g) })));
const lojaDe = (chave) => {
    if (chave === 'conversao' && funil.value && !vazio(funil.value.conversao)) return dois(funil.value.conversao);
    if (chave === 'rejeicao' && funil.value && !vazio(funil.value.taxa_rejeicao)) return dois(funil.value.taxa_rejeicao);
    return null;
};

const ANCORAS = [
    ['riscos', 'Riscos'], ['oportunidades', 'Oportunidades'], ['funil', 'Funil'], ['dinheiro', 'Dinheiro'], ['ads', 'ADS'],
    ['horarios', 'Horários'], ['produtos', 'Produtos'], ['promocoes', 'Promoções'], ['saude', 'Saúde'], ['avaliacoes', 'Avaliações'], ['glossario', 'Glossário'],
];
const cartaoClasse = 'mb-6 scroll-mt-24 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm';
const botaoSec = 'whitespace-nowrap rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-primary';
const selo = 'rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300';
</script>

<template>
    <Head title="Análise Profunda de Conta · Shopee" />

    <AdminLayout>
        <div class="shopee-preto">
            <!-- 1. Cabeçalho e período -->
            <div class="mb-2 flex flex-wrap items-center gap-3">
                <h1 class="flex items-center gap-3 text-2xl font-bold">
                    <i class="fas fa-microscope" style="color: #EE4D2D" aria-hidden="true"></i>
                    Análise Profunda de Conta · Shopee
                </h1>
                <div class="ml-auto flex flex-wrap items-center gap-2" role="group" aria-label="Período">
                    <button
                        v-for="d in periodos"
                        :key="d"
                        type="button"
                        class="rounded-lg border px-3 py-1.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-primary"
                        :class="filtro.dias === d ? 'border-primary bg-primary text-white' : 'border-[var(--surface-border)] hover:border-primary hover:text-primary'"
                        :aria-pressed="filtro.dias === d"
                        @click="irPeriodo(d)"
                    >{{ d }} dias</button>
                    <label class="sr-only" for="apf-desde">De</label>
                    <input id="apf-desde" v-model="desde" type="date" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 text-sm focus-visible:outline-2 focus-visible:outline-primary" />
                    <label class="sr-only" for="apf-ate">Até</label>
                    <input id="apf-ate" v-model="ate" type="date" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 text-sm focus-visible:outline-2 focus-visible:outline-primary" />
                    <button type="button" :class="botaoSec" @click="irIntervalo">Aplicar período</button>
                </div>
            </div>
            <p class="mb-1 text-sm text-slate-500 dark:text-slate-400">
                {{ data(analise.periodo.desde) }} a {{ data(analise.periodo.ate) }} · Pedidos e lucro: agora ·
                ADS: {{ analise.status.ads_sincronizado_em ? `atualizado ${dataHora(analise.status.ads_sincronizado_em)}` : 'sem sincronização' }} ·
                <span :class="analise.status.tela_atrasada ? 'font-semibold text-warning' : ''">
                    <template v-if="!analise.status.tela_coletada_em">Tela da Shopee: ainda sem coleta</template>
                    <template v-else-if="analise.status.tela_atrasada">Tela da Shopee: última coleta {{ dataHora(analise.status.tela_coletada_em) }}, números de funil podem estar velhos</template>
                    <template v-else>Tela da Shopee: coletada {{ dataHora(analise.status.tela_coletada_em) }}</template>
                </span>
            </p>
            <label class="mb-4 inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input v-model="comparar" type="checkbox" /> Comparar com o período anterior ({{ data(analise.periodo.anterior.desde) }} a {{ data(analise.periodo.anterior.ate) }})
            </label>

            <div v-if="semTela" class="mb-4 rounded-lg border-l-4 border-warning bg-lightwarning px-4 py-2 text-sm text-warning" role="status">
                Parte desta tela depende da coleta do Seller Center, que ainda não rodou para este período. Riscos de preço, ADS e estoque já valem.
            </div>

            <nav class="sticky top-0 z-20 -mx-1 mb-4 flex gap-1 overflow-x-auto rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-1 py-1 text-sm shadow-sm" aria-label="Seções da análise">
                <a v-for="[id, rotulo] in ANCORAS" :key="id" :href="`#${id}`" class="inline-flex items-center gap-1 whitespace-nowrap rounded-md px-2 py-1 font-medium hover:bg-lightprimary hover:text-primary focus-visible:outline-2 focus-visible:outline-primary">
                    {{ rotulo }}
                    <span v-if="id === 'riscos' && contagemGrav.grave" class="rounded-full bg-error px-1.5 text-xs font-bold text-white">{{ contagemGrav.grave }}<span class="sr-only"> riscos graves</span></span>
                </a>
            </nav>

            <!-- 2. Veredito e 4 cartões -->
            <p
                class="mb-4 rounded-xl border-l-4 px-4 py-3 text-base font-semibold"
                :class="{ success: 'border-success bg-lightsuccess text-success', warning: 'border-warning bg-lightwarning text-warning', error: 'border-error bg-lighterror text-error' }[veredito.cor]"
                role="status"
            >{{ veredito.texto }}</p>

            <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="relative">
                    <CardStats compact note-wrap stat-subtitle="LUCRO LÍQUIDO" :stat-title="dinheiro(conta.lucro)" :title-class="lucro >= 0 ? 'text-success' : 'text-error'"
                        :stat-note="`${um(margemPct)}% das vendas${textoVar(variacao(conta.lucro, antes.lucro))}`" :stat-icon-name="lucro >= 0 ? 'fas fa-sack-dollar' : 'fas fa-triangle-exclamation'" :variant="lucro >= 0 ? 'success' : 'error'" />
                    <span class="absolute right-2 top-2"><AjudaMetrica chave="lucro_liquido" /></span>
                </div>
                <div class="relative">
                    <CardStats compact note-wrap stat-subtitle="VENDAS" :stat-title="dinheiro(conta.receita)"
                        :stat-note="`${numero(conta.pedidos)} pedidos · ticket médio ${dinheiro(ticket)}${textoVar(variacao(conta.receita, antes.receita))}`" stat-icon-name="fas fa-bag-shopping" variant="neutral" />
                    <span class="absolute right-2 top-2"><AjudaMetrica chave="vendas" /></span>
                </div>
                <div class="relative">
                    <CardStats compact note-wrap stat-subtitle="CONVERSÃO DA LOJA" :stat-title="pct(funil?.conversao)"
                        :stat-note="funil ? `${numero(funil.visitantes)} visitantes · ${numero(funil.pedidos)} pedidos · ${seloTela(fonteTela)}` : 'sem coleta da tela'"
                        stat-icon-name="fas fa-filter" :variant="varianteConversao" />
                    <span class="absolute right-2 top-2"><AjudaMetrica chave="conversao" :loja="lojaDe('conversao')" /></span>
                </div>
                <div class="relative">
                    <CardStats compact note-wrap stat-subtitle="ADS SOBRE VENDAS" :stat-title="vazio(adsSobreVendas) ? SD : `${um(adsSobreVendas)}%`"
                        :stat-note="`${dinheiro(conta.ads)} de ADS · ROAS ${um(k.roas)}`" stat-icon-name="fas fa-bullhorn" variant="neutral" />
                    <span class="absolute right-2 top-2"><AjudaMetrica chave="tacos" /></span>
                </div>
            </div>

            <!-- 3. Riscos agora -->
            <section id="riscos" :class="[cartaoClasse, 'border-l-4', contagemGrav.grave ? 'border-l-error' : 'border-l-success']" aria-labelledby="riscos-titulo">
                <h2 id="riscos-titulo" class="text-lg font-semibold">Riscos agora: o que está custando dinheiro</h2>
                <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">Do mais grave para o mais leve. Cada linha diz o número que acendeu o alerta e o que fazer.</p>
                <p v-if="semTela" class="mb-3 text-sm text-warning">Alertas de funil (CTR, conversão, carrinho) desligados: ainda não há coleta da tela da Shopee para este período.</p>
                <div v-if="analise.riscos.length" class="mb-3 flex flex-wrap gap-2" role="group" aria-label="Filtrar riscos">
                    <button v-for="f in [['todos', `Todos (${analise.riscos.length})`], ['grave', `Grave (${contagemGrav.grave})`], ['atencao', `Atenção (${contagemGrav.atencao})`], ['melhorar', `Melhorar (${contagemGrav.melhorar})`], ...temas.map((t) => [t, TEMA[t]])]"
                        :key="f[0]" type="button"
                        class="rounded-full border px-3 py-1 text-xs font-semibold focus-visible:outline-2 focus-visible:outline-primary"
                        :class="filtroRisco === f[0] ? 'border-primary bg-lightprimary text-primary' : 'border-[var(--surface-border)]'"
                        :aria-pressed="filtroRisco === f[0]" @click="filtroRisco = f[0]">{{ f[1] }}</button>
                </div>
                <ol v-if="riscosVisiveis.length" class="divide-y divide-[var(--surface-border)]">
                    <li v-for="r in riscosVisiveis" :key="r.item_id ?? r.nome" class="flex flex-wrap items-start gap-x-4 gap-y-2 py-3">
                        <div class="min-w-0 flex-1 basis-72">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-bold" :class="GRAV[r.gravidade].cor">
                                    <i :class="GRAV[r.gravidade].icone" aria-hidden="true"></i>{{ GRAV[r.gravidade].rotulo }}
                                </span>
                                <a v-if="r.link_shopee" :href="r.link_shopee" target="_blank" rel="noopener noreferrer" class="min-w-0 truncate font-semibold hover:text-primary hover:underline" :title="r.nome">
                                    {{ r.nome }}<span class="sr-only"> (abre em outra aba)</span>
                                </a>
                                <span v-else class="min-w-0 truncate font-semibold">{{ r.nome }}</span>
                            </div>
                            <p class="mt-1 text-sm font-semibold">{{ r.alertas[0].texto }}</p>
                            <p class="mt-0.5 text-xs tabular-nums text-slate-500 dark:text-slate-400">
                                {{ r.alertas[0].numero }} · {{ r.alertas[0].fonte === 'Tela' ? seloTela(fonteTela) : r.alertas[0].fonte }} · {{ r.alertas[0].codigo }}
                            </p>
                            <div v-if="r.alertas.length > 1" class="mt-1 flex flex-wrap gap-1">
                                <span v-for="x in r.alertas.slice(1)" :key="x.codigo" :class="[selo, 'cursor-help']" :title="`${x.texto} (${x.numero ?? ''})`">+ {{ x.texto.split(':')[0].split('.')[0] }}</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span v-if="r.valor" class="text-sm font-bold tabular-nums text-error">{{ dinheiro(r.valor) }}</span>
                            <template v-for="d in [destino(r, r.alertas[0])]" :key="'d'">
                                <a v-if="d && d.externo" :href="d.href" target="_blank" rel="noopener noreferrer" :class="botaoSec">{{ d.rotulo }}<span class="sr-only"> (abre em outra aba)</span></a>
                                <Link v-else-if="d" :href="d.href" :class="botaoSec">{{ d.rotulo }}</Link>
                            </template>
                        </div>
                    </li>
                </ol>
                <p v-else class="text-sm font-semibold text-success">Nenhum risco aberto neste período. Bom sinal: confira as oportunidades logo abaixo.</p>
                <button v-if="!verTodosRiscos && riscosFiltrados.length > 15" type="button" :class="[botaoSec, 'mt-2']" @click="verTodosRiscos = true">Ver todos os {{ riscosFiltrados.length }} riscos</button>
            </section>

            <!-- 4. Oportunidades -->
            <section id="oportunidades" :class="cartaoClasse" aria-labelledby="oport-titulo">
                <h2 id="oport-titulo" class="text-lg font-semibold">Oportunidades: onde dá para vender mais com lucro</h2>
                <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">Produtos que já mostram sinal bom. Nenhuma ação é feita daqui: a tela só aponta.</p>
                <ol v-if="analise.oportunidades.length" class="divide-y divide-[var(--surface-border)]">
                    <li v-for="o in analise.oportunidades" :key="o.item_id" class="flex flex-wrap items-start gap-x-4 gap-y-2 py-3">
                        <div class="min-w-0 flex-1 basis-72">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-bold" :class="GRAV.oportunidade.cor"><i :class="GRAV.oportunidade.icone" aria-hidden="true"></i>Oportunidade</span>
                                <span class="min-w-0 truncate font-semibold" :title="o.nome">{{ o.nome }}</span>
                            </div>
                            <p class="mt-1 text-sm font-semibold">{{ o.alertas[0].texto }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">{{ o.alertas[0].numero }} · {{ seloTela(fonteTela) }}</p>
                        </div>
                        <Link href="/admin/publicidade/shopee" :class="botaoSec">Ver na Estratégia Shopee</Link>
                    </li>
                </ol>
                <p v-else class="text-sm text-slate-500">Nenhuma oportunidade clara neste período. Isso muda quando a coleta da tela tiver mais dias.</p>
            </section>

            <!-- 5. Funil -->
            <section id="funil" :class="cartaoClasse" aria-labelledby="funil-titulo">
                <h2 id="funil-titulo" class="text-lg font-semibold">Funil: do anúncio visto ao pedido pago <span :class="[selo, 'ml-1 align-middle']">{{ seloTela(fonteTela) }}</span></h2>
                <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">Onde as pessoas desistem. Cada degrau mostra quantas passaram e a porcentagem que seguiu para o próximo. Soma dos anúncios da loja.</p>
                <p v-if="avisoFonte(fonteTela)" class="mb-3 rounded-lg border-l-4 border-warning bg-lightwarning px-3 py-2 text-sm text-warning">{{ avisoFonte(fonteTela) }}</p>
                <template v-if="funil">
                    <ol class="grid grid-cols-1 gap-2 sm:grid-cols-3 xl:grid-cols-6">
                        <li v-for="(d, i) in degraus" :key="d.rotulo" class="rounded-lg border border-[var(--surface-border)] p-3">
                            <span class="block text-2xl font-bold tabular-nums">{{ numero(d.valor) }}</span>
                            <span class="text-sm text-slate-500">{{ d.rotulo }}<AjudaMetrica v-if="d.chave" :chave="d.chave" :loja="lojaDe(d.chave)" /></span>
                            <span v-if="comparar && funilAntes && variacao(d.valor, funilAntes[['impressoes', 'cliques', 'visitantes', 'add_carrinho_compradores', 'pedidos', 'pedidos_pagos'][i]])"
                                class="block text-xs tabular-nums"
                                :class="variacao(d.valor, funilAntes[['impressoes', 'cliques', 'visitantes', 'add_carrinho_compradores', 'pedidos', 'pedidos_pagos'][i]]).melhorou ? 'text-success' : 'text-error'"
                            >{{ variacao(d.valor, funilAntes[['impressoes', 'cliques', 'visitantes', 'add_carrinho_compradores', 'pedidos', 'pedidos_pagos'][i]]).texto }} vs anterior</span>
                            <span v-if="d.passa !== null && d.passa !== undefined" class="mt-2 block text-xs font-semibold" :class="d.ruim ? 'text-error' : 'text-slate-600 dark:text-slate-300'">
                                <i class="fas fa-arrow-right" aria-hidden="true"></i> {{ pct(d.passa) }} {{ d.passaRotulo }}
                            </span>
                        </li>
                    </ol>
                    <p class="mt-3 text-sm">
                        <b>Taxa de rejeição {{ pct(funil.taxa_rejeicao) }}</b><AjudaMetrica chave="rejeicao" :loja="lojaDe('rejeicao')" />
                        <span class="mx-2 text-slate-400" aria-hidden="true">|</span>
                        <b>Conversão {{ pct(funil.conversao) }}</b><AjudaMetrica chave="conversao" :loja="lojaDe('conversao')" />
                        <span class="mx-2 text-slate-400" aria-hidden="true">|</span>
                        <b>Carrinho abandonado {{ pct(funil.abandono) }}</b><AjudaMetrica chave="abandono" />
                    </p>
                    <p v-if="vazamento" class="mt-2 text-sm font-semibold">{{ vazamento }}</p>
                </template>
                <p v-else class="text-sm text-slate-500">Ainda não há coleta da tela da Shopee para este período. O funil depende dela: impressões, visitantes e carrinho só existem no Seller Center.</p>

                <h3 class="mb-2 mt-5 text-sm font-semibold">Visitantes e pedidos da loja por dia <span :class="selo">Tela · visão geral de vendas</span></h3>
                <div v-if="analise.funil.dias.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Visitantes e pedidos da loja por dia, segundo a Shopee</caption>
                        <thead><tr class="text-left text-xs uppercase text-slate-500"><th scope="col" class="px-2 py-1">Dia</th><th scope="col" class="px-2 py-1">Visitantes</th><th scope="col" class="px-2 py-1 text-right">Pedidos</th><th scope="col" class="px-2 py-1 text-right">Pagos</th><th scope="col" class="px-2 py-1 text-right">Vendas</th></tr></thead>
                        <tbody>
                            <tr v-for="d in analise.funil.dias" :key="d.data" class="border-t border-[var(--surface-border)]">
                                <th scope="row" class="whitespace-nowrap px-2 py-1 text-left font-medium">{{ data(d.data) }}</th>
                                <td class="w-1/2 px-2 py-1">
                                    <span class="flex items-center gap-2">
                                        <span class="h-2 rounded-full" :style="{ width: `${Math.max(2, ((d.visitantes ?? 0) / maxDia) * 100)}%`, background: '#EE4D2D' }" aria-hidden="true"></span>
                                        <span class="tabular-nums">{{ numero(d.visitantes) }}</span>
                                    </span>
                                </td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ numero(d.pedidos) }}</td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ numero(d.pedidos_pagos) }}</td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ dinheiro(d.vendas) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-slate-500">Linha do tempo do funil aparece quando a coleta diária da loja começar. Por enquanto, só o total do período.</p>
            </section>

            <!-- 6. Dinheiro -->
            <section id="dinheiro" :class="cartaoClasse" aria-labelledby="dinheiro-titulo">
                <h2 id="dinheiro-titulo" class="mb-3 text-lg font-semibold">Dinheiro: quanto entrou e quanto sobrou <span :class="[selo, 'ml-1 align-middle']">KazaKora</span></h2>
                <div class="grid gap-6 lg:grid-cols-2">
                    <div>
                        <dl class="space-y-2">
                            <div v-for="l in cascata" :key="l.rotulo" class="grid grid-cols-[1fr_auto_4rem] items-center gap-3 text-sm">
                                <dt>{{ l.sinal }} {{ l.rotulo }}</dt>
                                <dd class="text-right tabular-nums">{{ dinheiro(l.valor) }}</dd>
                                <dd class="text-right tabular-nums text-slate-500">{{ vazio(l.pct) ? SD : `${um(l.pct)}%` }}</dd>
                                <dd class="col-span-3 h-1 rounded-full bg-slate-100 dark:bg-slate-800" aria-hidden="true"><span class="block h-1 rounded-full bg-slate-400" :style="{ width: `${Math.min(100, Math.abs(l.pct ?? 0))}%` }"></span></dd>
                            </div>
                            <div class="grid grid-cols-[1fr_auto_4rem] gap-3 border-t border-[var(--surface-border)] pt-2 text-sm font-bold" :class="lucro >= 0 ? 'text-success' : 'text-error'">
                                <dt>= Lucro líquido<AjudaMetrica chave="lucro_liquido" /></dt>
                                <dd class="text-right tabular-nums">{{ dinheiro(conta.lucro) }}</dd>
                                <dd class="text-right tabular-nums">{{ vazio(margemPct) ? SD : `${um(margemPct)}%` }}</dd>
                            </div>
                        </dl>
                        <p class="mt-2 text-xs text-slate-500">Mesma conta do <Link href="/admin/fluxo-de-caixa" class="font-medium text-primary hover:underline">Fluxo de Caixa</Link> e do <Link href="/admin/dashboard-financeiro" class="font-medium text-primary hover:underline">Resultado e margem</Link>.</p>
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-semibold">Vendas e lucro por dia</h3>
                        <table class="w-full text-sm">
                            <caption class="sr-only">Vendas e lucro da Shopee por dia</caption>
                            <thead><tr class="text-left text-xs uppercase text-slate-500"><th scope="col" class="px-2 py-1">Dia</th><th scope="col" class="px-2 py-1">Vendas</th><th scope="col" class="px-2 py-1 text-right">Lucro</th></tr></thead>
                            <tbody>
                                <tr v-for="d in analise.serie" :key="d.data" class="border-t border-[var(--surface-border)]">
                                    <th scope="row" class="whitespace-nowrap px-2 py-1 text-left font-medium">{{ curta(d.data) }}</th>
                                    <td class="w-1/2 px-2 py-1">
                                        <span class="flex items-center gap-2">
                                            <span class="h-2 rounded-full bg-primary" :class="d.lucro < 0 ? 'ring-2 ring-error' : ''" :style="{ width: `${Math.max(1, (Math.abs(d.receita) / maxSerie) * 100)}%` }" aria-hidden="true"></span>
                                            <span class="tabular-nums">{{ dinheiro(d.receita) }}</span>
                                        </span>
                                    </td>
                                    <td class="px-2 py-1 text-right tabular-nums" :class="d.lucro < 0 ? 'text-error font-semibold' : ''">{{ dinheiro(d.lucro) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <CardStats compact note-wrap stat-subtitle="TICKET MÉDIO" :stat-title="dinheiro(ticket)" stat-icon-name="fas fa-receipt" variant="neutral" />
                    <CardStats compact note-wrap stat-subtitle="LUCRO POR PEDIDO" :stat-title="dinheiro(conta.pedidos ? lucro / conta.pedidos : null)" stat-icon-name="fas fa-coins" variant="neutral" />
                    <CardStats compact note-wrap stat-subtitle="TAXA REAL DA SHOPEE" :stat-title="vazio(taxaReal) ? SD : `${um(taxaReal)}%`" stat-note="taxas ÷ vendas" stat-icon-name="fas fa-percent" variant="neutral" />
                    <CardStats compact note-wrap stat-subtitle="PEDIDOS COM TAXA ESTIMADA" :stat-title="numero(analise.taxas?.pedidos_estimada ?? 0)"
                        :stat-note="analise.taxas?.pedidos_estimada ? `${analise.taxas.pedidos_estimada} pedidos ainda com taxa estimada: o lucro pode mudar quando a Shopee liquidar.` : 'todas as taxas já são reais'"
                        stat-icon-name="fas fa-hourglass-half" :variant="analise.taxas?.pedidos_estimada ? 'warning' : 'neutral'" />
                </div>
            </section>

            <!-- 7. ADS -->
            <section id="ads" :class="cartaoClasse" aria-labelledby="ads-titulo">
                <div class="flex flex-wrap items-start gap-3">
                    <div class="flex-1">
                        <h2 id="ads-titulo" class="text-lg font-semibold">ADS: quanto o anúncio pago traz de volta
                            <span :class="[selo, 'ml-1 align-middle']">{{ fonteAds?.tipo === 'coleta' ? `API pela coleta diária · ${curta(fonteAds.inicio)} a ${curta(fonteAds.fim)}` : 'API' }}</span>
                        </h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Resumo. Para pausar ou mudar meta, use a Estratégia Shopee.</p>
                    </div>
                    <Link href="/admin/publicidade/shopee" :class="botaoSec">Abrir Estratégia Shopee</Link>
                </div>
                <p v-if="fonteAds?.tipo === 'coleta' && avisoFonte(fonteAds)" class="mt-2 text-sm text-warning">{{ avisoFonte(fonteAds).replace('Funil da Shopee', 'ADS') }}</p>
                <p v-if="fonteAds?.tipo === 'coleta'" class="mt-2 text-xs text-slate-500">As campanhas sincronizadas de hora em hora não têm gasto neste período; os números vêm do ADS por anúncio da coleta diária.</p>
                <template v-if="k.gasto > 0">
                    <div class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                        <div v-for="c in [
                            ['Saldo de ADS', saldo === null ? 'Indisponível' : dinheiro(saldo), notaSaldo, 'saldo'],
                            ['Gasto', dinheiro(k.gasto), `${dinheiro(k.gasto_dia)} por dia`, null],
                            ['Venda pelo ADS', dinheiro(k.venda), k.venda_direta !== null ? `direta ${dinheiro(k.venda_direta)}` : '', null],
                            ['ROAS', um(k.roas), k.roas_meta_loja ? `meta da loja ${um(k.roas_meta_loja)}` : 'sem meta calculada', 'roas'],
                            ['ACOS', vazio(k.acos) ? SD : `${um(k.acos)}%`, 'gasto ÷ venda pelo ADS', 'acos'],
                            ['Custo por pedido do anúncio', dinheiro(k.custo_por_pedido), `gasto ADS ÷ ${numero(k.pedidos)} pedidos`, 'cpr'],
                            ['CTR do ADS', pct(k.ctr), `${numero(k.cliques)} cliques de ${numero(k.impressoes)}`, null],
                            ['CPC', dinheiro(k.cpc), 'gasto ÷ cliques', 'cpc'],
                            ['Conversão do ADS', pct(k.conversao), 'pedidos ÷ cliques', 'conversao_ads'],
                            ['Anúncios com ADS ativo', numero(k.ativos), '', null],
                        ]" :key="c[0]" class="rounded-lg border border-[var(--surface-border)] p-3">
                            <span class="text-xs font-semibold uppercase text-slate-500">{{ c[0] }}<AjudaMetrica v-if="c[3]" :chave="c[3]" /></span>
                            <span class="block text-xl font-bold tabular-nums">{{ c[1] }}</span>
                            <span v-if="c[2]" class="block text-xs text-slate-500">{{ c[2] }}</span>
                        </div>
                    </div>
                    <h3 class="mb-2 mt-5 text-sm font-semibold">ADS por anúncio (os {{ Math.min(20, analise.ads.total_anuncios) }} que mais gastaram de {{ analise.ads.total_anuncios }})</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <caption class="sr-only">ADS por anúncio no período</caption>
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                                    <th scope="col" class="sticky left-0 bg-[var(--surface)] px-2 py-2">Produto</th>
                                    <th scope="col" class="px-2 py-2">Situação</th>
                                    <th v-for="[campo, rotulo] in COLUNAS_ADS" :key="campo" scope="col" class="px-2 py-2 text-right" :aria-sort="ariaAds(campo)">
                                        <button type="button" class="font-semibold uppercase hover:text-primary focus-visible:outline-2 focus-visible:outline-primary" @click="ordenarAds(campo)">{{ rotulo }}</button>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in anunciosAds.map(comAds)" :key="p.item_id" class="border-t border-[var(--surface-border)]">
                                    <th scope="row" class="sticky left-0 max-w-xs bg-[var(--surface)] px-2 py-2 text-left font-semibold"><span class="line-clamp-2" :title="p.nome">{{ p.nome }}</span></th>
                                    <td class="px-2 py-2"><span :class="p.ads_ativo ? 'bg-lightsuccess text-success' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'" class="rounded-full px-2 py-0.5 text-xs font-bold">{{ p.ads_ativo ? 'Ativo' : 'Parado' }}</span></td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ dinheiro(p.gasto) }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ dinheiro(p.venda_ads) }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums" :class="p.roas_empate && p.roas !== null && p.roas < p.roas_empate ? 'font-semibold text-error' : ''">{{ um(p.roas) }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ um(p.roas_empate) }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ um(p.roas_meta) }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ pct(p.ctr_ads) }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ dinheiro(p.cpc) }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums" :class="p.custo_por_pedido && p.lucro_unidade !== null && p.custo_por_pedido > p.lucro_unidade ? 'text-error' : ''">{{ dinheiro(p.custo_por_pedido) }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ pct(p.conversao_ads) }}</td>
                                    <td class="px-2 py-2 text-right font-semibold tabular-nums" :class="p.resultado_ads === null ? 'text-slate-400' : p.resultado_ads < 0 ? 'text-error' : 'text-success'">{{ dinheiro(p.resultado_ads) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="analise.ads.por_dia.length" class="mt-4">
                        <h3 class="mb-2 text-sm font-semibold">Gasto e venda do ADS por dia</h3>
                        <table class="w-full text-sm">
                            <caption class="sr-only">Gasto e venda do ADS por dia</caption>
                            <thead><tr class="text-left text-xs uppercase text-slate-500"><th scope="col" class="px-2 py-1">Dia</th><th scope="col" class="px-2 py-1 text-right">Gasto</th><th scope="col" class="px-2 py-1 text-right">Venda</th><th scope="col" class="px-2 py-1 text-right">ROAS</th></tr></thead>
                            <tbody>
                                <tr v-for="d in analise.ads.por_dia" :key="d.data" class="border-t border-[var(--surface-border)]">
                                    <th scope="row" class="px-2 py-1 text-left font-medium">{{ curta(d.data) }}</th>
                                    <td class="px-2 py-1 text-right tabular-nums">{{ dinheiro(d.gasto) }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums">{{ dinheiro(d.venda) }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums">{{ d.gasto > 0 ? um(d.venda / d.gasto) : SD }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
                <p v-else class="mt-3 text-sm text-slate-500">Nenhuma campanha de ADS com gasto neste período.</p>
            </section>

            <!-- 8. Horários -->
            <section id="horarios" :class="cartaoClasse" aria-labelledby="horarios-titulo">
                <h2 id="horarios-titulo" class="text-lg font-semibold">Quando a loja vende</h2>
                <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">Pedidos por dia da semana e hora (horário de Brasília). <span :class="selo">KazaKora</span></p>
                <p v-if="analise.periodo.dias < 30" class="mb-2 text-sm text-warning">Com {{ analise.periodo.dias }} dias o mapa engana. Use 30 ou mais.</p>
                <template v-if="h.pedidos">
                    <p class="mb-1 text-sm font-semibold">
                        Pico: das {{ h.pico.de }}h às {{ h.pico.ate }}h ({{ um(h.pico.pct) }}% dos pedidos).<AjudaMetrica chave="pico" />
                    </p>
                    <p class="mb-3 text-sm">Na média, sai um pedido a cada {{ minutos(h.intervalo_medio_min) }} (mediana {{ minutos(h.intervalo_mediana_min) }}).</p>
                    <div class="overflow-x-auto">
                        <table class="text-xs">
                            <caption class="sr-only">Pedidos da Shopee por dia da semana e hora</caption>
                            <thead><tr><th scope="col" class="px-1"><span class="sr-only">Dia</span></th><th v-for="hr in 24" :key="hr" scope="col" class="w-7 px-0.5 text-center font-normal text-slate-500">{{ hr - 1 }}</th></tr></thead>
                            <tbody>
                                <tr v-for="(linha, dia) in h.mapa" :key="dia">
                                    <th scope="row" class="whitespace-nowrap pr-2 text-left font-medium">{{ DIAS_SEMANA[dia] }}</th>
                                    <td v-for="(n, hr) in linha" :key="hr" class="h-6 w-7 rounded text-center tabular-nums" :style="{ background: corMapa(n) }" :title="`${DIAS_SEMANA[dia]}, ${hr}h: ${n} pedidos`">
                                        <span :class="n ? '' : 'sr-only'">{{ n }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
                <p v-else class="text-sm text-slate-500">Nenhum pedido da Shopee neste período no KazaKora.</p>

                <h3 class="mb-2 mt-5 text-sm font-semibold">Visitas por hora <span :class="selo">Tela · {{ h.visitas_dias }} dias coletados</span></h3>
                <div v-if="h.visitas_por_hora" class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <caption class="sr-only">Visitantes e pedidos da loja por hora do dia, segundo a Shopee</caption>
                        <thead><tr class="text-left uppercase text-slate-500"><th scope="col" class="px-2 py-1">Hora</th><th scope="col" class="px-2 py-1">Visitantes</th><th scope="col" class="px-2 py-1 text-right">Pedidos (Shopee)</th></tr></thead>
                        <tbody>
                            <tr v-for="(v, hr) in h.visitas_por_hora" :key="hr" class="border-t border-[var(--surface-border)]">
                                <th scope="row" class="px-2 py-0.5 text-left font-medium">{{ hr }}h</th>
                                <td class="w-2/3 px-2 py-0.5">
                                    <span class="flex items-center gap-2">
                                        <span class="h-2 rounded-full" :style="{ width: `${Math.max(1, ((v ?? 0) / maxVisitaHora) * 100)}%`, background: '#EE4D2D' }" aria-hidden="true"></span>
                                        <span class="tabular-nums">{{ numero(v) }}</span>
                                    </span>
                                </td>
                                <td class="px-2 py-0.5 text-right tabular-nums">{{ numero(h.pedidos_por_hora_tela?.[hr]) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-slate-500">Visitas por hora aparecem quando a coleta por hora começar. Os pedidos por hora já são reais.</p>
            </section>

            <!-- 9. Produtos -->
            <section id="produtos" :class="cartaoClasse" aria-labelledby="produtos-titulo">
                <div class="flex flex-wrap items-start gap-3">
                    <div class="flex-1">
                        <h2 id="produtos-titulo" class="text-lg font-semibold">Todos os produtos na Shopee</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Uma linha por anúncio, com tudo: funil, dinheiro, ADS e estoque. Clique no cabeçalho para ordenar.</p>
                    </div>
                    <button type="button" :class="botaoSec" @click="baixarCsv"><i class="fas fa-download mr-1" aria-hidden="true"></i>Baixar planilha</button>
                </div>
                <div class="my-3 grid grid-cols-2 gap-3 md:grid-cols-5">
                    <label class="col-span-2 text-xs font-semibold text-slate-500">Buscar
                        <input v-model="busca" type="search" name="busca" autocomplete="off" placeholder="Nome, SKU ou item…" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm font-normal focus-visible:outline-2 focus-visible:outline-primary" @input="pagina = 1" />
                    </label>
                    <label class="text-xs font-semibold text-slate-500">Situação
                        <select v-model="situacao" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm font-normal focus-visible:outline-2 focus-visible:outline-primary" @change="pagina = 1">
                            <option value="">Todas</option><option value="grave">Com risco grave</option><option value="atencao">Com atenção</option>
                            <option value="melhorar">A melhorar</option><option value="oportunidade">Oportunidade</option><option value="sem">Sem alerta</option>
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-slate-500">ADS
                        <select v-model="filtroAds" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm font-normal focus-visible:outline-2 focus-visible:outline-primary" @change="pagina = 1">
                            <option value="">Com e sem ADS</option><option value="com">Com ADS</option><option value="sem">Sem ADS</option>
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-slate-500">Ordenar por
                        <select v-model="ordem" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm font-normal focus-visible:outline-2 focus-visible:outline-primary" @change="coluna = null">
                            <option v-for="(o, chave) in ORDENS" :key="chave" :value="chave">{{ o[0] }}</option>
                        </select>
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm"><input v-model="estoqueBaixo" type="checkbox" @change="pagina = 1" /> Estoque &lt; 15 dias</label>
                </div>
                <p class="mb-2 text-xs text-slate-500">{{ produtosFiltrados.length }} de {{ analise.produtos.length }} anúncios · funil {{ seloTela(fonteTela) }} · vendas e dinheiro KazaKora · ADS {{ fonteAds?.tipo === 'coleta' ? 'coleta diária' : 'API' }}</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Todos os anúncios da Shopee com funil, vendas, dinheiro, ADS e estoque</caption>
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                                <th scope="col" class="sticky left-0 z-10 min-w-[16rem] bg-[var(--surface)] px-2 py-2">Produto</th>
                                <th v-for="[campo, rotulo, ajuda] in COLUNAS" :key="campo" scope="col" class="whitespace-nowrap px-2 py-2 text-right" :aria-sort="ariaColuna(campo)">
                                    <button type="button" class="font-semibold uppercase hover:text-primary focus-visible:outline-2 focus-visible:outline-primary" @click="ordenarColuna(campo)">{{ rotulo }}</button><AjudaMetrica v-if="ajuda" :chave="ajuda" :loja="lojaDe(ajuda)" />
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="p in produtosPagina" :key="p.item_id ?? p.nome">
                                <tr class="border-t border-[var(--surface-border)] align-top">
                                    <th scope="row" class="sticky left-0 z-10 bg-[var(--surface)] px-2 py-2 text-left font-normal">
                                        <div class="flex items-start gap-2">
                                            <button type="button" class="mt-0.5 text-slate-400 hover:text-primary focus-visible:outline-2 focus-visible:outline-primary" :aria-expanded="aberto === p.item_id" :aria-label="`Detalhes de ${p.nome}`" @click="alternarLinha(p.item_id)">
                                                <i :class="aberto === p.item_id ? 'fas fa-chevron-down' : 'fas fa-chevron-right'" aria-hidden="true"></i>
                                            </button>
                                            <div class="min-w-0">
                                                <span class="line-clamp-2 font-semibold" :title="p.nome">{{ p.nome }}</span>
                                                <span class="block text-xs text-slate-500">{{ p.sku ?? 'sem SKU' }}<template v-if="p.item_id"> · {{ p.item_id }}</template><template v-if="p.estimado && p.lucro_unidade !== null"> · lucro estimado</template></span>
                                                <span v-if="situacaoProduto(p) !== 'sem'" class="mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-bold" :class="GRAV[situacaoProduto(p)].cor">{{ GRAV[situacaoProduto(p)].rotulo }}</span>
                                            </div>
                                        </div>
                                    </th>
                                    <td v-for="[campo] in COLUNAS" :key="campo" class="whitespace-nowrap px-2 py-2 text-right tabular-nums" :class="corCelula(p, campo)"
                                        :title="vazio(p[campo]) ? (['impressoes', 'ctr', 'visitantes', 'rejeicao', 'carrinho', 'abandono', 'conversao'].includes(campo) ? 'Sem dado da tela para este período' : 'Sem dado') : null">{{ celula(p, campo) }}</td>
                                </tr>
                                <tr v-if="aberto === p.item_id">
                                    <td :colspan="COLUNAS.length + 1" class="bg-slate-50 px-4 py-3 dark:bg-slate-900/40">
                                        <ul v-if="mapaRisco[p.item_id] || mapaOport[p.item_id]" class="space-y-1 text-sm">
                                            <li v-for="x in [...(mapaRisco[p.item_id]?.alertas ?? []), ...(mapaOport[p.item_id]?.alertas ?? [])]" :key="x.codigo">
                                                <span class="mr-2 rounded-full px-2 py-0.5 text-xs font-bold" :class="GRAV[x.gravidade].cor">{{ GRAV[x.gravidade].rotulo }}</span>{{ x.texto }} <span class="text-xs text-slate-500">({{ x.numero }})</span>
                                            </li>
                                        </ul>
                                        <p v-else class="text-sm text-slate-500">Sem alerta neste período.</p>
                                        <p class="mt-2 text-xs text-slate-500">
                                            Pedidos no KazaKora: {{ p.pedidos }} · na tela da Shopee: {{ numero(p.pedidos_tela) }} · custo {{ dinheiro(p.custo) }} · última venda {{ p.ultima_venda ? dataHora(p.ultima_venda) : SD }}
                                            <template v-if="p.ads_ativo || p.gasto"> · ADS: {{ numero(p.cliques_ads) }} cliques, {{ numero(p.pedidos_ads) }} pedidos, meta configurada {{ um(p.meta_configurada) }}</template>
                                        </p>
                                        <p class="mt-2 flex flex-wrap gap-2">
                                            <Link v-if="p.product_id" :href="`/admin/produtos/${p.product_id}/editar`" :class="botaoSec">Abrir produto</Link>
                                            <a v-if="p.link_shopee" :href="p.link_shopee" target="_blank" rel="noopener noreferrer" :class="botaoSec">Abrir no Seller Center<span class="sr-only"> (abre em outra aba)</span></a>
                                        </p>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!produtosPagina.length"><td :colspan="COLUNAS.length + 1" class="px-3 py-6 text-center text-slate-500">Nenhum anúncio com esses filtros.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="paginas > 1" class="mt-3 flex items-center gap-2 text-sm">
                    <button type="button" :class="botaoSec" :disabled="pagina === 1" @click="pagina--">Anterior</button>
                    <span>Página {{ pagina }} de {{ paginas }}</span>
                    <button type="button" :class="botaoSec" :disabled="pagina === paginas" @click="pagina++">Próxima</button>
                </div>
            </section>

            <!-- 10. Promoções -->
            <section id="promocoes" :class="cartaoClasse" aria-labelledby="promo-titulo">
                <h2 id="promo-titulo" class="text-lg font-semibold">Promoções: o que está no ar e se ainda dá lucro</h2>
                <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">Toda promoção tem que respeitar o piso de lucro. Aqui aparece quem está abaixo.</p>
                <h3 class="mb-2 text-sm font-semibold">Relâmpagos configuradas <span :class="selo">KazaKora</span></h3>
                <div v-if="analise.relampagos.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Relâmpagos configuradas com o lucro pela régua de 15% do custo</caption>
                        <thead><tr class="text-left text-xs uppercase text-slate-500">
                            <th scope="col" class="px-2 py-2">Produto</th><th scope="col" class="px-2 py-2">Dia</th><th scope="col" class="px-2 py-2 text-right">Preço da Relâmpago</th>
                            <th scope="col" class="px-2 py-2 text-right">Unidades</th><th scope="col" class="px-2 py-2 text-right">Lucro/un</th>
                            <th scope="col" class="px-2 py-2 text-right">Lucro % do custo<AjudaMetrica chave="lucro_pct_custo" /></th><th scope="col" class="px-2 py-2">Situação</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="r in analise.relampagos" :key="r.item_id + (r.dia ?? '')" class="border-t border-[var(--surface-border)]">
                                <th scope="row" class="max-w-xs px-2 py-2 text-left font-semibold"><span class="line-clamp-2">{{ r.nome }}</span></th>
                                <td class="px-2 py-2">{{ r.dia ? data(r.dia) : 'todo dia' }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ dinheiro(r.preco) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ numero(r.unidades) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ dinheiro(r.lucro_unidade) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ vazio(r.lucro_pct_custo) ? SD : `${um(r.lucro_pct_custo)}%` }}</td>
                                <td class="px-2 py-2">
                                    <span v-if="r.dentro_do_piso === true" class="rounded-full bg-lightsuccess px-2 py-0.5 text-xs font-bold text-success">Dentro do piso</span>
                                    <span v-else-if="r.dentro_do_piso === false" class="rounded-full bg-lighterror px-2 py-0.5 text-xs font-bold text-error">Abaixo do piso</span>
                                    <span v-else class="text-xs text-slate-500">sem custo</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-slate-500">Nenhuma Relâmpago configurada no KazaKora.</p>
                <p class="mt-2 text-xs text-slate-500">A Shopee não aceita Relâmpago com preço acima do menor preço do item nos últimos 7 dias. Baixar o preço hoje trava o teto da Relâmpago por uma semana.</p>
                <h3 class="mb-1 mt-4 text-sm font-semibold">Descontos e cupons da loja</h3>
                <p class="text-sm text-slate-500">O KazaKora ainda não lê descontos e cupons da loja pela API da Shopee. Eles aparecem aqui quando a leitura existir.</p>
                <p class="mt-2 text-xs text-slate-500">Cupom e desconto Pix pagos pela Shopee não diminuem o que a loja recebe. Só cupom da própria loja entra na conta de lucro.</p>
            </section>

            <!-- 11. Saúde da conta -->
            <section id="saude" :class="cartaoClasse" aria-labelledby="saude-titulo">
                <h2 id="saude-titulo" class="text-lg font-semibold">Saúde da conta: o que a Shopee avalia na loja<AjudaMetrica chave="saude" /></h2>
                <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">Fora da meta, a loja perde alcance na busca e pode receber ponto de penalidade.</p>
                <table v-if="analise.saude.length" class="w-full text-sm">
                    <caption class="sr-only">Indicadores da loja coletados do Seller Center</caption>
                    <thead><tr class="text-left text-xs uppercase text-slate-500"><th scope="col" class="px-2 py-1">Indicador</th><th scope="col" class="px-2 py-1 text-right">Valor</th><th scope="col" class="px-2 py-1">Coleta</th></tr></thead>
                    <tbody>
                        <tr v-for="s in analise.saude" :key="s.metrica" class="border-t border-[var(--surface-border)]">
                            <th scope="row" class="px-2 py-1 text-left font-medium">{{ s.rotulo ?? s.metrica }}</th>
                            <td class="px-2 py-1 text-right tabular-nums">{{ s.texto_original ?? s.valor }}</td>
                            <td class="px-2 py-1 text-xs text-slate-500">{{ dataHora(s.coletado_em) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="text-sm text-slate-500">A Saúde da Conta ainda não é coletada. Ela fica em Seller Center › Saúde da Conta e entra aqui quando a coleta incluir essa tela.</p>
            </section>

            <!-- 12. Avaliações e devoluções -->
            <section id="avaliacoes" :class="cartaoClasse" aria-labelledby="aval-titulo">
                <h2 id="aval-titulo" class="mb-3 text-lg font-semibold">Avaliações e devoluções <span :class="[selo, 'ml-1 align-middle']">KazaKora</span></h2>
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <CardStats compact stat-subtitle="NOTA MÉDIA NO PERÍODO" :stat-title="vazio(analise.avaliacoes.media) ? SD : dois(analise.avaliacoes.media)" stat-icon-name="fas fa-star" variant="neutral" />
                    <CardStats compact stat-subtitle="AVALIAÇÕES NOVAS" :stat-title="numero(analise.avaliacoes.total)" stat-icon-name="fas fa-comment" variant="neutral" />
                    <CardStats compact stat-subtitle="DE 1 E 2 ESTRELAS" :stat-title="numero(analise.avaliacoes.ruins)" stat-icon-name="fas fa-thumbs-down" :variant="analise.avaliacoes.ruins ? 'warning' : 'neutral'" />
                    <CardStats compact note-wrap stat-subtitle="DEVOLUÇÕES" :stat-title="numero(analise.devolucoes.total)" :stat-note="vazio(analise.devolucoes.pct) ? '' : `${um(analise.devolucoes.pct)}% dos pedidos`" stat-icon-name="fas fa-rotate-left" variant="neutral" />
                </div>
                <div v-if="analise.avaliacoes.ultimas_ruins.length || analise.devolucoes.por_motivo.length" class="mt-4 grid gap-6 lg:grid-cols-2">
                    <div v-if="analise.avaliacoes.ultimas_ruins.length">
                        <h3 class="mb-2 text-sm font-semibold">Avaliações ruins mais recentes</h3>
                        <ul class="space-y-2 text-sm">
                            <li v-for="r in analise.avaliacoes.ultimas_ruins" :key="r.id">
                                <span class="font-semibold text-error">{{ r.nota }} estrela{{ r.nota > 1 ? 's' : '' }}</span> · {{ r.produto ?? 'produto' }} · {{ data(r.data) }}
                                <span v-if="r.texto" class="block text-slate-600 dark:text-slate-300">“{{ r.texto }}”</span>
                            </li>
                        </ul>
                        <Link href="/admin/avaliacoes" :class="[botaoSec, 'mt-2 inline-block']">Ver em Avaliações</Link>
                    </div>
                    <div v-if="analise.devolucoes.por_motivo.length">
                        <h3 class="mb-2 text-sm font-semibold">Devoluções por motivo</h3>
                        <ul class="space-y-1 text-sm">
                            <li v-for="m in analise.devolucoes.por_motivo" :key="m.motivo" class="flex justify-between gap-3"><span>{{ m.motivo }}</span><span class="tabular-nums">{{ m.qtd }}</span></li>
                        </ul>
                    </div>
                </div>
                <p v-else class="mt-3 text-sm text-slate-500">Nenhuma avaliação ruim nem devolução neste período.</p>
            </section>

            <!-- 13. Glossário -->
            <section id="glossario" :class="cartaoClasse" aria-labelledby="glossario-titulo">
                <h2 id="glossario-titulo" class="text-lg font-semibold">Aprenda a ler os números da Shopee</h2>
                <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">As mesmas explicações do ícone (?) ao lado de cada número, juntas aqui.</p>
                <div v-for="g in glossarioPorGrupo" :key="g.grupo" class="mb-4">
                    <h3 class="mb-2 text-sm font-semibold">{{ g.grupo }}</h3>
                    <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                        <details v-for="[chave, v] in g.itens" :key="chave" class="rounded-lg border border-[var(--surface-border)] p-3 text-sm">
                            <summary class="cursor-pointer font-semibold focus-visible:outline-2 focus-visible:outline-primary">{{ v.nome }} <span class="text-xs font-normal text-slate-500">({{ v.fonte }})</span></summary>
                            <p class="mt-2"><b>O que é:</b> {{ lojaDe(chave) ? v.oque.replace('{loja}', lojaDe(chave)) : v.oque }}</p>
                            <p><b>Bom:</b> {{ lojaDe(chave) ? v.bom.replace('{loja}', lojaDe(chave)) : v.bom.replace(/ ?A loja está em \{loja\}%\./, '') }}</p>
                            <p><b>Ruim:</b> {{ v.ruim }}</p>
                            <p><b>O que fazer:</b> {{ v.fazer }}</p>
                        </details>
                    </div>
                </div>
            </section>

            <!-- 14. De onde vêm os números -->
            <details class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 text-sm shadow-sm">
                <summary class="cursor-pointer text-base font-semibold focus-visible:outline-2 focus-visible:outline-primary">De onde vêm os números</summary>
                <table class="mt-3 w-full text-sm">
                    <caption class="sr-only">Fontes dos números desta tela</caption>
                    <thead><tr class="text-left text-xs uppercase text-slate-500"><th scope="col" class="px-2 py-1">Fonte</th><th scope="col" class="px-2 py-1">O que traz</th><th scope="col" class="px-2 py-1">Última atualização</th></tr></thead>
                    <tbody>
                        <tr class="border-t border-[var(--surface-border)]"><th scope="row" class="px-2 py-1 text-left">KazaKora</th><td class="px-2 py-1">Pedidos, custo, taxas, imposto, lucro (ContributionMargin), estoque, Relâmpagos, avaliações, devoluções</td><td class="px-2 py-1">Em dia</td></tr>
                        <tr class="border-t border-[var(--surface-border)]"><th scope="row" class="px-2 py-1 text-left">API da Shopee</th><td class="px-2 py-1">ADS por campanha (de hora em hora) e diagnóstico do Cauê (status, fotos, vídeo, nota)</td><td class="px-2 py-1">ADS {{ dataHora(analise.fontes.ads) ?? 'sem sincronização' }} · diagnóstico {{ dataHora(analise.fontes.diagnostico) ?? 'sem diagnóstico' }}</td></tr>
                        <tr class="border-t border-[var(--surface-border)]"><th scope="row" class="px-2 py-1 text-left">ADS da coleta diária</th><td class="px-2 py-1">Gasto, venda, pedidos e cliques de ADS por anúncio (07h10)</td><td class="px-2 py-1">{{ dataHora(analise.fontes.ads_coleta) ?? 'Sem coleta' }}</td></tr>
                        <tr class="border-t border-[var(--surface-border)]"><th scope="row" class="px-2 py-1 text-left">Tela da Shopee</th><td class="px-2 py-1">Funil, rejeição, carrinho, visitas por hora. Coleta no Chrome da Cora Box; só leitura, nada é alterado na Shopee.</td><td class="px-2 py-1">{{ dataHora(analise.fontes.tela) ?? 'Sem coleta' }}</td></tr>
                    </tbody>
                </table>
                <p class="mt-3">Nenhum número desta tela é digitado à mão. Se algo parecer errado, a data da coleta ao lado de cada bloco diz de quando ele é.</p>
            </details>
        </div>
    </AdminLayout>
</template>
