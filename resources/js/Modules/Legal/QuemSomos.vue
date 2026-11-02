<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { COMPANY } from '@/Shared/company';
import { Head, Link } from '@inertiajs/vue3';

const compromissos = [
    { titulo: 'Preço', texto: 'Compramos por importação direta, com menos intermediários, para buscar um preço justo no que você leva para casa.' },
    { titulo: 'Pedido', texto: 'Cada pedido passa por separação e conferência no nosso próprio sistema antes de sair daqui.' },
    { titulo: 'Confiança', texto: 'Somos verificados no Reclame AQUI e deixamos nossos dados e nossas regras de troca à vista.' },
];

const dados = [
    { rotulo: 'Razão social', valor: COMPANY.razaoSocial },
    { rotulo: 'CNPJ', valor: COMPANY.cnpj },
    { rotulo: 'Endereço', valor: COMPANY.enderecoCompleto },
    { rotulo: 'Atendimento', valor: COMPANY.horario },
];
</script>

<template>
    <Head title="Quem Somos" />

    <AppLayout>
        <div class="mx-auto max-w-[760px] px-4 py-12 md:px-6 md:py-16">
            <p class="font-store-mono text-xs uppercase tracking-wider text-store-fg-faint">
                <Link href="/" class="hover:text-store-accent">Início</Link> / Quem Somos
            </p>

            <h1 class="mt-5 font-display text-4xl font-semibold leading-tight md:text-5xl">
                A KazaKora começou depois do expediente.
            </h1>
            <p class="mt-5 text-lg leading-relaxed text-store-fg-muted">
                De dia, sistemas. À noite, uma loja. Esta é a nossa história, e ela também é de quem compra aqui.
            </p>

            <section class="mt-12 space-y-5 text-base leading-relaxed text-store-fg-muted">
                <p>
                    José Roberto Lira é desenvolvedor de sistemas. Passa o dia resolvendo problemas com código e, quando o
                    expediente acaba, continua trabalhando no que é dele: o Grupo AlphaKora.
                </p>
                <p>
                    Foi nessas noites que a KazaKora tomou forma, a partir de uma pergunta direta: dá para ter uma loja com
                    preço justo, em que o pedido sai certo e o cliente não precisa correr atrás de ninguém?
                </p>
                <p>
                    A resposta começou pequena, na Mooca, em São Paulo: importação direta e muita atenção
                    a cada venda.
                </p>
            </section>

            <section class="mt-12">
                <h2 class="font-display text-2xl font-semibold">Os pedidos cresceram. Os problemas também.</h2>
                <div class="mt-4 space-y-5 text-base leading-relaxed text-store-fg-muted">
                    <p>
                        Mais vendas trouxeram etiqueta para imprimir, pedido para separar e entrega para acompanhar. Em vez
                        de aceitar o erro como parte do negócio, fizemos o que um desenvolvedor faz: criamos nossos próprios
                        sistemas de separação e de entrega.
                    </p>
                    <p>
                        Hoje entregamos para todo o Brasil. Do power bank ao cesto de bambu, da ring light à bike de spinning,
                        da cozinha de brinquedo ao organizador, tudo passa pela mesma operação.
                    </p>
                </div>
            </section>

            <section class="mt-12">
                <h2 class="font-display text-2xl font-semibold">Só é sucesso se for bom para os dois lados.</h2>
                <p class="mt-4 text-base leading-relaxed text-store-fg-muted">
                    Para nós, um bom resultado não é só vender mais. É você receber o que comprou e ter vontade de voltar.
                    É assim que tentamos fazer isso acontecer:
                </p>
                <ul class="mt-6 space-y-5 border-l-2 border-store-accent pl-5">
                    <li v-for="item in compromissos" :key="item.titulo">
                        <h3 class="font-semibold">{{ item.titulo }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-store-fg-muted">{{ item.texto }}</p>
                    </li>
                </ul>
                <p class="mt-6 text-sm text-store-fg-muted">
                    Veja como funcionam as
                    <Link href="/trocas-e-devolucoes" class="font-medium text-store-accent hover:underline">trocas e devoluções</Link>.
                </p>
            </section>

            <section class="mt-12">
                <h2 class="font-display text-2xl font-semibold">Parte do Grupo AlphaKora</h2>
                <p class="mt-4 text-base leading-relaxed text-store-fg-muted">
                    A KazaKora faz parte do Grupo AlphaKora, de José Roberto Lira, que também atua com desenvolvimento de
                    sites e sistemas e com marketing digital. É a mesma cabeça de tecnologia, agora cuidando da sua compra.
                </p>
            </section>

            <section class="mt-12 rounded-2xl border border-store-border bg-store-bg-raised p-6 lg:p-7">
                <h2 class="font-display text-2xl font-semibold">Fale com a gente</h2>
                <div class="mt-4 flex flex-col gap-2 text-base sm:flex-row sm:gap-6">
                    <a :href="COMPANY.whatsappLink" target="_blank" rel="noopener" class="font-medium text-store-accent hover:underline">WhatsApp {{ COMPANY.whatsappDisplay }}</a>
                    <a :href="`mailto:${COMPANY.email}`" class="font-medium text-store-accent hover:underline">{{ COMPANY.email }}</a>
                </div>
                <dl class="mt-6 space-y-3 border-t border-store-border pt-5 text-sm">
                    <div v-for="item in dados" :key="item.rotulo" class="sm:grid sm:grid-cols-[120px_1fr] sm:gap-4">
                        <dt class="font-semibold">{{ item.rotulo }}</dt>
                        <dd class="text-store-fg-muted">{{ item.valor }}</dd>
                    </div>
                </dl>
            </section>
        </div>
    </AppLayout>
</template>
