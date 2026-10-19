/**
 * Cores e formatos dos gráficos do admin (saíram do Dashboard.vue em
 * 2026-10-19, quando o Gerenciador virou abas de Indicadores e o Fluxo de
 * Caixa ganhou gráficos): uma paleta só para todas as telas.
 *
 * Cor fixa por canal (nunca pela posição no ranking), paleta categórica
 * validada para daltonismo nos dois temas (validate_palette.js, 2026-10-06).
 * No claro, verde-água, amarelo e magenta ficam abaixo de 3:1 no fundo: todo
 * gráfico de canal leva o valor escrito ao lado da cor.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

export const CHANNEL_LABELS = {
    loja: 'KazaKora',
    mercado_livre: 'Mercado Livre',
    shopee: 'Shopee',
    tiktok_shop: 'TikTok Shop',
    amazon: 'Amazon',
    ferrakora: 'FerraKora',
    beautykora: 'Beauty Kora',
};

export const CHANNEL_ORDER = ['tiktok_shop', 'shopee', 'mercado_livre', 'amazon', 'loja'];

export const CHANNEL_COLORS = {
    light: { tiktok_shop: '#2a78d6', shopee: '#eb6834', mercado_livre: '#1baf7a', amazon: '#eda100', loja: '#e87ba4', ferrakora: '#F27A2A', beautykora: '#d63384' },
    dark: { tiktok_shop: '#3987e5', shopee: '#d95926', mercado_livre: '#199e70', amazon: '#c98500', loja: '#d55181', ferrakora: '#e8691a', beautykora: '#e05a9d' },
};

// Tema grafite: neutros quentes; destaque grafite no claro e laranja no escuro.
export const CHROME = {
    light: { grid: '#dddcdb', text: '#5b6066', surface: '#ffffff', accent: '#3c4044', context: '#b3aaad' },
    dark: { grid: '#3c4044', text: '#a9adb2', surface: '#2a2d30', accent: '#fd7b41', context: '#7a6f73' },
};

/**
 * O tema muda na hora (botão do layout põe/tira .dark no <html>): os
 * gráficos repintam junto.
 */
export function useTemaEscuro() {
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
    const channelColor = (channel) => CHANNEL_COLORS[isDark.value ? 'dark' : 'light'][channel] ?? chrome.value.context;
    const tooltip = computed(() => ({
        backgroundColor: isDark.value ? '#f1eced' : '#3c4044',
        titleColor: isDark.value ? '#000000' : '#ffffff',
        bodyColor: isDark.value ? '#000000' : '#ffffff',
        footerColor: isDark.value ? '#000000' : '#ffffff',
        padding: 10,
        cornerRadius: 8,
        boxPadding: 4,
    }));
    const axisStyle = computed(() => ({
        grid: { color: chrome.value.grid, drawTicks: false },
        border: { display: false },
        ticks: { color: chrome.value.text, padding: 6 },
    }));

    return { isDark, chrome, channelColor, tooltip, axisStyle };
}

export const channelName = (channel) => CHANNEL_LABELS[channel] ?? channel;
export const sortChannels = (channels) => [...channels].sort((a, b) => (CHANNEL_ORDER.indexOf(a) + 99) % 99 - (CHANNEL_ORDER.indexOf(b) + 99) % 99);

export const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
export const formatCompact = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', notation: 'compact', maximumFractionDigits: 1 }).format(value ?? 0);
export const formatPercent = (value) => `${(value ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
export const formatInt = (value) => (value ?? 0).toLocaleString('pt-BR');

/** Data de hoje (AAAA-MM-DD) em São Paulo, sem passar por UTC. */
export const hojeSaoPaulo = () => new Intl.DateTimeFormat('sv-SE', { timeZone: 'America/Sao_Paulo' }).format(new Date());

/** AAAA-MM-DD de um Date, no fuso de São Paulo. */
export const dataSaoPaulo = (date) => new Intl.DateTimeFormat('sv-SE', { timeZone: 'America/Sao_Paulo' }).format(date);

/** Soma dias a uma data AAAA-MM-DD sem fuso (meio-dia evita virar o dia). */
export const somarDias = (iso, dias) => {
    const d = new Date(`${iso}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + dias);
    return d.toISOString().slice(0, 10);
};

/** "19/10" a partir de AAAA-MM-DD. */
export const diaMes = (iso) => (iso ? `${iso.slice(8, 10)}/${iso.slice(5, 7)}` : '');

/**
 * Plugin do Chart.js que escreve o valor de cada barra do 1º conjunto
 * (em cima na vertical, na ponta na horizontal), para o número não depender
 * da cor nem do tooltip.
 */
export const valorNasBarras = (formatar, corTexto, valores = null) => ({
    id: 'valorNasBarras',
    afterDatasetsDraw(chart) {
        const { ctx } = chart;
        const meta = chart.getDatasetMeta(0);
        const horizontal = chart.options.indexAxis === 'y';
        ctx.save();
        ctx.font = '600 11px DM Sans, system-ui, sans-serif';
        ctx.fillStyle = typeof corTexto === 'function' ? corTexto() : corTexto;
        meta.data.forEach((barra, i) => {
            const bruto = valores ? valores[i] : chart.data.datasets[0].data[i];
            if (bruto === null || bruto === undefined) return;
            const texto = formatar(bruto, i);
            if (horizontal) {
                const negativo = barra.x < barra.base;
                ctx.textAlign = negativo ? 'right' : 'left';
                ctx.textBaseline = 'middle';
                ctx.fillText(texto, barra.x + (negativo ? -6 : 6), barra.y);
            } else {
                ctx.textAlign = 'center';
                ctx.textBaseline = 'bottom';
                ctx.fillText(texto, barra.x, Math.min(barra.y, barra.base) - 4);
            }
        });
        ctx.restore();
    },
});
