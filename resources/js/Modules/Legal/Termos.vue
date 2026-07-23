<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import LegalCard from '@/Shared/Legal/LegalCard.vue';
import LegalCallout from '@/Shared/Legal/LegalCallout.vue';
import { COMPANY } from '@/Shared/company';
import { Head, Link } from '@inertiajs/vue3';

const definicoes = [
    { termo: '"Site"', texto: `a loja online ${COMPANY.site}` },
    { termo: '"Usuário"', texto: 'qualquer pessoa que acesse o site' },
    { termo: '"Cliente"', texto: 'usuário que realiza compras no site' },
    { termo: '"Produtos"', texto: 'itens oferecidos para venda no site' },
    { termo: '"Lojista" ou "KazaKora"', texto: `a empresa ${COMPANY.razaoSocial}` },
];

const usoProibido = [
    'Utilizar o site para fins ilegais',
    'Tentar acessar áreas restritas sem autorização',
    'Inserir informações falsas',
    'Usar bots ou scripts automatizados sem autorização',
    'Reproduzir ou copiar conteúdo sem permissão',
];
</script>

<template>
    <Head title="Termos de Uso" />

    <AppLayout>
        <div class="mx-auto max-w-[840px] px-4 py-12 md:px-6">
            <p class="font-store-mono text-xs uppercase tracking-wider text-store-fg-faint">
                <Link href="/" class="hover:text-store-accent">Início</Link> / Termos de Uso
            </p>
            <h1 class="mt-2 font-display text-3xl font-semibold">Termos de Uso</h1>

            <div class="mt-8 flex flex-col gap-6">
                <LegalCard title="Aceitação dos Termos">
                    <p>
                        Estes Termos de Uso ("Termos") regem o acesso e uso do site {{ COMPANY.site }} e de todos os
                        serviços oferecidos pela {{ COMPANY.razaoSocial }}, inscrita no CNPJ/MF sob o nº {{ COMPANY.cnpj }},
                        com sede na {{ COMPANY.enderecoCompleto }} ("KazaKora" ou "Lojista"). Ao acessar ou utilizar
                        nosso site, você concorda integralmente com estes Termos. Se você não concorda com qualquer
                        parte destes Termos, não utilize nosso site ou serviços.
                    </p>
                </LegalCard>

                <LegalCard title="Definições">
                    <dl class="flex flex-col gap-2.5">
                        <div v-for="item in definicoes" :key="item.termo" class="flex flex-wrap gap-1.5">
                            <dt class="font-medium text-store-fg">{{ item.termo }}:</dt>
                            <dd>{{ item.texto }}</dd>
                        </div>
                    </dl>
                </LegalCard>

                <LegalCard title="Uso do Site">
                    <p>O usuário concorda em usar o site apenas para fins legítimos. É proibido:</p>
                    <ul class="flex flex-col gap-2.5">
                        <li v-for="item in usoProibido" :key="item" class="flex items-start gap-2.5">
                            <i class="fas fa-xmark mt-0.5 shrink-0 text-red-500"></i>
                            <span>{{ item }}</span>
                        </li>
                    </ul>
                    <p>A KazaKora pode suspender ou bloquear o acesso de usuários que violem estes termos.</p>
                </LegalCard>

                <LegalCard title="Cadastro e Conta">
                    <ul class="flex flex-col gap-2.5">
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>Para realizar compras, o usuário deve fornecer informações verdadeiras, completas e atualizadas</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>O usuário é responsável por manter a confidencialidade de sua senha e conta</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>Toda atividade realizada com a conta do usuário é de sua responsabilidade</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>O usuário deve ter 18 anos ou mais para realizar compras</span></li>
                    </ul>
                </LegalCard>

                <LegalCard title="Produtos e Preços">
                    <ul class="flex flex-col gap-2.5">
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>Os produtos oferecidos estão sujeitos a disponibilidade de estoque</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>Os preços podem ser alterados a qualquer momento, com efeito apenas para novas compras</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>As imagens dos produtos são meramente ilustrativas</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>A KazaKora reserva-se o direito de limitar a quantidade de produtos por pedido</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>Em caso de erro de preço ou indisponibilidade, a KazaKora pode cancelar o pedido e reembolsar o valor pago</span></li>
                    </ul>
                </LegalCard>

                <LegalCard title="Pagamentos">
                    <ul class="flex flex-col gap-2.5">
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>Aceitamos cartões de crédito (Visa, Mastercard), PIX e boleto bancário</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>O processamento de pagamentos é feito por intermediadores seguros</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>Pedidos não pagos dentro do prazo serão cancelados automaticamente</span></li>
                    </ul>
                </LegalCard>

                <LegalCard title="Entrega">
                    <ul class="flex flex-col gap-2.5">
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>Os prazos de entrega informados são estimativas</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>A KazaKora não se responsabiliza por atrasos causados por transportadoras ou eventos de força maior</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>O risco de perda ou dano do produto passa ao cliente no momento da entrega</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>Em caso de não recebimento, o cliente deve notificar em até 72 horas</span></li>
                    </ul>
                </LegalCard>

                <LegalCard title="Propriedade Intelectual">
                    <ul class="flex flex-col gap-2.5">
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>Todo o conteúdo do site (textos, imagens, logos, layout, design) é propriedade da KazaKora ou de terceiros licenciadores</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>É proibida a reprodução, distribuição ou modificação sem autorização expressa</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>A marca "KazaKora" e seus elementos visuais são protegidos por direitos autorais</span></li>
                    </ul>
                </LegalCard>

                <LegalCard title="Limitação de Responsabilidade">
                    <ul class="flex flex-col gap-2.5">
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>A KazaKora não se responsabiliza por danos indiretos, lucros cessantes ou perda de dados</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>A responsabilidade da KazaKora limita-se ao valor do produto adquirido</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>A KazaKora não se responsabiliza por interrupções temporárias do site por manutenção</span></li>
                    </ul>
                </LegalCard>

                <LegalCard title="Foro e Legislação Aplicável">
                    <ul class="flex flex-col gap-2.5">
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>Estes Termos são regidos pela legislação brasileira</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-circle mt-1.5 shrink-0 text-[6px] text-store-fg-faint"></i><span>Fica eleito o foro da Comarca de São Paulo/SP para dirimir qualquer controvérsia</span></li>
                    </ul>
                </LegalCard>

                <LegalCard title="Contato">
                    <p>
                        Para dúvidas, sugestões ou reclamações sobre estes Termos de Uso, entre em contato com nossa
                        equipe. Atendemos de {{ COMPANY.horario }}. Sempre há alguém pronto para te atender durante
                        nosso horário comercial, inclusive aos sábados.
                    </p>
                    <dl class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <div><dt class="text-xs text-store-fg-faint">WhatsApp</dt><dd class="text-store-fg">{{ COMPANY.whatsappDisplay }}</dd></div>
                        <div><dt class="text-xs text-store-fg-faint">E-mail</dt><dd class="text-store-fg">{{ COMPANY.email }}</dd></div>
                        <div><dt class="text-xs text-store-fg-faint">Responsável</dt><dd class="text-store-fg">{{ COMPANY.responsavelNome }}</dd></div>
                        <div><dt class="text-xs text-store-fg-faint">Razão Social</dt><dd class="text-store-fg">{{ COMPANY.razaoSocial }}</dd></div>
                        <div><dt class="text-xs text-store-fg-faint">CNPJ</dt><dd class="font-store-mono text-store-fg">{{ COMPANY.cnpj }}</dd></div>
                        <div><dt class="text-xs text-store-fg-faint">Endereço</dt><dd class="text-store-fg">{{ COMPANY.enderecoCompleto }}</dd></div>
                    </dl>
                </LegalCard>

                <LegalCallout />
            </div>
        </div>
    </AppLayout>
</template>
