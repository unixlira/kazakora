<script setup>
// Layout do checkout v2 (pedido 2026-10-10): sem topo, menu e rodapé da
// loja — só a faixa de segurança e o conteúdo, pra o cliente fechar a compra
// sem distração. Sempre claro (as cores do checkout são fixas).
</script>

<template>
    <div class="min-h-screen bg-[#FAFAFA] font-store text-slate-800 [color-scheme:light]">
        <div class="bg-[#0FB930] px-4 py-2 text-center text-sm font-semibold tracking-wide text-white">
            <i class="fa-solid fa-lock mr-1.5"></i> COMPRA 100% SEGURA
            <span class="mx-2 opacity-60">·</span>
            <i class="fa-solid fa-fingerprint mr-1.5"></i> DADOS PROTEGIDOS
            <span class="mx-2 hidden opacity-60 sm:inline">·</span>
            <span class="hidden sm:inline"><i class="fa-solid fa-box-open mr-1.5"></i> ENTREGA GARANTIDA</span>
        </div>
        <slot />
        <p class="px-4 pb-8 pt-2 text-center text-xs text-slate-400">
            <i class="fa-solid fa-shield-halved mr-1"></i> Ambiente seguro · Pagamento processado pelo Mercado Pago
        </p>
    </div>
</template>
