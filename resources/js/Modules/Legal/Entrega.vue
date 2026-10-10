<script setup>
// Política de Entrega (pedido 2026-10-10): frete grátis, prazos e o que é o
// Full (entrega expressa) com as regiões atendidas — vindas da configuração,
// então a lista aqui é sempre a mesma que o site usa.
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import LegalCard from '@/Shared/Legal/LegalCard.vue';
import LegalCallout from '@/Shared/Legal/LegalCallout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    full: { type: Object, required: true },
});

const cepFormatado = (cep) => `${cep.slice(0, 5)}-${cep.slice(5)}`;
const regioes = computed(() => {
    const porLocal = {};
    props.full.faixas.forEach((faixa) => {
        (porLocal[faixa.local] ??= []).push(`${cepFormatado(faixa.inicio)} a ${cepFormatado(faixa.fim)}`);
    });
    return Object.entries(porLocal).map(([local, faixas]) => ({ local, faixas }));
});
</script>

<template>
    <Head title="Política de Entrega" />

    <AppLayout>
        <div class="mx-auto max-w-[840px] px-4 py-12 md:px-6">
            <p class="font-store-mono text-xs uppercase tracking-wider text-store-fg-faint">
                <Link href="/" class="hover:text-store-accent">Início</Link> / Política de Entrega
            </p>
            <h1 class="mt-2 font-display text-3xl font-semibold">Política de Entrega</h1>

            <div class="mt-8 flex flex-col gap-6">
                <LegalCard title="Frete grátis para todo o Brasil">
                    <p>
                        Todas as compras na KazaKora têm <strong class="text-store-fg">frete grátis</strong>. O valor do frete
                        nunca é cobrado no checkout, qualquer que seja o CEP.
                    </p>
                    <p>
                        A entrega é feita pelos <strong class="text-store-fg">Correios ou por transportadora</strong> em até
                        <strong class="text-store-fg">7 dias úteis</strong> após a aprovação do pagamento. O prazo exato para o
                        seu CEP aparece na página do produto (campo "Consultar prazo de entrega").
                    </p>
                </LegalCard>

                <LegalCard title="O que é o Full ⚡">
                    <p>
                        <span class="selo-full"><i class="fa-solid fa-bolt"></i>FULL</span> é a nossa
                        <strong class="text-store-fg">entrega expressa</strong>, feita a partir do nosso estoque em São Paulo
                        para CEPs da capital e de algumas cidades vizinhas.
                    </p>
                    <ul class="flex flex-col gap-2.5">
                        <li class="flex items-start gap-2.5">
                            <i class="fas fa-bolt mt-0.5 shrink-0 text-[#00a650]"></i>
                            <span>Pagamento aprovado <strong class="text-store-fg">até as {{ full.horario_corte }}</strong>:
                                você <strong class="text-store-fg">recebe no mesmo dia, até as {{ full.horario_entrega }}</strong>.</span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <i class="fas fa-bolt mt-0.5 shrink-0 text-[#00a650]"></i>
                            <span>Pagamento aprovado <strong class="text-store-fg">depois das {{ full.horario_corte }}</strong>:
                                você <strong class="text-store-fg">recebe no dia seguinte</strong>.</span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <i class="fas fa-bolt mt-0.5 shrink-0 text-[#00a650]"></i>
                            <span>Continua com <strong class="text-store-fg">frete grátis</strong>: o Full não tem custo extra.</span>
                        </li>
                    </ul>
                    <p>Quando o seu CEP é atendido, o "⚡FULL Receba hoje" ou "Receba amanhã" aparece na página do produto e no resumo do pedido.</p>
                </LegalCard>

                <LegalCard title="Onde o Full atende">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[420px] text-left text-sm">
                            <thead>
                                <tr class="border-b border-store-border text-xs uppercase tracking-wider text-store-fg-faint">
                                    <th class="pb-2 pr-4 font-medium">Cidade</th>
                                    <th class="pb-2 font-medium">Faixas de CEP atendidas</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-store-border">
                                <tr v-for="regiao in regioes" :key="regiao.local">
                                    <td class="py-3 pr-4 font-medium text-store-fg">{{ regiao.local }}</td>
                                    <td class="font-store-mono py-3 text-xs">
                                        <div v-for="faixa in regiao.faixas" :key="faixa">{{ faixa }}</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs">Em Barueri, o Full atende só a faixa de CEP indicada acima. Outras cidades da Grande São Paulo recebem pela entrega normal.</p>
                </LegalCard>

                <LegalCard title="Fora da área do Full">
                    <p>
                        Para os demais CEPs, a entrega é pelos Correios ou por transportadora em até 7 dias úteis.
                        Quando os Correios confirmam entrega em até 3 dias úteis para o seu CEP, a página do produto mostra o selo
                        <span class="selo-full"><i class="fa-solid fa-bolt"></i>FLEX</span>.
                    </p>
                </LegalCard>

                <LegalCard title="Acompanhar o pedido">
                    <p>
                        Assim que o pedido é despachado, o código de rastreio aparece em
                        <Link href="/rastreio" class="font-medium text-store-accent hover:underline">Rastrear pedido</Link>
                        e em "Meus pedidos". Se tiver qualquer dúvida sobre a entrega, fale com a gente.
                    </p>
                </LegalCard>

                <LegalCallout />
            </div>
        </div>
    </AppLayout>
</template>
