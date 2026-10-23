<script setup>
// Layout do checkout no modelo Yampi (pedido 2026-10-23, referência
// seguro.mavoloja.com.br): só a logo no topo, faixa escura com os dados da
// empresa e uma linha fina laranja; rodapé curto com as formas de pagamento,
// os selos e 4 linhas com os dados da loja. Sem menu, busca e rodapé da loja.
// Sem contador regressivo: o da referência é falso (regra do Lira, nada de
// publicidade enganosa), e o "Frete grátis por tempo limitado" de 15 minutos
// que recomeçava ao atualizar saiu junto. Sempre claro (cores fixas).
import { COMPANY } from '@/Shared/company.js';
import AvisoCookies from '@/Shared/Components/AvisoCookies.vue';
import SeloReclameAqui from '@/Shared/Components/SeloReclameAqui.vue';

const BANDEIRAS = ['amex', 'visa', 'mastercard', 'elo', 'hiper', 'boleto', 'pix'];
const ano = new Date().getFullYear();
</script>

<template>
    <div class="flex min-h-screen flex-col bg-[#F4F5F7] font-store text-slate-800 [color-scheme:light]">
        <!-- Mercado Pago em modo teste (sandbox): nada é cobrado de verdade. -->
        <div v-if="$page.props.pagamentoTeste" class="bg-yellow-300 px-3 py-1.5 text-center text-xs font-bold uppercase tracking-wide text-yellow-950">
            <i class="fa-solid fa-flask mr-1"></i>Modo teste do Mercado Pago: nenhum valor é cobrado
        </div>

        <header class="bg-white">
            <div class="mx-auto flex h-16 max-w-[1200px] items-center justify-center px-4 md:h-20">
                <img :src="$page.props.marca?.logoNav || '/images/marca/logo-nav.webp'" alt="KazaKora" width="170" height="30" class="h-7 w-auto md:h-8">
            </div>
        </header>

        <!-- Faixa escura com razão social, CNPJ e endereço (os mesmos dos Termos de Uso). -->
        <div class="bg-gradient-to-r from-[#0b0f19] via-[#1b2130] to-[#0b0f19] px-3 py-2.5 text-center text-[11px] leading-relaxed text-white/80 sm:text-[13px]">
            <p>{{ COMPANY.nomeFantasia }} · <span class="whitespace-nowrap">{{ COMPANY.razaoSocial }}</span> · <span class="whitespace-nowrap">CNPJ: {{ COMPANY.cnpj }}</span></p>
            <p>{{ COMPANY.enderecoResumido }}</p>
            <p class="mt-0.5 font-semibold text-white"><i class="fa-solid fa-lock mr-1 text-[#2fd27a]"></i>Ambiente 100% seguro</p>
        </div>
        <div class="h-[3px] bg-gradient-to-r from-[#F44D00] via-[#ffb000] to-[#F44D00]" aria-hidden="true"></div>

        <slot />
        <AvisoCookies />

        <footer class="mt-auto px-4 pb-10 pt-2">
            <div class="mx-auto max-w-[1200px] border-t border-slate-300/70 pt-8 text-center">
                <p class="text-sm font-medium text-slate-600">Formas de pagamento</p>
                <ul class="mx-auto mt-3 flex max-w-[420px] flex-nowrap items-center justify-center gap-1.5" aria-label="Bandeiras aceitas">
                    <li v-for="bandeira in BANDEIRAS" :key="bandeira" class="min-w-0 flex-1">
                        <img :src="`/images/payments/novas/${bandeira}.webp`" :alt="bandeira" width="249" height="167" loading="lazy" class="h-auto w-full rounded-[6px] border border-slate-200 bg-white">
                    </li>
                </ul>

                <!-- Selos sem link no checkout (pedido 2026-10-21): o cliente não sai da tela de pagamento. -->
                <div class="mt-5 flex items-center justify-center gap-2">
                    <div class="flex h-9 items-center justify-center rounded-[8px] border border-slate-200 bg-white px-2">
                        <img src="/images/selos/selo-google-safe.webp" alt="Google Safe Browsing" width="139" height="45" class="h-7 w-auto">
                    </div>
                    <div class="flex h-9 items-center justify-center rounded-[8px] border border-slate-200 bg-white px-2">
                        <SeloReclameAqui :altura="28" sem-link />
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
