<script setup>
// Política de Entrega (pedido 2026-10-10): frete grátis, prazos, a Entrega
// Rápida no mesmo dia com as regiões atendidas e a Entrega Expressa paga —
// vindas da configuração, então a lista aqui é sempre a mesma que o site usa.
// Sem Full/Flex/Turbo na vista do cliente (pedido do Lira 2026-11-24).
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import LegalCard from '@/Shared/Legal/LegalCard.vue';
import LegalCallout from '@/Shared/Legal/LegalCallout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    rapida: { type: Object, required: true },
    motoboy: { type: Object, default: null },
});

const precoMotoboy = computed(() => (props.motoboy ? Number(props.motoboy.preco).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) : null));

const cepFormatado = (cep) => `${cep.slice(0, 5)}-${cep.slice(5)}`;
const regioes = computed(() => {
    const porLocal = {};
    props.rapida.faixas.forEach((faixa) => {
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
                        O frete é <strong class="text-store-fg">GRÁTIS para todo o Brasil</strong>, em todos os produtos
                        e para qualquer CEP: você não paga nada pela entrega.
                    </p>
                    <ul class="flex flex-col gap-2.5">
                        <li class="flex items-start gap-2.5">
                            <i class="fas fa-box mt-0.5 shrink-0 text-store-verde-texto"></i>
                            <span><strong class="text-store-fg">Envio em até 1 dia útil</strong> após a aprovação do pagamento.</span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <i class="fas fa-truck mt-0.5 shrink-0 text-store-verde-texto"></i>
                            <span><strong class="text-store-fg">Entrega em até 7 dias úteis</strong> depois do envio, pelos Correios ou por transportadora.</span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <i class="fas fa-bolt mt-0.5 shrink-0 text-store-verde-texto"></i>
                            <span>
                                Onde atende, a <strong class="text-store-fg">Entrega Rápida</strong> chega antes, também com frete grátis.
                            </span>
                        </li>
                    </ul>
                    <p>Quando chega para o seu CEP ("Chega até qui., 27/11", por exemplo) aparece na página do produto (campo "Calcular o prazo de entrega") e no checkout, em cada opção de entrega.</p>
                </LegalCard>

                <LegalCard title="Entrega Rápida ⚡">
                    <p>
                        A <strong class="text-store-fg">Entrega Rápida</strong> sai do nosso estoque em São Paulo
                        e, nos CEPs da capital e de algumas cidades vizinhas, chega no mesmo dia ou no dia seguinte.
                    </p>
                    <ul class="flex flex-col gap-2.5">
                        <li class="flex items-start gap-2.5">
                            <i class="fas fa-bolt mt-0.5 shrink-0 text-store-verde-texto"></i>
                            <span>Pagamento aprovado <strong class="text-store-fg">até as {{ rapida.horario_corte }}</strong>:
                                você <strong class="text-store-fg">recebe no mesmo dia, até as {{ rapida.horario_entrega }}</strong>.</span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <i class="fas fa-bolt mt-0.5 shrink-0 text-store-verde-texto"></i>
                            <span>Pagamento aprovado <strong class="text-store-fg">depois das {{ rapida.horario_corte }}</strong>:
                                você <strong class="text-store-fg">recebe no próximo dia de entrega</strong>: de segunda a sábado, sem domingo e feriado (sábado depois das {{ rapida.horario_corte }}, chega na segunda).</span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <i class="fas fa-bolt mt-0.5 shrink-0 text-store-verde-texto"></i>
                            <span>Continua com <strong class="text-store-fg">frete grátis</strong>: a Entrega Rápida não tem custo extra.</span>
                        </li>
                    </ul>
                    <p>Quando o seu CEP é atendido, o "Chega hoje até as {{ rapida.horario_entrega }}", "Chega amanhã" ou o dia da entrega aparece na página do produto e no checkout.</p>
                </LegalCard>

                <LegalCard title="Onde chega no mesmo dia">
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
                    <p class="text-xs">Em Barueri, a entrega no mesmo dia atende só a faixa de CEP indicada acima. Outras cidades da Grande São Paulo recebem pelos Correios ou por transportadora.</p>
                </LegalCard>

                <LegalCard title="Fora dessa área">
                    <p>
                        Para os demais CEPs, o pedido é enviado em até 1 dia útil após a aprovação do pagamento e entregue
                        pelos Correios ou por transportadora em até 7 dias úteis, com frete grátis.
                        Quando os Correios confirmam entrega em até 3 dias úteis para o seu CEP, a opção grátis aparece como
                        <strong class="text-store-fg">Entrega Rápida</strong>.
                    </p>
                </LegalCard>

                <LegalCard title="Entrega Expressa (paga)">
                    <p>Tem pressa? Na página do produto e no checkout aparece a <strong class="text-store-fg">Entrega Expressa</strong>, com o valor e quando chega:</p>
                    <ul class="flex flex-col gap-2.5">
                        <li v-if="motoboy" class="flex items-start gap-2.5">
                            <i class="fas fa-motorcycle mt-0.5 shrink-0 text-store-verde-texto"></i>
                            <span><strong class="text-store-fg">Grande São Paulo</strong> (CEP 01000-000 a 09999-999): motoboy, <strong class="text-store-fg">chega em até 4 horas</strong>. O motoboy custa {{ precoMotoboy }} e você paga só o que passar do frete que já está no preço do produto (se o frete do preço cobrir, sai grátis). Vale para pedidos de segunda a sexta, das {{ motoboy.semana?.[0] }} às {{ motoboy.semana?.[1] }}, e sábado, das {{ motoboy.sabado?.[0] }} às {{ motoboy.sabado?.[1] }}.</span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <i class="fas fa-truck-fast mt-0.5 shrink-0 text-store-verde-texto"></i>
                            <span><strong class="text-store-fg">Resto do Brasil</strong><template v-if="motoboy"> (e a Grande São Paulo fora desse horário)</template>: SEDEX, cobrando só a diferença entre o SEDEX e o frete grátis.</span>
                        </li>
                    </ul>
                </LegalCard>

                <LegalCard title="Acompanhar o pedido">
                    <p>
                        Assim que o pedido é despachado, o código de rastreio aparece em
                        <Link href="/rastreio" class="font-medium text-store-accent hover:underline">Rastrear pedido</Link>
                        e em "Meus Pedidos". Se tiver qualquer dúvida sobre a entrega, fale com a gente.
                    </p>
                </LegalCard>

                <LegalCallout />
            </div>
        </div>
    </AppLayout>
</template>
