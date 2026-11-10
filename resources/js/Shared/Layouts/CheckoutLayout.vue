<script setup>
// Layout do checkout no modelo Yampi (pedido 2026-10-23, referência
// seguro.mavoloja.com.br). Desde 2026-10-24 o topo segue o print da Mavo:
// uma faixa escura só, com a logo, o CNPJ e o endereço (sem nome nem razão
// social, ajuste de 2026-10-24). A barra roxa "Oferta termina em" (relógio de
// 30 minutos que recomeçava para cada visitante) saiu em 2026-11-10, pedido do
// Lira: não era uma oferta de verdade e passava cara de truque (urgência
// enganosa, art. 37 do CDC).
// Rodapé TOTALMENTE estático desde 2026-10-25 (pedido do Lira): só texto e
// imagens, sem link nem sanfona, igual em todas as telas. No celular as
// bandeiras e os selos ficam sempre centralizados. Sem menu, busca e rodapé
// da loja. Sempre claro (cores fixas). Desde 2026-11-03 (pedido do Lira) o selo
// RA é a imagem do rodapé do site, sem QR Code; o resto do rodapé não muda.
import { COMPANY } from '@/Shared/company.js';

defineProps({
    // Sem efeito desde 2026-11-10 (o relógio saiu); fica para não quebrar quem passa.
    semRelogio: { type: Boolean, default: false },
});

const BANDEIRAS = ['amex', 'visa', 'mastercard', 'elo', 'hiper', 'boleto', 'pix'];
const ano = new Date().getFullYear();

</script>

<template>
    <div class="flex min-h-screen flex-col bg-[#F4F5F7] font-store text-slate-800 [color-scheme:light]">
        <!-- Mercado Pago em modo teste (sandbox): nada é cobrado de verdade. -->
        <div v-if="$page.props.pagamentoTeste" class="bg-yellow-300 px-3 py-1.5 text-center text-xs font-bold uppercase tracking-wide text-yellow-950">
            <i class="fa-solid fa-flask mr-1"></i>Modo teste do Mercado Pago: nenhum valor é cobrado
        </div>

        <header class="bg-[#141414] px-4 py-3 text-center text-white">
            <img :src="$page.props.marca?.logoRodape || '/images/marca/logo-rodape.png'" alt="KazaKora" width="160" height="28" class="mx-auto h-7 w-auto md:h-8">
            <!-- Só logo, CNPJ e endereço (os mesmos dos Termos de Uso). -->
            <p class="mt-2 text-[11px] font-semibold leading-snug sm:text-[13px]">CNPJ: {{ COMPANY.cnpj }}</p>
            <p class="text-[11px] leading-snug text-white/75 sm:text-[13px]">{{ COMPANY.enderecoResumido }}</p>
        </header>
        <div class="checkout-oferta h-[3px]" aria-hidden="true"></div>

        <slot />

        <footer class="mt-auto px-4 pb-10 pt-2">
            <!-- Rodapé estático (2026-10-25): nada clicável, só texto e imagens. -->
            <div class="mx-auto max-w-[1200px] border-t border-slate-300/70 pt-8 text-center">
                <p class="text-sm font-medium text-slate-600">Formas de pagamento</p>
                <ul class="mx-auto mt-3 flex max-w-[420px] flex-wrap items-center justify-center gap-1.5" aria-label="Bandeiras aceitas">
                    <li v-for="bandeira in BANDEIRAS" :key="bandeira" class="w-[calc(25%-0.3rem)] sm:w-auto sm:min-w-0 sm:flex-1">
                        <img :src="`/images/payments/novas/${bandeira}.webp`" :alt="bandeira" width="249" height="167" loading="lazy" class="mx-auto h-auto w-full rounded-[6px] border border-slate-200 bg-white">
                    </li>
                </ul>

                <!-- Selos sem link (o RA também, com semLink). -->
                <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                    <div class="flex h-9 items-center justify-center rounded-[8px] border border-slate-200 bg-white px-2">
                        <img src="/images/selos/selo-google-safe.webp" alt="Google Safe Browsing" width="139" height="45" class="h-7 w-auto">
                    </div>
                    <div class="flex h-9 items-center justify-center rounded-[8px] border border-slate-200 bg-white px-2">
                        <img src="/images/selos/reclame-aqui-verificada.webp" alt="Verificada por Reclame AQUI" width="578" height="158" loading="lazy" class="h-7 w-auto">
                    </div>
                    <div class="flex h-9 items-center justify-center rounded-[8px] border border-slate-200 bg-white px-2">
                        <img src="/images/selos/selo-norton.svg" alt="Norton Safe Web" width="104" height="40" loading="lazy" class="h-6 w-auto">
                    </div>
                </div>

                <div class="mt-6 space-y-1 text-xs text-slate-600">
                    <p>{{ COMPANY.nomeFantasia }}: kazakora.com</p>
                    <p>{{ COMPANY.enderecoCompleto }}</p>
                    <p>© {{ ano }} {{ COMPANY.nomeFantasia }} | Grupo AlphaKora · CNPJ: {{ COMPANY.cnpj }}</p>
                    <p>WhatsApp: {{ COMPANY.whatsappDisplay }} / E-mail: {{ COMPANY.email }}</p>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
/* Linha colorida do topo (modelo Mavo, nas cores da KazaKora). */
.checkout-oferta {
    background: linear-gradient(90deg, #F44D00 0%, #E0247A 55%, #6D28D9 100%);
}
</style>
