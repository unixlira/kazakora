<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import LegalCard from '@/Shared/Legal/LegalCard.vue';
import { COMPANY } from '@/Shared/company';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Quem Somos" />

    <AppLayout>
        <div class="mx-auto max-w-[840px] px-4 py-12 md:px-6">
            <p class="font-store-mono text-xs uppercase tracking-wider text-store-fg-faint">
                <Link href="/" class="hover:text-store-accent">Início</Link> / Quem Somos
            </p>
            <h1 class="mt-2 font-display text-3xl font-semibold">Quem Somos</h1>
            <p class="mt-3 text-base leading-relaxed text-store-fg-muted">
                Uma loja que cresceu junto com quem compra aqui.
            </p>

            <div class="mt-8 space-y-4">
                <LegalCard title="Como surgimos">
                    <p>
                        A KazaKora surgiu de uma ideia simples: comprar pela internet pode ser fácil, seguro e justo.
                        José Roberto Lira, desenvolvedor de sistemas, trouxe para o varejo o mesmo jeito de trabalhar que
                        usa na tecnologia: entender o problema, testar e melhorar até funcionar. Foi assim que a loja
                        ganhou forma, dentro do Grupo AlphaKora.
                    </p>
                </LegalCard>

                <LegalCard title="Uma história de crescimento">
                    <p>
                        Começamos pequenos e crescemos pedido a pedido. Cada venda ensinou alguma coisa: o que o cliente
                        procura, o que precisa chegar mais rápido, o que dá para fazer melhor na próxima vez.
                    </p>
                    <p>
                        Quando o volume aumentou, a tecnologia virou nossa maior aliada. Criamos sistemas próprios de
                        separação e de entrega, e a operação ficou mais organizada a cada etapa. Hoje entregamos para
                        todo o Brasil, sem perder o cuidado com cada pedido.
                    </p>
                </LegalCard>

                <LegalCard title="Um sucesso que também é do cliente">
                    <p>
                        O nosso crescimento só faz sentido quando é bom para quem compra. A importação direta nos ajuda a
                        buscar um preço justo, a conferência ajuda o pedido a sair certo e a verificação no Reclame AQUI
                        mostra que a nossa loja está aberta a ser avaliada por quem compra.
                    </p>
                    <p>
                        Cada cliente que volta faz parte dessa história. É por isso que continuamos melhorando.
                    </p>
                </LegalCard>

                <LegalCard title="O Grupo AlphaKora">
                    <p>
                        A KazaKora faz parte do Grupo AlphaKora, de José Roberto Lira, que reúne desenvolvimento de sites
                        e sistemas, agência de marketing digital e varejo de produtos importados. É essa experiência que
                        está por trás de cada compra feita aqui.
                    </p>
                </LegalCard>

                <LegalCard title="O que você encontra aqui">
                    <p>
                        Produtos para a casa, a cozinha, a organização, a tecnologia, o bem-estar e as crianças. Tudo com
                        atendimento antes e depois da compra.
                    </p>
                </LegalCard>

                <LegalCard title="Dados da empresa e contato">
                    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-[150px_1fr]">
                        <dt class="font-semibold text-store-fg">Nome fantasia</dt>
                        <dd>{{ COMPANY.nomeFantasia }} | Grupo AlphaKora</dd>
                        <dt class="font-semibold text-store-fg">Razão social</dt>
                        <dd>{{ COMPANY.razaoSocial }}</dd>
                        <dt class="font-semibold text-store-fg">CNPJ</dt>
                        <dd>{{ COMPANY.cnpj }}</dd>
                        <dt class="font-semibold text-store-fg">Endereço</dt>
                        <dd>{{ COMPANY.enderecoCompleto }}</dd>
                        <dt class="font-semibold text-store-fg">WhatsApp</dt>
                        <dd><a :href="COMPANY.whatsappLink" target="_blank" rel="noopener" class="text-store-accent hover:underline">{{ COMPANY.whatsappDisplay }}</a></dd>
                        <dt class="font-semibold text-store-fg">E-mail</dt>
                        <dd><a :href="`mailto:${COMPANY.email}`" class="text-store-accent hover:underline">{{ COMPANY.email }}</a></dd>
                        <dt class="font-semibold text-store-fg">Atendimento</dt>
                        <dd>{{ COMPANY.horario }}</dd>
                    </dl>
                </LegalCard>
            </div>
        </div>
    </AppLayout>
</template>
