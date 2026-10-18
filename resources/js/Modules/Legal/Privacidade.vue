<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import LegalCard from '@/Shared/Legal/LegalCard.vue';
import LegalCallout from '@/Shared/Legal/LegalCallout.vue';
import { COMPANY } from '@/Shared/company';
import { Head, Link } from '@inertiajs/vue3';

const dadosCategorias = [
    { titulo: 'Dados de cadastro', texto: 'nome, sobrenome, número de telefone, cidade, estado, endereço de e-mail, CPF/CNPJ.' },
    { titulo: 'Dados de navegação', texto: 'dados de geolocalização ao acessar a Loja, preferências e interesses em relação aos produtos/serviços, dados de navegação gerais.' },
    { titulo: 'Dados de pagamento', texto: 'processados por intermediadores seguros, não armazenados diretamente pela KazaKora.' },
    { titulo: 'Cookies', texto: 'necessários para a loja funcionar (sessão, carrinho, segurança) e, com o seu OK, de estatística de visitas e do Pixel da Meta (publicidade). Com esse OK, a loja também informa à Meta, pelo nosso servidor, os produtos colocados no carrinho e as compras aprovadas, com e-mail, telefone, nome, cidade e CEP embaralhados (hash), para medir os anúncios. Veja a Política de Cookies.' },
    { titulo: 'Registros de acesso', texto: 'endereço IP, data e hora de acesso, guardados por 6 meses em sigilo, por obrigação do Marco Civil da Internet (Lei nº 12.965/14).' },
];

const finalidades = [
    'Processamento e fulfillment de pedidos',
    'Comunicação com o cliente sobre seu pedido',
    'Cumprimento de obrigações legais e regulatórias',
    'Melhoria da experiência de compra',
    'Marketing e promoções (mediante consentimento)',
    'Prevenção de fraudes',
];

const direitos = [
    'Confirmação da existência de tratamento de dados',
    'Acesso aos seus dados pessoais',
    'Correção de dados incompletos ou inexatos',
    'Anonimização, bloqueio ou eliminação de dados desnecessários',
    'Portabilidade dos dados',
    'Eliminação dos dados pessoais tratados com consentimento',
    'Revogação do consentimento',
];
</script>

<template>
    <Head title="Política de Privacidade" />

    <AppLayout>
        <div class="mx-auto max-w-[840px] px-4 py-12 md:px-6">
            <p class="font-store-mono text-xs uppercase tracking-wider text-store-fg-faint">
                <Link href="/" class="hover:text-store-accent">Início</Link> / Política de Privacidade
            </p>
            <h1 class="mt-2 font-display text-3xl font-semibold">Política de Privacidade</h1>
            <p class="mt-2 text-sm text-store-fg-muted">Baseada na Lei Geral de Proteção de Dados (Lei nº 13.709/18).</p>

            <div class="mt-8 flex flex-col gap-6">
                <LegalCard title="Introdução">
                    <p>
                        A {{ COMPANY.razaoSocial }}, pessoa jurídica de direito privado, com sede na {{ COMPANY.enderecoCompleto }},
                        inscrita no CNPJ/MF sob o nº {{ COMPANY.cnpj }} ("Lojista" ou "KazaKora") leva a sua privacidade a sério
                        e zela pela segurança e proteção de dados de todos os seus clientes, parceiros, fornecedores e usuários
                        ("Usuários" ou "você") do site {{ COMPANY.site }} e qualquer outro site, loja ou aplicativo operado pelo
                        Lojista (aqui designados, simplesmente, "Loja").
                    </p>
                    <p>
                        Esta Política de Privacidade destina-se a informá-lo sobre o modo como nós utilizamos e divulgamos
                        informações coletadas em suas visitas à nossa Loja e em mensagens que trocamos com você
                        ("Comunicações"). Ao acessar a Loja, enviar comunicações ou fornecer qualquer tipo de dado pessoal,
                        você declara estar ciente com relação aos termos aqui previstos e de acordo com a Política de
                        Privacidade, a qual descreve as finalidades e formas de tratamento de seus dados pessoais que você
                        disponibilizar na Loja.
                    </p>
                </LegalCard>

                <LegalCard title="Dados Coletados">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div v-for="categoria in dadosCategorias" :key="categoria.titulo">
                            <p class="text-sm font-medium text-store-fg">{{ categoria.titulo }}</p>
                            <p class="mt-1">{{ categoria.texto }}</p>
                        </div>
                    </div>
                </LegalCard>

                <LegalCard title="Finalidades do Tratamento">
                    <ul class="flex flex-col gap-2.5">
                        <li v-for="item in finalidades" :key="item" class="flex items-start gap-2.5">
                            <i class="fas fa-check mt-0.5 shrink-0 text-store-accent"></i>
                            <span>{{ item }}</span>
                        </li>
                    </ul>
                </LegalCard>

                <LegalCard title="Seus Direitos (LGPD)">
                    <ul class="flex flex-col gap-2.5">
                        <li v-for="item in direitos" :key="item" class="flex items-start gap-2.5">
                            <i class="fas fa-shield-halved mt-0.5 shrink-0 text-store-accent"></i>
                            <span>{{ item }}</span>
                        </li>
                    </ul>
                </LegalCard>

                <LegalCard title="Cookies">
                    <p>
                        Os cookies que usamos, para que servem e por quanto tempo ficam estão na
                        <Link href="/politica-de-cookies" class="font-medium text-store-accent hover:underline">Política de Cookies</Link>.
                    </p>
                </LegalCard>

                <!-- Exclusão de dados (pedido 2026-10-10): URL pedida pela Meta (WhatsApp)
                     — https://kazakora.com/exclusao-de-dados aponta para cá. -->
                <LegalCard id="exclusao-de-dados" title="Como pedir a exclusão dos seus dados" class="scroll-mt-28">
                    <p>
                        Você pode pedir, a qualquer momento e sem custo, a exclusão dos seus dados pessoais — inclusive das
                        conversas com a KazaKora pelo WhatsApp (LGPD, art. 18, VI).
                    </p>
                    <ol class="flex list-decimal flex-col gap-2 pl-5">
                        <li>
                            Envie o pedido pelo <Link href="/fale-conosco" class="font-medium text-store-accent hover:underline">Fale conosco</Link>
                            com o assunto <strong>"Exclusão de dados"</strong>, ou pelo e-mail {{ COMPANY.email }}, ou pelo WhatsApp {{ COMPANY.whatsappDisplay }}.
                        </li>
                        <li>Informe o nome e o e-mail ou telefone usados na loja ou no WhatsApp, para localizarmos os seus dados.</li>
                        <li>Para sua segurança, podemos pedir uma confirmação de que o pedido é seu antes de excluir.</li>
                        <li>Respondemos em até <strong>15 dias</strong> (LGPD, art. 19), confirmando o que foi excluído.</li>
                    </ol>
                    <p>
                        Excluímos cadastro, endereços, favoritos, carrinho, preferências e conversas. Alguns dados precisam ser
                        guardados por obrigação legal mesmo após o pedido, e ficam bloqueados para qualquer outro uso:
                        notas fiscais e dados de pedidos pelo prazo da legislação fiscal (5 anos) e registros de acesso por
                        6 meses (Marco Civil da Internet, art. 15).
                    </p>
                </LegalCard>

                <LegalCard title="Compartilhamento de Dados">
                    <p>
                        A KazaKora pode compartilhar dados com: provedores de pagamento, transportadoras, provedores de
                        serviços de tecnologia e autoridades públicas, quando exigido por lei.
                    </p>
                    <p class="flex items-start gap-2.5 font-medium text-store-fg">
                        <i class="fas fa-xmark mt-0.5 shrink-0 text-red-500"></i>
                        A KazaKora não vende dados pessoais a terceiros.
                    </p>
                </LegalCard>

                <LegalCard title="Segurança dos Dados">
                    <p>
                        Adotamos medidas técnicas e organizacionais para proteger dados pessoais, incluindo criptografia
                        SSL, controle de acesso, monitoramento e backups regulares.
                    </p>
                    <p class="flex items-start gap-2.5 font-medium text-store-fg">
                        <i class="fas fa-xmark mt-0.5 shrink-0 text-red-500"></i>
                        A plataforma não se destina a pessoas com menos de 18 anos.
                    </p>
                </LegalCard>

                <LegalCard title="Contato e Responsável">
                    <p>
                        Caso pretenda exercer qualquer um dos direitos previstos nesta Política de Privacidade e/ou nas
                        Leis de Proteção de Dados, ou resolver quaisquer dúvidas relacionadas ao Tratamento de seus Dados
                        Pessoais, favor contatar-nos através do e-mail {{ COMPANY.email }}.
                    </p>
                    <dl class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <div><dt class="text-xs text-store-fg-faint">Responsável pelo tratamento</dt><dd class="text-store-fg">{{ COMPANY.responsavelNome }} — CPF {{ COMPANY.responsavelCpf }}</dd></div>
                        <div><dt class="text-xs text-store-fg-faint">Razão Social</dt><dd class="text-store-fg">{{ COMPANY.razaoSocial }}</dd></div>
                        <div><dt class="text-xs text-store-fg-faint">CNPJ</dt><dd class="font-store-mono text-store-fg">{{ COMPANY.cnpj }}</dd></div>
                        <div><dt class="text-xs text-store-fg-faint">Endereço</dt><dd class="text-store-fg">{{ COMPANY.enderecoCompleto }}</dd></div>
                        <div><dt class="text-xs text-store-fg-faint">E-mail</dt><dd class="text-store-fg">{{ COMPANY.email }}</dd></div>
                        <div><dt class="text-xs text-store-fg-faint">WhatsApp</dt><dd class="text-store-fg">{{ COMPANY.whatsappDisplay }}</dd></div>
                        <div><dt class="text-xs text-store-fg-faint">Horário de atendimento</dt><dd class="text-store-fg">{{ COMPANY.horario }}</dd></div>
                        <div><dt class="text-xs text-store-fg-faint">Última atualização</dt><dd class="text-store-fg">10/10/2026</dd></div>
                    </dl>
                    <p class="mt-2">
                        Nossa equipe está sempre disponível durante o horário comercial, inclusive aos sábados, para
                        atender suas solicitações de privacidade e proteção de dados.
                    </p>
                </LegalCard>

                <LegalCallout />
            </div>
        </div>
    </AppLayout>
</template>
