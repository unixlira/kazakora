<script setup>
// Card de confiança no lugar dos depoimentos (pedido do Lira 2026-11-24): três frases curtas,
// na ordem mais fácil de absorver: quem processa o pagamento, o que chega no e-mail e o aceite.
// O aceite dos termos (com cookies, que estão na política de privacidade) ficou curto aqui;
// o servidor continua gravando a prova ao finalizar.
import { Link } from '@inertiajs/vue3';

defineProps({
    mercadoPago: { type: Boolean, default: false },
    notaFiscal: { type: String, required: true },
});
</script>

<template>
    <section class="rounded-[24px] bg-white px-4 py-3.5 shadow-[0_4px_24px_rgba(15,23,42,0.07)]" aria-label="Compra segura" data-card-confianca>
        <ul class="space-y-2.5 text-[13px] leading-snug text-slate-700">
            <li v-if="mercadoPago" class="flex items-center gap-2.5">
                <img src="/images/pagamento/mercado-pago.svg" alt="" width="24" height="24" class="h-6 w-6 shrink-0" decoding="async">
                <span>Pagamento processado pelo <strong class="font-semibold text-[#0a0080]">Mercado Pago</strong></span>
            </li>
            <li class="flex items-center gap-2.5">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#00a650]/10 text-[12px] text-[#00a650]" aria-hidden="true"><i class="fa-solid fa-envelope"></i></span>
                <span>{{ notaFiscal }}</span>
            </li>
            <li class="flex items-center gap-2.5 text-[12px] text-slate-500">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[11px] text-slate-500" aria-hidden="true"><i class="fa-solid fa-file-lines"></i></span>
                <span>Ao comprar, você aceita os <Link href="/termos-de-uso" target="_blank" class="font-semibold underline-offset-2 hover:underline">termos de uso</Link> e a <Link href="/politica-de-privacidade" target="_blank" class="font-semibold underline-offset-2 hover:underline">política de privacidade</Link>.</span>
            </li>
        </ul>
    </section>
</template>
