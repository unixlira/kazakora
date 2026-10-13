<script setup>
// Sinais de concorrência de um produto do Mercado Livre (pedido do Lira
// 2026-10-13): selo de concorrência, aviso de guerra de preço, quanto dá
// para pagar no fornecedor e a margem quando a KazaKora já tem o custo.
// As regras ficam no servidor (MaisVendidosMercadoLivre::classificar).
defineProps({ produto: { type: Object, required: true } });

const SELO = {
    baixa: { texto: 'Concorrência baixa', classe: 'bg-lightsuccess text-success' },
    media: { texto: 'Concorrência média', classe: 'bg-lightwarning text-warning' },
    alta: { texto: 'Concorrência alta', classe: 'bg-lighterror text-error' },
};
const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
const formatPct = (value) => `${(value * 100).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`;
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5 text-xs">
        <span v-if="SELO[produto.concorrencia]" class="sinal" :class="SELO[produto.concorrencia].classe"
            :title="`${produto.vendedores} vendedor(es) neste produto de catálogo`">{{ SELO[produto.concorrencia].texto }}</span>
        <span v-if="produto.guerra_preco" class="sinal bg-lighterror text-error" title="O menor preço está abaixo de 80% da mediana: alguém já está derrubando o preço">
            <i class="fas fa-triangle-exclamation" aria-hidden="true"></i> guerra de preço
        </span>
        <span v-if="produto.custo_maximo !== null && produto.custo_maximo !== undefined" class="text-[var(--texto-suave)]"
            :title="`Vendendo pela mediana (${formatPrice(produto.preco_mediana)}) e pagando até este valor, sobram 15% de margem`">
            pagar até <b class="text-[var(--surface-foreground)]">{{ formatPrice(produto.custo_maximo) }}</b> no fornecedor
        </span>
        <span v-if="produto.margem_estimada !== null && produto.margem_estimada !== undefined"
            :class="produto.margem_estimada >= 0.15 ? 'text-success' : 'text-error'"
            :title="`Nosso custo ${formatPrice(produto.custo)}, vendendo pela mediana ${formatPrice(produto.preco_mediana)}`">
            · margem estimada <b>{{ formatPct(produto.margem_estimada) }}</b>
        </span>
    </div>
</template>

<style scoped>
.sinal {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    border-radius: 999px;
    padding: 0.125rem 0.5rem;
    font-size: 0.6875rem;
    font-weight: 700;
    white-space: nowrap;
}
</style>
