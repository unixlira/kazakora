<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import LegalCard from '@/Shared/Legal/LegalCard.vue';
import LegalCallout from '@/Shared/Legal/LegalCallout.vue';
import { COMPANY } from '@/Shared/company';
import { Head, Link } from '@inertiajs/vue3';

const prazos = [
    { caso: 'Produto com defeito', prazo: 'Até 30 dias após o recebimento', base: 'Art. 26, CDC' },
    { caso: 'Desistência de compra (arrependimento)', prazo: 'Até 7 dias após o recebimento', base: 'Art. 49, CDC' },
    { caso: 'Produto em perfeitas condições (troca por vontade própria)', prazo: 'Até 7 dias após o recebimento', base: '—' },
    { caso: 'Produto com erro no pedido (item errado enviado)', prazo: 'Comunicar imediatamente — 7 dias', base: '—' },
];

const condicoes = [
    'O produto deve estar em sua embalagem original',
    'Deve conter todos os acessórios e manuais',
    'Não pode apresentar sinais de uso excessivo ou dano causado pelo consumidor',
    'Deve estar acompanhado da nota fiscal original',
    'Produtos de higiene pessoal, íntimos e personalizados não podem ser devolvidos por questões de saúde e segurança, exceto em caso de defeito de fabricação',
];

const passos = [
    'Entre em contato pelo WhatsApp (11) 96572-3990 ou e-mail contato@kazakora.com informando o número do pedido, o motivo da solicitação e fotos do produto, se aplicável.',
    'Nossa equipe de atendimento irá analisar a solicitação em até 2 dias úteis e responder com as instruções de envio.',
    `O produto deve ser enviado para o endereço: ${COMPANY.enderecoCompleto}.`,
    'Após recebermos o produto, faremos uma análise em até 5 dias úteis.',
    'Se aprovado, a troca será processada ou o reembolso emitido em até 7 dias úteis.',
];
</script>

<template>
    <Head title="Trocas e Devoluções" />

    <AppLayout>
        <div class="mx-auto max-w-[840px] px-4 py-12 md:px-6">
            <p class="font-store-mono text-xs uppercase tracking-wider text-store-fg-faint">
                <Link href="/" class="hover:text-store-accent">Início</Link> / Trocas e Devoluções
            </p>
            <h1 class="mt-2 font-display text-3xl font-semibold">Política de Trocas e Devoluções</h1>

            <div class="mt-8 flex flex-col gap-6">
                <LegalCard title="Visão Geral">
                    <p>
                        A KazaKora compromete-se a oferecer a melhor experiência de compra. Seguimos o Código de
                        Defesa do Consumidor (Lei nº 8.078/90) e oferecemos prazos claros para trocas e devoluções.
                        Esta política descreve as condições, prazos e procedimentos para solicitação de troca ou
                        devolução de produtos adquiridos em nossa loja online ({{ COMPANY.site }}).
                    </p>
                </LegalCard>

                <LegalCard title="Prazos para Devolução">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[480px] text-left text-sm">
                            <thead>
                                <tr class="border-b border-store-border text-xs uppercase tracking-wider text-store-fg-faint">
                                    <th class="pb-2 pr-4 font-medium">Situação</th>
                                    <th class="pb-2 pr-4 font-medium">Prazo</th>
                                    <th class="pb-2 font-medium">Base legal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-store-border">
                                <tr v-for="row in prazos" :key="row.caso">
                                    <td class="py-3 pr-4 text-store-fg">{{ row.caso }}</td>
                                    <td class="py-3 pr-4">{{ row.prazo }}</td>
                                    <td class="font-store-mono py-3 text-xs">{{ row.base }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </LegalCard>

                <LegalCard title="Condições para Troca ou Devolução">
                    <ul class="flex flex-col gap-2.5">
                        <li v-for="item in condicoes" :key="item" class="flex items-start gap-2.5">
                            <i class="fas fa-check mt-0.5 shrink-0 text-store-accent"></i>
                            <span>{{ item }}</span>
                        </li>
                    </ul>
                </LegalCard>

                <LegalCard title="Como Solicitar">
                    <ol class="flex flex-col gap-4">
                        <li v-for="(passo, index) in passos" :key="passo" class="flex gap-3">
                            <span class="font-store-mono flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-store-accent-soft text-xs font-semibold text-store-accent-strong">
                                {{ index + 1 }}
                            </span>
                            <span>{{ passo }}</span>
                        </li>
                    </ol>
                </LegalCard>

                <LegalCard title="Reembolso">
                    <p>Reembolsos são feitos pelo mesmo método de pagamento utilizado na compra:</p>
                    <ul class="flex flex-col gap-2.5">
                        <li class="flex items-start gap-2.5">
                            <i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i>
                            <span><strong class="text-store-fg">Cartão de crédito:</strong> estorno em até 2 faturas</span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i>
                            <span><strong class="text-store-fg">PIX:</strong> devolução em até 1 dia útil</span>
                        </li>
                    </ul>
                </LegalCard>

                <LegalCard title="Contato e Atendimento">
                    <p>
                        Nossa equipe de atendimento está disponível para auxiliar em qualquer questão relacionada a
                        trocas e devoluções. Atendemos de {{ COMPANY.horario }}. Sempre há alguém pronto para te
                        atender durante nosso horário comercial, inclusive aos sábados.
                    </p>
                    <dl class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <div><dt class="text-xs text-store-fg-faint">WhatsApp</dt><dd class="text-store-fg">{{ COMPANY.whatsappDisplay }}</dd></div>
                        <div><dt class="text-xs text-store-fg-faint">E-mail</dt><dd class="text-store-fg">{{ COMPANY.email }}</dd></div>
                        <div><dt class="text-xs text-store-fg-faint">Responsável</dt><dd class="text-store-fg">{{ COMPANY.responsavelNome }}</dd></div>
                        <div><dt class="text-xs text-store-fg-faint">CNPJ</dt><dd class="font-store-mono text-store-fg">{{ COMPANY.cnpj }}</dd></div>
                    </dl>
                </LegalCard>

                <LegalCallout />
            </div>
        </div>
    </AppLayout>
</template>
