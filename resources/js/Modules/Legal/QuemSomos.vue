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
                Uma loja criada por quem constrói tecnologia, para deixar a sua casa mais prática e a sua compra mais simples.
            </p>

            <div class="mt-8 space-y-4">
                <LegalCard title="De onde viemos">
                    <p>
                        A KazaKora tem a cabeça de um desenvolvedor de sistemas. José Roberto Lira trabalha resolvendo
                        problemas com tecnologia e levou esse jeito de pensar para o varejo: criar a própria loja, dentro
                        do Grupo AlphaKora, e cuidar de perto de cada etapa, da escolha do produto à importação, da
                        separação ao despacho.
                    </p>
                </LegalCard>

                <LegalCard title="A nossa jornada">
                    <p>
                        A KazaKora foi conquistando espaço pedido a pedido. Hoje está na Shopee, no Mercado Livre, no
                        TikTok Shop, na Amazon e na Magalu, além da nossa casa principal, o site kazakora.com.
                    </p>
                    <p>
                        Conforme os pedidos cresceram, a tecnologia entrou na operação. Criamos nossos próprios sistemas
                        de separação e de entrega, para o seu pedido sair conferido e com a etiqueta certa.
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
                        Produtos para a casa, a cozinha, a organização, a tecnologia, o bem-estar e as crianças. Como
                        trabalhamos com importação direta, buscamos oferecer um preço justo, com atendimento de gente de
                        verdade antes e depois da compra.
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
