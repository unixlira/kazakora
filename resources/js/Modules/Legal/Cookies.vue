<script setup>
// Política de Cookies (pedido 2026-10-10) — LGPD (Lei 13.709/2018), Guia
// Orientativo de Cookies da ANPD (2022) e Marco Civil da Internet (Lei
// 12.965/2014). Detalhe técnico: docs/privacidade-e-cookies.md.
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import LegalCard from '@/Shared/Legal/LegalCard.vue';
import LegalCallout from '@/Shared/Legal/LegalCallout.vue';
import { COMPANY } from '@/Shared/company';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    sessaoMinutos: { type: Number, default: 120 },
    nomeSessao: { type: String, default: 'kazakora-session' },
    versao: { type: String, default: '' },
});

const cookies = [
    {
        nome: props.nomeSessao,
        tipo: 'Necessário',
        finalidade: 'Mantém você conectado, guarda o carrinho e os dados do checkout enquanto navega.',
        duracao: `${props.sessaoMinutos} minutos sem uso`,
        base: 'Execução de contrato e legítimo interesse (art. 7º, V e IX)',
    },
    {
        nome: 'XSRF-TOKEN',
        tipo: 'Necessário',
        finalidade: 'Segurança: impede que outro site envie formulários em seu nome.',
        duracao: `${props.sessaoMinutos} minutos sem uso`,
        base: 'Legítimo interesse — segurança (art. 7º, IX e art. 46)',
    },
    {
        nome: 'kazakora_cookies',
        tipo: 'Necessário',
        finalidade: 'Lembra que você já deu OK neste aviso, para não perguntar de novo.',
        duracao: '12 meses',
        base: 'Cumprimento de obrigação legal — prova do consentimento (art. 8º, § 2º)',
    },
    {
        nome: 'kazakora_visitor',
        tipo: 'Estatística',
        finalidade: 'Código aleatório que conta visitas e páginas vistas para melhorarmos a loja. Não identifica você pelo nome e só é criado depois do seu OK.',
        duracao: '12 meses',
        base: 'Consentimento (art. 7º, I)',
    },
];
</script>

<template>
    <Head title="Política de Cookies" />

    <AppLayout>
        <div class="mx-auto max-w-[840px] px-4 py-12 md:px-6">
            <p class="font-store-mono text-xs uppercase tracking-wider text-store-fg-faint">
                <Link href="/" class="hover:text-store-accent">Início</Link> / Política de Cookies
            </p>
            <h1 class="mt-2 font-display text-3xl font-semibold">Política de Cookies</h1>
            <p class="mt-2 text-sm text-store-fg-muted">
                Conforme a Lei Geral de Proteção de Dados (Lei nº 13.709/18), o Guia de Cookies da ANPD e o Marco Civil da Internet (Lei nº 12.965/14).
            </p>

            <div class="mt-8 flex flex-col gap-6">
                <LegalCard title="O que são cookies">
                    <p>
                        Cookies são pequenos arquivos que o site guarda no seu navegador. Eles servem para a loja funcionar
                        (manter o carrinho e o login, por exemplo) e para entendermos como ela é usada.
                    </p>
                    <p class="flex items-start gap-2.5 font-medium text-store-fg">
                        <i class="fas fa-check mt-0.5 shrink-0 text-green-600"></i>
                        A KazaKora não usa cookies de publicidade nem de redes sociais, e não vende dados.
                    </p>
                </LegalCard>

                <LegalCard title="Cookies que usamos">
                    <div class="flex flex-col gap-3">
                        <div v-for="cookie in cookies" :key="cookie.nome" class="rounded-xl border border-store-border p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <code class="font-store-mono text-sm font-semibold text-store-fg">{{ cookie.nome }}</code>
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="cookie.tipo === 'Necessário' ? 'bg-green-100 text-green-800' : 'bg-orange-100 text-orange-800'">{{ cookie.tipo }}</span>
                            </div>
                            <p class="mt-2">{{ cookie.finalidade }}</p>
                            <dl class="mt-2 grid grid-cols-1 gap-1 text-xs sm:grid-cols-2">
                                <div><dt class="inline text-store-fg-faint">Duração: </dt><dd class="inline text-store-fg">{{ cookie.duracao }}</dd></div>
                                <div><dt class="inline text-store-fg-faint">Base legal (LGPD): </dt><dd class="inline text-store-fg">{{ cookie.base }}</dd></div>
                            </dl>
                        </div>
                    </div>
                    <p class="mt-2 text-xs">
                        Também guardamos no próprio navegador (armazenamento local, sem enviar para nós) pequenas preferências,
                        como a faixa de ofertas fechada.
                    </p>
                </LegalCard>

                <LegalCard title="O aviso e o seu OK">
                    <p>
                        Os cookies necessários funcionam desde o primeiro acesso, porque sem eles a loja não abre o carrinho nem o
                        pagamento. O cookie de estatística só é criado depois que você clica em <strong>OK</strong> no aviso. Guardamos
                        a data, a hora e a versão do aviso em que você deu o OK, como a LGPD exige.
                    </p>
                    <p>
                        Para desfazer, apague os cookies do site nas configurações do seu navegador. O aviso volta a aparecer e
                        nada de estatística é registrado até um novo OK. Você também pode pedir pelo e-mail {{ COMPANY.email }}
                        ou pelo <Link href="/fale-conosco" class="font-medium text-store-accent hover:underline">Fale conosco</Link>.
                    </p>
                </LegalCard>

                <LegalCard title="Registros de acesso (Marco Civil da Internet)">
                    <p>
                        Por obrigação legal (art. 15 da Lei nº 12.965/14), guardamos o endereço IP, a data e a hora de cada acesso
                        à loja por <strong>6 meses</strong>, em sigilo, e só entregamos por ordem judicial. Depois desse prazo, o IP
                        e os dados do navegador são apagados automaticamente; ficam só números gerais, sem identificar ninguém.
                    </p>
                </LegalCard>

                <LegalCard title="O que registramos das visitas">
                    <ul class="flex flex-col gap-2.5">
                        <li class="flex items-start gap-2.5"><i class="fas fa-check mt-0.5 shrink-0 text-store-accent"></i><span>Página visitada, data e hora</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-check mt-0.5 shrink-0 text-store-accent"></i><span>Site de onde você veio e campanha (UTM), sem o restante do endereço</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-check mt-0.5 shrink-0 text-store-accent"></i><span>Tipo de aparelho (celular, tablet ou computador)</span></li>
                        <li class="flex items-start gap-2.5"><i class="fas fa-check mt-0.5 shrink-0 text-store-accent"></i><span>Navegador completo e código de visitante: só com o seu OK</span></li>
                    </ul>
                    <p class="mt-2">
                        Usamos esses dados para saber quais produtos interessam mais, de onde vêm as visitas e melhorar a loja.
                        Mais detalhes na <Link href="/politica-de-privacidade" class="font-medium text-store-accent hover:underline">Política de Privacidade</Link>.
                    </p>
                </LegalCard>

                <LegalCard title="Contato">
                    <p>
                        Responsável pelo tratamento: {{ COMPANY.responsavelNome }} — {{ COMPANY.razaoSocial }}, CNPJ {{ COMPANY.cnpj }}.
                        E-mail: {{ COMPANY.email }}. Versão deste aviso: {{ versao.split('-').reverse().join('/') }}.
                    </p>
                </LegalCard>

                <LegalCallout />
            </div>
        </div>
    </AppLayout>
</template>
